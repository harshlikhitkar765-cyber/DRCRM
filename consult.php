<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

require_login();

$pdo = db();

/* Resolve patient, either from an appointment or directly */
$apptId = (int)($_GET['appt'] ?? $_POST['appt_id'] ?? 0);
$pid    = (int)($_GET['patient'] ?? $_POST['patient_id'] ?? 0);
if ($apptId) {
    $a = $pdo->prepare('SELECT * FROM appointments WHERE id=?'); $a->execute([$apptId]);
    $appt = $a->fetch();
    if (!$appt) { http_response_code(404); exit('Appointment not found'); }
    $pid = (int)$appt['patient_id'];
}
$p = $pdo->prepare('SELECT * FROM patients WHERE id=?'); $p->execute([$pid]); $pt = $p->fetch();
if (!$pt) { http_response_code(404); exit('Patient not found'); }

/* ---------------- Save ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $meds = [];
    foreach (($_POST['med_name'] ?? []) as $i => $nm) {
        if (trim((string)$nm) === '') continue;
        $meds[] = [
            'name'     => trim((string)$nm),
            'dose'     => trim((string)($_POST['med_dose'][$i]     ?? '')),
            'unit'     => trim((string)($_POST['med_unit'][$i]     ?? '')),
            'when'     => trim((string)($_POST['med_when'][$i]     ?? '')),
            'freq'     => trim((string)($_POST['med_freq'][$i]     ?? '')),
            'duration' => trim((string)($_POST['med_dur'][$i]      ?? '')),
            'notes'    => trim((string)($_POST['med_notes'][$i]    ?? '')),
        ];
    }
    $vitals = [
        'temp'=>trim((string)($_POST['v_temp']??'')),   'bp'=>trim((string)($_POST['v_bp']??'')),
        'pulse'=>trim((string)($_POST['v_pulse']??'')), 'spo2'=>trim((string)($_POST['v_spo2']??'')),
        'weight'=>trim((string)($_POST['v_weight']??'')),'sugar'=>trim((string)($_POST['v_sugar']??'')),
    ];
    $labs = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['labs'] ?? '')))));

    $st = $pdo->prepare('INSERT INTO prescriptions(patient_id,rx_date,diagnosis,vitals,meds,labs,advice,follow_up)
                         VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([
        $pid, date('Y-m-d'),
        pf('diagnosis'),
        json_encode($vitals, JSON_UNESCAPED_UNICODE),
        json_encode($meds,   JSON_UNESCAPED_UNICODE),
        json_encode($labs,   JSON_UNESCAPED_UNICODE),
        pf('advice'),
        pf('follow_up'),
    ]);
    $rxId = (int)$pdo->lastInsertId();

    /* Store each vital as its own row as well, so temperature, BP and the
       rest can be compared and charted rather than only printed. */
    if (function_exists('vitals_save')) {
        vitals_save($pid, $rxId, $vitals, date('Y-m-d'));
    }

    /* An allergy the patient stated aloud belongs on their record, not just
       in this visit's note — the safety checks read it from there on every
       future prescription. Merge rather than overwrite, and never lose an
       allergy already on file. */
    $heard = pf('scribe_allergy');
    if ($heard !== '') {
        $have = array_filter(array_map('trim', explode(',', (string)$pt['allergies'])));
        $add  = array_filter(array_map('trim', explode(',', $heard)));
        $new  = $have;
        foreach ($add as $a) {
            $dup = false;
            foreach ($have as $h) if (strcasecmp($h, $a) === 0) { $dup = true; break; }
            if (!$dup) $new[] = $a;
        }
        if (count($new) > count($have)) {
            $merged = implode(', ', $new);
            $pdo->prepare('UPDATE patients SET allergies=? WHERE id=?')->execute([$merged, $pid]);
            audit('allergy_added', 'patient', $pid, $heard.' (heard in consultation)');
            $_SESSION['ok'] = 'Allergy added to the record: '.$heard;
        }
    }

    /* Keep the drug defaults in step with what this doctor really writes. */
    if (function_exists('learn_apply_defaults')) learn_apply_defaults();

    /* Learn the diagnosis so the list improves by itself. */
    if (function_exists('diagnosis_learn')) {
        diagnosis_learn(pf('diagnosis'));
    }

    /* --------------------------------------------------------------
       Build the main-points record for EVERY prescription, including
       one the doctor simply typed with no recording. Without this a
       typed visit has no summary, so nothing appears in the patient's
       history or in the "Discussed in the consultation" box on the
       printout. The doctor's own typing is the source here.
       -------------------------------------------------------------- */
    $vitalsOut = [];
    foreach ($vitals as $k => $vv) if (trim((string)$vv) !== '') $vitalsOut[$k] = $vv;

    $medsOut = [];
    foreach ($meds as $m) {
        $medsOut[] = [
            'name' => $m['name'] ?? '', 'dose' => $m['dose'] ?? '',
            'unit' => $m['unit'] ?? '', 'when' => $m['when'] ?? '',
            'freq' => $m['freq'] ?? '', 'dur'  => $m['duration'] ?? '',
        ];
    }
    $adviceOut = array_values(array_filter(array_map('trim',
        preg_split('/\r\n|\r|\n/', (string)($_POST['advice'] ?? '')) ?: [])));

    $typedDx = pf('diagnosis');
    $fu      = pf('follow_up');
    $fuDays  = 0;
    if ($fu !== '') {
        $d1 = new DateTime(date('Y-m-d'));
        $d2 = DateTime::createFromFormat('Y-m-d', $fu);
        if ($d2) $fuDays = (int)$d1->diff($d2)->format('%r%a');
    }

    $writtenSummary = [
        'disease'     => $typedDx,
        'when'        => date('H:i'),
        'took'        => '',
        'secs'        => 0,
        'lang'        => 'typed',
        'source'      => 'written',      /* as opposed to a recorded visit */
        'complaints'  => [],
        'duration'    => '',
        'vitals'      => $vitalsOut,
        'diagnosis'   => $typedDx,
        'meds'        => $medsOut,
        'labs'        => $labs,
        'advice'      => $adviceOut,
        'followUp'    => $fu,
        'followDays'  => $fuDays,
        'patientSaid' => [],
        'doctorSaid'  => [],
        'turns'       => 0,
        'words'       => 0,
    ];

    /* Keep the recorded conversation with the visit. It is the doctor's note,
       and the evidence behind what was prescribed. */
    $tr     = pf('scribe_transcript');
    $noteId = pint('scribe_note_id');

    if ($noteId > 0) {
        /* The conversation was already saved live while they were talking.
           Attach that row to this prescription rather than duplicating it. */
        $own = $pdo->prepare('SELECT id FROM consult_notes WHERE id=? AND patient_id=?');
        $own->execute([$noteId, $pid]);
        if ($own->fetchColumn()) {
            $pdo->prepare('UPDATE consult_notes
                           SET rx_id=?, transcript=?, turns=?, disease=?, dr_points=?,
                               pt_points=?, lang=?, picked=?, summary=?, secs=?,
                               ended_at=COALESCE(ended_at, NOW())
                           WHERE id=?')
                ->execute([$rxId, $tr,
                           (string)($_POST['scribe_turns']   ?? ''),
                           mb_substr(pf('scribe_disease'), 0, 200),
                           (string)($_POST['scribe_dr']      ?? ''),
                           (string)($_POST['scribe_pt']      ?? ''),
                           (string)($_POST['scribe_lang']    ?? 'en-IN'),
                           (string)($_POST['scribe_picked']  ?? ''),
                           ((string)($_POST['scribe_summary'] ?? '') !== ''
                              ? (string)$_POST['scribe_summary']
                              : json_encode($writtenSummary, JSON_UNESCAPED_UNICODE)),
                           max(0, pint('scribe_secs')),
                           $noteId]);
            audit('consult_recorded','prescription',$rxId,'live note #'.$noteId);
            $tr = '';   /* already stored — skip the insert below */
        }
    }

    if ($tr !== '') {
        $pdo->prepare('INSERT INTO consult_notes
                       (patient_id,appt_id,rx_id,transcript,turns,disease,dr_points,pt_points,
                        lang,picked,started_at,summary,secs,ended_at)
                       VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([$pid, $apptId ?: null, $rxId, $tr,
                       (string)($_POST['scribe_turns']   ?? ''),
                       mb_substr(pf('scribe_disease'), 0, 200),
                       (string)($_POST['scribe_dr']      ?? ''),
                       (string)($_POST['scribe_pt']      ?? ''),
                       (string)($_POST['scribe_lang']    ?? 'en-IN'),
                       (string)($_POST['scribe_picked']  ?? ''),
                       dnull((string)($_POST['scribe_started'] ?? '')),
                       (string)($_POST['scribe_summary'] ?? ''),
                       max(0, pint('scribe_secs'))]);
        audit('consult_recorded','prescription',$rxId,'transcript '.strlen($tr).' chars');
    }

    /* No recording on this visit — save the typed prescription as the record,
       so every prescription has one and the history is never patchy. */
    $has = $pdo->prepare('SELECT COUNT(*) FROM consult_notes WHERE rx_id=?');
    $has->execute([$rxId]);
    if (!(int)$has->fetchColumn()) {
        $pdo->prepare('INSERT INTO consult_notes
                       (patient_id, appt_id, rx_id, transcript, disease, summary,
                        lang, secs, started_at, ended_at)
                       VALUES (?,?,?,?,?,?,?,0,NOW(),NOW())')
            ->execute([$pid, $apptId ?: null, $rxId, '', mb_substr($typedDx, 0, 200),
                       json_encode($writtenSummary, JSON_UNESCAPED_UNICODE), 'typed']);
        audit('consult_written','prescription',$rxId,'typed prescription recorded');
    }

    /* learn what this doctor actually uses, so the list self-sorts */
    foreach ($meds as $mm) {
        $pdo->prepare('UPDATE drugs SET uses=uses+1 WHERE name=?')->execute([$mm['name']]);
    }
    foreach ($labs as $ll) {
        $pdo->prepare('UPDATE labs SET uses=uses+1 WHERE name=?')->execute([$ll]);
    }

    if (pf('fav_name') !== '') {
        $pdo->prepare('INSERT INTO rx_sets(name,diagnosis,meds,labs,advice) VALUES(?,?,?,?,?)')
            ->execute([pf('fav_name'), pf('diagnosis'),
                       json_encode($meds, JSON_UNESCAPED_UNICODE), json_encode($labs, JSON_UNESCAPED_UNICODE),
                       pf('advice')]);
    }

    if ($apptId) $pdo->prepare("UPDATE appointments SET status='Completed' WHERE id=?")->execute([$apptId]);

    $_SESSION['ok'] = 'Prescription saved for ' . $pt['name'] . '.';
    redirect(($_POST['action'] ?? '') === 'send'
        ? "send.php?rx=$rxId"
        : "patient.php?id=$pid");
}

/* Prefill the medicine rows from the last prescription if asked */
$prefill = []; $preDiag = ''; $preLabs = ''; $preAdvice = '';
if (isset($_GET['set'])) {
    $g = $pdo->prepare('SELECT * FROM rx_sets WHERE id=?'); $g->execute([(int)$_GET['set']]);
    if ($st2 = $g->fetch()) {
        $prefill   = json_decode((string)$st2['meds'], true) ?: [];
        $preDiag   = (string)$st2['diagnosis'];
        $preLabs   = implode(', ', json_decode((string)$st2['labs'], true) ?: []);
        $preAdvice = (string)$st2['advice'];
        $pdo->prepare('UPDATE rx_sets SET uses=uses+1 WHERE id=?')->execute([(int)$_GET['set']]);
    }
}
if (isset($_GET['prev'])) {
    $q = $pdo->prepare('SELECT meds FROM prescriptions WHERE patient_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$pid]);
    $prefill = json_decode((string)$q->fetchColumn(), true) ?: [];
}
while (count($prefill) < 3) $prefill[] = ['name'=>'','dose'=>'','unit'=>'tab','when'=>'After Food','freq'=>'OD','duration'=>'','notes'=>''];

$DRUGROWS = $pdo->query('SELECT * FROM drugs WHERE active=1 ORDER BY uses DESC, name')->fetchAll();
$DRUGS = array_column($DRUGROWS, 'name');
$DRUGMAP = [];
foreach ($DRUGROWS as $d) $DRUGMAP[$d['name']] = [
    'dose'=>$d['def_dose'],'unit'=>$d['def_unit'],'when'=>$d['def_when'],
    'freq'=>$d['def_freq'],'duration'=>$d['def_duration'],'notes'=>$d['notes']];
/* Brand names the patient and doctor actually say. "Dolo 650" must find
   Tab Paracetamol 650mg — without this the 102-row brands table never
   reaches the browser and a spoken brand matches nothing. */
$BRANDMAP = [];
foreach ($pdo->query('SELECT brand,generic FROM brands WHERE active=1') as $b) {
    $g = strtolower($b['generic']);
    $cands = [];
    foreach ($DRUGROWS as $d) {
        if (stripos($d['name'], $g) !== false || stripos((string)$d['generic'], $g) !== false) {
            $cands[] = $d;
        }
    }
    if (!$cands) continue;

    /* A molecule is often stocked in several forms and strengths. Taking
       the first row gave adults a paediatric syrup — "Dolo 650" mapped to
       Syp Paracetamol 250mg/5ml. Prefer, in order: the form the doctor
       uses most, then a tablet or capsule over a syrup or injection,
       then the higher strength. The spoken strength still overrides this
       in matchDrug(). */
    usort($cands, function ($x, $y) use ($g) {
        $rank = function ($r) {
            $f = strtolower((string)$r['form']);
            if ($f === 'tab' || $f === 'cap') return 0;
            if ($f === 'syp' || $f === 'drops' || $f === 'spray') return 2;
            if ($f === 'inj') return 3;
            return 1;
        };
        $a = $rank($x); $b2 = $rank($y);
        if ($a !== $b2) return $a <=> $b2;
        /* A single ingredient before a combination: "Telma" is
           Telmisartan, not Telmisartan + HCTZ, which is a different
           prescription entirely. */
        $combo = fn($r) => (int)(bool)preg_match('/\+|\band\b/i', (string)$r['name']);
        $c = $combo($x) <=> $combo($y);
        if ($c !== 0) return $c;
        /* Exact generic before a longer one that merely contains it:
           "omeprazole" must not resolve to Esomeprazole. */
        $exact = fn($r) => strcasecmp(trim((string)$r['generic']), $g) === 0 ? 0 : 1;
        $e = $exact($x) <=> $exact($y);
        if ($e !== 0) return $e;
        $u = (int)($y['uses'] ?? 0) <=> (int)($x['uses'] ?? 0);
        if ($u !== 0) return $u;
        /* higher strength last resort, so 650 beats 500 for an adult */
        preg_match('/(\d+)/', (string)$x['strength'], $mx);
        preg_match('/(\d+)/', (string)$y['strength'], $my);
        return (int)($my[1] ?? 0) <=> (int)($mx[1] ?? 0);
    });
    $BRANDMAP[strtolower($b['brand'])] = $cands[0]['name'];
}

$LABROWS = $pdo->query('SELECT * FROM labs WHERE active=1 ORDER BY uses DESC, name')->fetchAll();
$LABS = array_column($LABROWS, 'name');
$SETS = $pdo->query('SELECT id,name FROM rx_sets ORDER BY uses DESC, name')->fetchAll();

head('Consultation — '.$pt['name']);
?>
<div class="page-h">
  <div>
    <h1><?= e($pt['name']) ?></h1>
    <p><?= (int)$pt['age'] ?><?= e($pt['sex']) ?> · ABHA <?= e($pt['abha']) ?> · <?= e($pt['phone']) ?></p>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost sm" href="queue.php">← Back to queue</a>
  <a class="btn ghost sm" href="?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>&prev=1">⟲ Load previous Rx</a>
  <?php if ($SETS): ?>
    <select onchange="if(this.value)location.href='?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>&set='+this.value"
            style="max-width:190px;padding:7px 9px;font-size:12.5px">
      <option value="">⭐ Use a favourite…</option>
      <?php foreach ($SETS as $sx): ?><option value="<?= (int)$sx['id'] ?>"><?= e($sx['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
  <a class="btn ghost sm" href="drugs.php">💊 My drugs</a>
  <a class="btn ghost sm" href="scan.php?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>">📄 Scan paper</a>
  <a class="btn ghost sm" href="write.php?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>">✍ Write by hand</a>
</div>

<?php if (trim((string)$pt['allergies']) !== ''): ?>
  <div class="flash-err">⚠ Allergies on record: <?= e($pt['allergies']) ?></div>
<?php endif; ?>

<form method="post" id="rxForm">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="appt_id" value="<?= (int)$apptId ?>">
<input type="hidden" name="patient_id" value="<?= (int)$pid ?>">
<div class="grid g2" style="align-items:start">
  <div>
    <div class="card">
      <h3>Vitals</h3><div class="sub">Recorded this visit</div>
      <div class="row3">
        <div class="field"><label>Temp (°F)</label><input name="v_temp" class="live" placeholder="98.6"></div>
        <div class="field"><label>BP (mmHg)</label><input name="v_bp" class="live" placeholder="120/80"></div>
        <div class="field"><label>Pulse (/min)</label><input name="v_pulse" class="live" placeholder="78"></div>
      </div>
      <div class="row3">
        <div class="field"><label>SpO₂ (%)</label><input name="v_spo2" class="live" placeholder="98"></div>
        <div class="field"><label>Weight (kg)</label><input name="v_weight" class="live" placeholder="70"></div>
        <div class="field"><label>Sugar (mg/dL)</label><input name="v_sugar" class="live" placeholder="110"></div>
      </div>
    </div>

    <div class="card">
      <h3>Diagnosis &amp; Prescription</h3>
      <div class="sub">This is what the patient receives on WhatsApp</div>
      <div class="field"><label>Diagnosis</label>
        <input name="diagnosis" list="dxlist" class="live" required placeholder="e.g. Dengue fever with thrombocytopenia"
               value="<?= e($preDiag) ?>"></div>
      <?php if ($preDiag === '' && trim((string)$pt['conditions']) !== ''): ?>
        <?php /* The stored condition is offered, not pre-filled. Filling it
                 in made every visit open with last month's illness, and hid
                 the fact that the recording had found the new one. */ ?>
        <div class="dxhint">
          <span>On record:</span>
          <?php foreach (array_slice(array_filter(array_map('trim',
                explode(',', (string)$pt['conditions']))), 0, 3) as $c): ?>
            <button type="button" class="dxhint-b"><?= e($c) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
      /* What this doctor has actually prescribed before for this illness.
         Learned from their own history, not a built-in list. */
      $dxNow   = $preDiag;
      $learned = $dxNow !== '' ? learn_meds_for($dxNow, 4) : [];
      if ($learned): ?>
        <div class="learnbar">
          <span class="learnbar-k">You usually prescribe</span>
          <?php foreach ($learned as $m): ?>
            <button type="button" class="learn-chip"
                    data-med='<?= e(json_encode($m, JSON_UNESCAPED_UNICODE)) ?>'>
              <?= e($m['name']) ?>
              <i><?= (int)$m['times'] ?>&times;</i>
            </button>
          <?php endforeach; ?>
          <span class="learnbar-h">tap to add</span>
        </div>
      <?php endif; ?>

      <div class="scribe">
        <div class="scribe-top">
          <button type="button" class="btn ghost sm" id="scribeBtn">🎙 Record conversation</button>
          <select id="scribeLang" style="max-width:130px;padding:6px 8px;font-size:12.5px">
            <option value="en-IN">English</option>
            <option value="hi-IN">हिन्दी</option>
            <option value="hinglish">Hinglish (mixed)</option>
          </select>
          <span class="ph">Let it listen to the whole consultation, then build the prescription from it</span>
        </div>
        <div id="scribeNotice"></div>
        <div id="scribeStat" class="mic-out"></div>
        <div id="scribeSave" class="mic-out"></div>
        <input type="hidden" id="scribeCsrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" id="scribePid"  value="<?= (int)$pid ?>">
        <input type="hidden" id="scribeAppt" value="<?= (int)$apptId ?>">
        <div id="scribePanel" style="display:none">
          <textarea id="scribeText" rows="6" placeholder="The conversation appears here as you talk. You can correct it before making the prescription."></textarea>
          <div class="scribe-btns">
            <button type="button" class="btn sm" id="scribeMake" disabled>✨ Make prescription from this</button>
            <button type="button" class="btn ghost sm" id="scribeApply" style="display:none">↓ Fill the form</button>
            <button type="button" class="btn ghost sm" id="scribeClear">Clear</button>
          </div>
          <div id="scribeSummary_ui"></div>
          <div id="scribeTurns_ui"></div>
          <div id="scribeSug" class="scribe-sug"></div>
        </div>
        <input type="hidden" name="scribe_transcript" id="scribeTranscript">
        <input type="hidden" name="scribe_lang"       id="scribeLangVal" value="en-IN">
        <input type="hidden" name="scribe_picked"     id="scribePicked">
        <input type="hidden" name="scribe_started"    id="scribeStart">
        <input type="hidden" name="scribe_turns"      id="scribeTurns">
        <input type="hidden" name="scribe_disease"    id="scribeDisease">
        <input type="hidden" name="scribe_dr"         id="scribeDr">
        <input type="hidden" name="scribe_pt"         id="scribePt">
        <input type="hidden" name="scribe_note_id"    id="scribeNoteId" value="0">
        <input type="hidden" name="scribe_summary"    id="scribeSummary">
        <input type="hidden" name="scribe_secs"       id="scribeSecs" value="0">
        <input type="hidden" name="scribe_allergy"    id="scribeAllergy">
      </div>

      <div class="micbar">
        <button type="button" class="btn ghost sm" id="micBtn">🎤 Dictate</button>
        <select id="micLang" style="max-width:130px;padding:6px 8px;font-size:12.5px">
          <option value="en-IN">English</option>
          <option value="hi-IN">हिन्दी</option>
        </select>
        <span class="ph">Speak: “Paracetamol 650 twice daily after food for three days”</span>
      </div>
      <div id="micOut" class="mic-out"></div>

      <label>Medicines</label>
      <table class="rxt"><thead><tr>
        <th style="width:24%">Medicine</th><th style="width:56px">Dose</th><th style="width:74px">Unit</th>
        <th style="width:132px">When</th><th style="width:84px">Freq</th><th style="width:86px">Duration</th>
        <th>Notes</th><th style="width:26px"></th></tr></thead>
        <tbody id="medRows">
        <?php foreach ($prefill as $m): ?>
          <tr>
            <td data-l="Medicine"><input name="med_name[]" list="drugs" class="live" value="<?= e($m['name']??'') ?>" placeholder="Search medicine"></td>
            <td class="half" data-l="Dose"><input name="med_dose[]" class="live" value="<?= e($m['dose']??'') ?>" placeholder="1-0-1"></td>
            <td class="half" data-l="Unit"><select name="med_unit[]" class="live">
              <?php foreach (picklist('unit',['tab','cap','ml','puff','sachet','drop']) as $u): ?>
                <option <?= ($m['unit']??'')===$u?'selected':'' ?>><?= $u ?></option><?php endforeach; ?></select></td>
            <td data-l="When"><select name="med_when[]" class="live">
              <?php foreach (picklist('when',['After Food','Before Food','With Food','Empty Stomach','Bedtime']) as $w): ?>
                <option <?= ($m['when']??'')===$w?'selected':'' ?>><?= $w ?></option><?php endforeach; ?></select></td>
            <td class="half" data-l="Frequency"><select name="med_freq[]" class="live">
              <?php foreach (picklist('freq',['OD','BD','TDS','QID','Weekly','SOS']) as $f): ?>
                <option <?= ($m['freq']??'')===$f?'selected':'' ?>><?= $f ?></option><?php endforeach; ?></select></td>
            <td class="half" data-l="Duration"><input name="med_dur[]" class="live" value="<?= e($m['duration']??'') ?>" placeholder="5 days"></td>
            <td data-l="Notes"><input name="med_notes[]" class="live" value="<?= e($m['notes']??'') ?>" placeholder="Note"></td>
            <td class="del-cell"><button type="button" class="del" onclick="this.closest('tr').remove();preview()">🗑</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <datalist id="dxlist"><?php foreach (diagnosis_list() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="drugs"><?php foreach ($DRUGS as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
      <button type="button" class="btn ghost sm" style="margin-top:8px" onclick="addMed()">+ Add medicine</button>

      <div class="field" style="margin-top:15px"><label>Tests advised</label>
        <input name="labs" id="labsInput" class="live" placeholder="CBC, Platelet Count" value="<?= e($preLabs) ?>">
        <div style="margin-top:7px"><?php foreach ($LABS as $l): ?>
          <span class="tag" style="cursor:pointer" onclick="addLab('<?= e($l) ?>')"><?= e($l) ?></span>
        <?php endforeach; ?></div>
      </div>

      <div class="row2">
        <div class="field"><label>Advice to patient</label>
          <textarea name="advice" rows="3" class="live" placeholder="Diet, activity, warning signs…"><?= e($preAdvice) ?></textarea></div>
        <div class="field"><label>Next check-up</label>
          <input type="date" name="follow_up" class="live"></div>
      </div>

      <div class="field">
        <label>Message language</label>
        <select name="lang" id="langSel" class="live">
          <?php foreach (picklist('lang',['English','Hindi','Marathi']) as $l): ?>
            <option <?= $pt['lang']===$l?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
        <div class="ph">Defaults to the language on the patient's record.</div>
      </div>

      <div style="display:flex;gap:9px;padding-top:14px;border-top:1px solid var(--line);flex-wrap:wrap">
        <div class="field" style="margin:0 0 11px">
          <label>⭐ Save this as a favourite (optional)</label>
          <input name="fav_name" placeholder="e.g. Viral fever - adult" style="max-width:330px">
          <div class="ph">Give it a name and it appears in the “Use a favourite” list next time.</div>
        </div>
        <button class="btn" name="action" value="save">Save prescription</button>
        <button class="btn wa" name="action" value="send">Save &amp; send on WhatsApp →</button>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="position:sticky;top:76px">
      <div id="safetyBox"></div>
      <h3>WhatsApp Preview</h3>
      <div class="sub">Live — exactly what <?= e($pt['name']) ?> will receive</div>
      <div class="wa-phone">
        <div class="wa-head">
          <div class="av" style="background:#25d366;color:#0b141a"><?= e(mb_substr($pt['name'],0,1)) ?></div>
          <div style="min-width:0">
            <b style="color:#e9edef;font-size:12.5px"><?= e($pt['name']) ?></b>
            <div style="color:#8696a0;font-size:10.5px"><?= e($pt['phone']) ?></div></div>
          <div style="margin-left:auto;text-align:right">
            <div style="color:#25d366;font-size:9.5px;font-weight:800">✓ WHATSAPP BUSINESS</div>
            <div style="color:#8696a0;font-size:9.5px;font-weight:700"><?= e(clinic('name')) ?></div></div>
        </div>
        <div class="wa-body"><div class="wa-msg" id="waMsg">Loading preview…<div class="wa-t"><?= date('h:i a') ?> ✓✓</div></div></div>
      </div>
      <div class="ph">Message is built from the template in
        <a href="templates.php" style="color:var(--v);font-weight:700">Templates</a>, so you can reword it any time.</div>
    </div>
  </div>
</div>
</form>

<script>
const PID = <?= (int)$pid ?>;
function addMed(){
  const tb=document.getElementById('medRows');
  const r=tb.rows[0].cloneNode(true);
  r.querySelectorAll('input').forEach(i=>{i.value='';delete i.dataset.af;});
  r.querySelector('.del').setAttribute('onclick',"this.closest('tr').remove();preview()");
  tb.appendChild(r); bindLive(); bindAuto(); preview();
}
function addLab(t){
  const el=document.getElementById('labsInput');
  const cur=el.value.split(',').map(s=>s.trim()).filter(Boolean);
  const i=cur.indexOf(t); i<0?cur.push(t):cur.splice(i,1);
  el.value=cur.join(', '); preview();
}
let timer=null;
function preview(){
  clearTimeout(timer);
  timer=setTimeout(async ()=>{
    const fd=new FormData(document.getElementById('rxForm'));
    fd.append('patient_id',PID);
    try{
      const r=await fetch('api/preview.php',{method:'POST',body:fd});
      const j=await r.json();
      document.getElementById('waMsg').innerHTML =
        j.html + '<div class="wa-t"><?= date('h:i a') ?> ✓✓</div>';
      renderAlerts(j.alerts||[]);
    }catch(e){}
  },220);
}
let hasDanger=false;
function renderAlerts(list){
  const box=document.getElementById('safetyBox');
  hasDanger=list.some(a=>a.level==='danger');
  if(!list.length){ box.innerHTML=''; return; }
  box.innerHTML='<div class="alertwrap">'+list.map(a=>
    '<div class="alert '+(a.level==='danger'?'a-danger':'a-warn')+'">'
    +'<span class="ai">'+(a.level==='danger'?'\u26D4':'\u26A0')+'</span>'
    +'<div><b>'+esc(a.drug)+'</b><div>'+esc(a.msg)+'</div></div></div>').join('')+'</div>';
}
function esc(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

document.getElementById('rxForm').addEventListener('submit',function(ev){
  if(hasDanger && !confirm('There are RED safety alerts on this prescription.\n\nAre you sure you want to continue?')){
    ev.preventDefault();
  }
});

/* Picking a medicine fills its default dose, timing and duration. */
var DRUGMAP=<?= json_encode($DRUGMAP, JSON_UNESCAPED_UNICODE) ?>;
var BRANDMAP=<?= json_encode($BRANDMAP, JSON_UNESCAPED_UNICODE) ?>;
var ADVICELIST=<?= json_encode(advice_list(), JSON_UNESCAPED_UNICODE) ?>;
var DXLIST=<?= json_encode(diagnosis_list(400), JSON_UNESCAPED_UNICODE) ?>;
function autofill(inp){
  var d=DRUGMAP[inp.value]; if(!d) return;
  var row=inp.closest('tr')||inp.parentElement.parentElement;
  function set(sel,val){ var el=row.querySelector(sel); if(el && val!=null && val!=='' && !el.value) el.value=val; }
  function force(sel,val){ var el=row.querySelector(sel); if(el && val!=null && val!=='') el.value=val; }
  force('[name="med_dose[]"]',d.dose); force('[name="med_unit[]"]',d.unit);
  force('[name="med_when[]"]',d.when); force('[name="med_freq[]"]',d.freq);
  force('[name="med_dur[]"]',d.duration); set('[name="med_notes[]"]',d.notes);
  preview();
}
function bindAuto(){
  document.querySelectorAll('[name="med_name[]"]').forEach(function(el){
    if(el.dataset.af) return; el.dataset.af='1';
    el.addEventListener('change',function(){autofill(el);});
  });
}
bindAuto();

function bindLive(){
  document.querySelectorAll('.live').forEach(el=>{
    el.oninput=preview; el.onchange=preview;
  });
}
bindLive(); bindAuto(); preview();
</script>
<?php foot(); ?>
<script>window.LABLIST=<?= json_encode($LABS, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/voice.js?v=<?= asset_v('assets/voice.js') ?>"></script>
<script src="assets/speakers.js?v=<?= asset_v('assets/speakers.js') ?>"></script>
<script src="assets/summary.js?v=<?= asset_v('assets/summary.js') ?>"></script>
<script src="assets/miccheck.js?v=<?= asset_v('assets/miccheck.js') ?>"></script>
<script src="assets/audiorec.js?v=<?= asset_v('assets/audiorec.js') ?>"></script>
<script src="assets/livedock.js?v=<?= asset_v('assets/livedock.js') ?>"></script>
<script src="assets/scribe.js?v=<?= asset_v('assets/scribe.js') ?>"></script>
<script>
/* Tapping a learned medicine fills a row with the doctor's own usual
   dose — the one they actually write, not the list default. */
/* Tapping a stored condition fills the diagnosis box. */
document.querySelectorAll('.dxhint-b').forEach(function (b) {
  b.onclick = function () {
    var dx = document.querySelector('[name="diagnosis"]');
    if (!dx) return;
    dx.value = b.textContent.trim();
    dx.dispatchEvent(new Event('input', { bubbles: true }));
    dx.focus();
  };
});

function wireChips() {
  document.querySelectorAll('.learn-chip').forEach(function (b) {
    if (b.dataset.wired) return;
    b.dataset.wired = '1';
    b.onclick = function () {
    var m = JSON.parse(b.dataset.med || '{}');
    var row = (window.RxParse && RxParse.emptyRow) ? RxParse.emptyRow() : null;
    if (!row) return;
    var set = function (n, v) { var e = row.querySelector('[name="' + n + '"]'); if (e && v) e.value = v; };
    set('med_name[]', m.name); set('med_dose[]', m.dose); set('med_unit[]', m.unit);
    set('med_when[]', m.when); set('med_freq[]', m.freq); set('med_dur[]', m.duration);
      b.classList.add('used');
      if (typeof bindAuto === 'function') bindAuto();
      if (typeof preview === 'function') preview();
    };
  });
}
wireChips();

/* Follow the diagnosis as it is typed, so the suggestions match what the
   doctor is actually writing — not only the condition already on file. */
(function () {
  var dx = document.querySelector('[name="diagnosis"]');
  if (!dx) return;
  var bar = document.querySelector('.learnbar'), t = null, last = '';

  function ensureBar() {
    if (bar) return bar;
    bar = document.createElement('div');
    bar.className = 'learnbar';
    dx.closest('.field').insertAdjacentElement('afterend', bar);
    return bar;
  }

  dx.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () {
      var v = dx.value.trim();
      if (v.length < 3 || v === last) return;
      last = v;
      fetch('api/learn.php?dx=' + encodeURIComponent(v), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j) return;
          var b = ensureBar();
          if (!j.meds.length) { b.innerHTML = ''; return; }
          var h = '<span class="learnbar-k">You usually prescribe</span>';
          j.meds.forEach(function (m) {
            h += '<button type="button" class="learn-chip" data-med=\'' +
                 JSON.stringify(m).replace(/'/g, '&#39;') + '\'>' +
                 m.name + ' <i>' + m.times + '&times;</i></button>';
          });
          h += '<span class="learnbar-h">tap to add</span>';
          b.innerHTML = h;
          wireChips();
        })
        .catch(function () {});
    }, 400);
  });
})();
</script>
