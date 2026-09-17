<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
require_once __DIR__.'/inc/trend.php';

 require_login();
$pdo=db(); $id=gi('id');
$q=$pdo->prepare('SELECT * FROM patients WHERE id=?'); $q->execute([$id]); $pt=$q->fetch();
if(!$pt){ http_response_code(404); exit('Patient not found'); }
$rx=$pdo->prepare('SELECT * FROM prescriptions WHERE patient_id=? ORDER BY id DESC'); $rx->execute([$id]); $rxs=$rx->fetchAll();
/* recorded conversations, keyed by the prescription they produced */
$nt=$pdo->prepare('SELECT rx_id,transcript,turns,disease,dr_points,pt_points,
                          summary,secs,started_at,created_at
                   FROM consult_notes WHERE patient_id=? AND rx_id IS NOT NULL');
$nt->execute([$id]); $NOTES=[];
foreach($nt->fetchAll() as $n) $NOTES[(int)$n['rx_id']]=$n;
$series=['sys'=>[],'sugar'=>[],'weight'=>[]];
foreach(array_reverse($rxs) as $rr){
  $vv=json_decode((string)$rr['vitals'],true)?:[];
  $lbl=date('j M',strtotime((string)$rr['rx_date']));
  if(!empty($vv['bp']) && preg_match('~(\d{2,3})\s*/~',(string)$vv['bp'],$mm))
      $series['sys'][]=['d'=>$lbl,'v'=>(float)$mm[1]];
  if(isset($vv['sugar']) && is_numeric($vv['sugar'])) $series['sugar'][]=['d'=>$lbl,'v'=>(float)$vv['sugar']];
  if(isset($vv['weight'])&& is_numeric($vv['weight']))$series['weight'][]=['d'=>$lbl,'v'=>(float)$vv['weight']];
}
$docs=$pdo->prepare('SELECT * FROM documents WHERE patient_id=? ORDER BY id DESC LIMIT 6');
$docs->execute([$id]); $docList=$docs->fetchAll();
$due=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE patient_id=? AND paid=0');
$due->execute([$id]); $dueAmt=(int)$due->fetchColumn();
$wa=$pdo->prepare('SELECT * FROM wa_messages WHERE patient_id=? ORDER BY id DESC LIMIT 8'); $wa->execute([$id]); $was=$wa->fetchAll();
head($pt['name']);
?>
<div class="page-h">
  <div><h1><?= e($pt['name']) ?></h1>
    <p><?= (int)$pt['age'] ?><?= e($pt['sex']) ?> · ABHA <?= e($pt['abha']) ?> · <?= e($pt['phone']) ?> · <?= e($pt['city']) ?></p></div>
  <div class="spacer"></div>
  <a class="btn" href="consult.php?patient=<?= $id ?>">New consultation</a>
  <a class="btn ghost" href="padlink.php?patient=<?= $id ?>">📱 Smart Pad</a>
  <a class="btn ghost" href="documents.php?patient=<?= $id ?>">🧪 Reports</a>
  <a class="btn ghost" href="patients.php">← All patients</a>
</div>

<?php if(trim((string)$pt['allergies'])!==''): ?>
  <div class="flash-err">⚠ Allergies: <?= e($pt['allergies']) ?></div>
<?php endif; ?>

<div class="grid g2" style="align-items:start">
  <div>
    <div class="card"><h3>Prescription history</h3><div class="sub"><?= count($rxs) ?> visit(s) recorded</div>
      <?php if(!$rxs): ?><div class="empty">No prescriptions yet.</div><?php endif; ?>
      <?php foreach($rxs as $r):
        $meds=json_decode((string)$r['meds'],true)?:[]; $labs=json_decode((string)$r['labs'],true)?:[];
        $v=json_decode((string)$r['vitals'],true)?:[]; ?>
        <div style="padding:13px 0;border-bottom:1px solid var(--line)">
          <div style="display:flex;align-items:center;gap:9px;margin-bottom:7px">
            <b style="font-size:13px"><?= e($r['diagnosis']) ?></b>
            <span class="pill p-gray" style="margin-left:auto"><?= e(fmt_date($r['rx_date'])) ?></span>
          </div>
          <?php
          /* What the patient actually complained of. It was only inside the
             collapsed conversation, so the visit could not be understood
             without opening it. */
          $nn0 = $NOTES[(int)$r['id']] ?? null;
          $sm0 = $nn0 ? (json_decode((string)$nn0['summary'], true) ?: []) : [];
          $cc  = '';
          if (!empty($sm0['complaints'])) {
              $cc = implode(', ', $sm0['complaints']);
              if (!empty($sm0['duration'])) $cc .= ' — since ' . $sm0['duration'];
          }
          if ($cc !== ''): ?>
            <div style="display:flex;gap:7px;align-items:baseline;margin:-2px 0 7px">
              <span style="font-size:9.5px;font-weight:800;letter-spacing:.05em;
                           text-transform:uppercase;color:var(--muted);flex:none">Problem</span>
              <span style="font-size:12.5px;color:var(--ink)"><?= e($cc) ?></span>
            </div>
          <?php endif; ?>
          <?php if(!empty($r['ink_mode']) && !empty($r['ink_file'])): ?>
            <a href="rximg.php?rx=<?= (int)$r['id'] ?>" target="_blank">
              <img src="rximg.php?rx=<?= (int)$r['id'] ?>" alt="Handwritten prescription"
                   style="width:100%;max-width:330px;border:1px solid var(--line);border-radius:9px;display:block;margin-bottom:8px"></a>
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px">✍ Handwritten · tap to enlarge</div>
          <?php endif; ?>
          <?php if(format_vitals($v)!=='—'): ?>
            <div style="font-size:11.5px;color:var(--muted);margin-bottom:6px"><?= e(format_vitals($v)) ?></div><?php endif; ?>
          <div style="font-size:12.5px;white-space:pre-line;margin-bottom:6px"><?= e(format_meds($meds)) ?></div>
          <?php if($labs): ?><div style="margin-bottom:6px"><?php foreach($labs as $l): ?>
            <span class="tag">🧪 <?= e($l) ?></span><?php endforeach; ?></div><?php endif; ?>
          <?php if(trim((string)$r['advice'])!==''): ?>
            <div style="font-size:12px;color:var(--muted)"><?= e($r['advice']) ?></div><?php endif; ?>
          <?php if(isset($NOTES[(int)$r['id']])): $nn=$NOTES[(int)$r['id']];
            $dpts=json_decode((string)$nn['dr_points'],true)?:[];
            $ppts=json_decode((string)$nn['pt_points'],true)?:[];
            $trn =json_decode((string)$nn['turns'],true)?:[]; ?>
            <?php $sm=json_decode((string)$nn['summary'],true)?:[]; ?>
            <?php if(trim((string)$nn['disease'])!==''): ?>
              <div style="font-size:12px;margin:7px 0 4px">
                <span class="tag" style="background:#eef6f4;border-color:#cfe4de">🩺 <?= e($nn['disease']) ?></span>
                <?php if(!empty($sm['when'])): ?>
                  <span style="font-size:11px;color:var(--muted);margin-left:5px">
                    🎙 <?= e($sm['when']) ?><?= !empty($sm['took'])?' · '.e($sm['took']):'' ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if($ppts || $dpts): ?>
              <div class="grid g2" style="gap:9px;margin-top:7px">
                <?php if($ppts): ?>
                <div style="background:#fff6e8;border:1px solid #f0dcc0;border-radius:8px;padding:9px 11px">
                  <div style="font-size:10.5px;font-weight:700;letter-spacing:.05em;color:#b26a12;
                              text-transform:uppercase;margin-bottom:5px">Patient said</div>
                  <ul style="margin:0;padding-left:16px;font-size:12.5px;line-height:1.55">
                    <?php foreach($ppts as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
                  </ul>
                </div>
                <?php endif; ?>
                <?php if($dpts): ?>
                <div style="background:#eef6f4;border:1px solid #cfe4de;border-radius:8px;padding:9px 11px">
                  <div style="font-size:10.5px;font-weight:700;letter-spacing:.05em;color:#0a7d6b;
                              text-transform:uppercase;margin-bottom:5px">Doctor said</div>
                  <ul style="margin:0;padding-left:16px;font-size:12.5px;line-height:1.55">
                    <?php foreach($dpts as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
                  </ul>
                </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <details style="margin-top:7px">
              <summary style="font-size:12px;color:var(--muted);cursor:pointer">🎙 Full conversation</summary>
              <?php if($trn): ?>
                <div style="margin-top:6px">
                <?php foreach($trn as $t): ?>
                  <div class="turn <?= ($t['s']??'dr')==='dr'?'turn-dr':'turn-pt' ?>">
                    <b style="flex:0 0 54px;font-size:10.5px;text-transform:uppercase;
                       color:<?= ($t['s']??'dr')==='dr'?'#0a7d6b':'#b26a12' ?>">
                       <?= ($t['s']??'dr')==='dr'?'Dr':'Patient' ?></b>
                    <span><?= e($t['t']??'') ?></span>
                  </div>
                <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div style="font-size:12.5px;line-height:1.6;white-space:pre-wrap;background:#f7fafb;
                            border:1px solid var(--line);border-radius:8px;padding:10px 11px;margin-top:6px">
                  <?= e($nn['transcript']) ?></div>
              <?php endif; ?>
            </details>
          <?php endif; ?>
          <div style="margin-top:9px;display:flex;gap:7px">
            <a class="btn wa sm" href="send.php?rx=<?= (int)$r['id'] ?>">Send on WhatsApp</a>
            <a class="btn ghost sm" href="print.php?rx=<?= (int)$r['id'] ?>" target="_blank">⎙ Print</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
    <div class="card"><h3>Patient details</h3><div class="sub">Record summary</div>
      <table><tbody>
        <tr><td style="color:var(--muted)">ABHA</td><td style="text-align:right"><b><?= e($pt['abha']) ?></b></td></tr>
        <tr><td style="color:var(--muted)">Phone</td><td style="text-align:right"><b><?= e($pt['phone']) ?></b></td></tr>
        <tr><td style="color:var(--muted)">Care setting</td><td style="text-align:right"><b><?= e($pt['care']) ?></b></td></tr>
        <tr><td style="color:var(--muted)">Risk</td><td style="text-align:right"><b><?= e($pt['risk']) ?></b></td></tr>
        <tr><td style="color:var(--muted)">Message language</td><td style="text-align:right"><b><?= e($pt['lang']) ?></b></td></tr>
        <tr><td style="color:var(--muted)">WhatsApp consent</td><td style="text-align:right">
          <?php if((int)($pt['wa_consent']??0)===1): ?><span class="pill p-green">Given</span>
          <?php else: ?>
            <form method="post" action="consent.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn sm">Record consent</button></form>
          <?php endif; ?></td></tr>
      </tbody></table>
      <div style="margin-top:12px"><label>Conditions</label>
        <?php foreach(array_filter(array_map('trim',explode(',',(string)$pt['conditions']))) as $c): ?>
          <span class="tag"><?= e($c) ?></span><?php endforeach; ?></div>
    </div>

    <?php if($dueAmt>0): ?>
      <div class="card" style="border:1px solid var(--amber);background:#fff9ee">
        <b style="font-size:13px">Outstanding balance ₹<?= number_format($dueAmt) ?></b>
        <div class="ph" style="margin-top:4px"><a href="billing.php">Settle in Billing →</a></div></div>
    <?php endif; ?>

    <?php if($series['sys']||$series['sugar']||$series['weight']): ?>
      <div class="card"><h3>Trends</h3><div class="sub">Across recorded visits</div>
        <?php if($series['sys']):    ?><div style="margin-bottom:14px"><?= trend_svg($series['sys'],'Systolic BP','mmHg','#0e7c86') ?></div><?php endif; ?>
        <?php if($series['sugar']):  ?><div style="margin-bottom:14px"><?= trend_svg($series['sugar'],'Blood sugar','mg/dL','#c0392b') ?></div><?php endif; ?>
        <?php if($series['weight']): ?><div><?= trend_svg($series['weight'],'Weight','kg','#2d6a9f') ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if($docList): ?>
      <div class="card"><h3>Reports on file</h3><div class="sub"><a href="documents.php?patient=<?= $id ?>">Add or view all</a></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:9px">
        <?php foreach($docList as $d): ?>
          <a href="docimg.php?id=<?= (int)$d['id'] ?>" target="_blank" title="<?= e($d['title']) ?>">
            <img src="docimg.php?id=<?= (int)$d['id'] ?>" style="width:100%;height:74px;object-fit:cover;
                 border:1px solid var(--line);border-radius:7px;display:block"></a>
        <?php endforeach; ?></div>
      </div>
    <?php endif; ?>

    <div class="card"><h3>WhatsApp history</h3><div class="sub">Sent from <?= e(clinic('name')) ?></div>
      <?php if(!$was): ?><div class="empty">Nothing sent yet.</div><?php endif; ?>
      <?php foreach($was as $w): ?>
        <div style="display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #f2f6f7">
          <div style="width:8px;height:8px;border-radius:50%;margin-top:5px;background:<?= $w['status']==='Sent'?'var(--green)':($w['status']==='Failed'?'var(--red)':'var(--amber)') ?>"></div>
          <div style="min-width:0;flex:1">
            <b style="font-size:12.5px"><?= e($w['status']) ?> · <?= e($w['lang']) ?></b>
            <div style="font-size:11px;color:var(--muted)"><?= e($w['driver']==='cloud'?'Cloud API':'Click-to-chat') ?> · <?= e($w['sent_at']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php foot(); ?>
