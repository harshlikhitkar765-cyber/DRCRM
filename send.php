<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

require_doctor();

$pdo = db();
$rxId = (int)($_GET['rx'] ?? $_POST['rx'] ?? 0);
$q = $pdo->prepare('SELECT r.*, p.name, p.phone, p.lang, p.age, p.sex, p.wa_consent
                    FROM prescriptions r JOIN patients p ON p.id=r.patient_id WHERE r.id=?');
$q->execute([$rxId]); $rx = $q->fetch();
if (!$rx) { http_response_code(404); exit('Prescription not found'); }

$meds   = json_decode((string)$rx['meds'], true)   ?: [];
$vitals = json_decode((string)$rx['vitals'], true) ?: [];
$labs   = json_decode((string)$rx['labs'], true)   ?: [];
$lang   = $_POST['lang'] ?? $rx['lang'] ?? 'English';

/* Build the message from the stored template */
function build_body(PDO $pdo, array $rx, array $meds, array $vitals, array $labs, string $lang, ?string $override=null): string {
    if ($override !== null && trim($override) !== '') return $override;
    $t = $pdo->prepare('SELECT body FROM templates WHERE lang=? ORDER BY is_default DESC, id LIMIT 1');
    $t->execute([$lang]);
    $tpl = (string)($t->fetchColumn() ?: (default_templates()[$lang] ?? default_templates()['English']));
    return render_template($tpl, [
        'lang'=>$lang, 'patient'=>$rx['name'],
        'diagnosis'=>(string)$rx['diagnosis'],
        'medicines'=>format_meds($meds,$lang),
        'vitals'=>format_vitals($vitals),
        'labs'=>implode(', ', $labs),
        'advice'=>(string)$rx['advice'],
        'followup'=>(string)$rx['follow_up'],
    ]);
}

$isInk  = !empty($rx['ink_mode']) && !empty($rx['ink_file']);
$inkAbs = $isInk ? __DIR__.'/data/rx/'.basename((string)$rx['ink_file']) : null;

$body = $isInk
    ? ink_caption($rx, (string)$rx['diagnosis'], (string)$rx['follow_up'])
    : build_body($pdo,$rx,$meds,$vitals,$labs,$lang);
$link = null; $sentNow = false; $sendError = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do'] ?? '')==='send') {
    csrf_check();
    /* Consent must be enforced on the server, not merely shown as a red
       message in the UI. This also protects direct POSTs and resend links. */
    if ((int)($rx['wa_consent'] ?? 0) !== 1) {
        $sendError = 'WhatsApp consent has not been recorded for this patient. No message was sent.';
        audit('wa_send_blocked', 'prescription', $rxId, 'missing consent');
    } else {
        try {
            if ($isInk) {
                $body = pf('body') !== '' ? (string)$_POST['body'] : $body;
                $res  = wa_send_image((string)$rx['phone'], $body, $rxId, (string)$inkAbs);
            } else {
                $body = build_body($pdo,$rx,$meds,$vitals,$labs,$lang, $_POST['body'] ?? null);
                $res  = wa_send((string)$rx['phone'], $body);
            }
            $link = $res['link'] ?? null;
            audit('wa_send', 'prescription', $rxId, $res['driver'].'/'.$res['status']);

            $ins = $pdo->prepare('INSERT INTO wa_messages(patient_id,rx_id,phone,lang,body,driver,status,response)
                                  VALUES(?,?,?,?,?,?,?,?)');
            $ins->execute([(int)$rx['patient_id'],$rxId,$rx['phone'],$lang,$body,
                           $res['driver'],$res['status'],$res['response']]);
            $sentNow = (bool)$res['ok'];
            if ($res['ok']) {
                $_SESSION['ok'] = $res['driver']==='cloud'
                    ? 'Message delivered via WhatsApp Cloud API.'
                    : 'Message ready — press the green button to open WhatsApp.';
            } else {
                $sendError = 'WhatsApp could not accept this message. Check the patient phone number and the delivery log.';
            }
        } catch (Throwable $e) {
            $sendError = $e->getMessage();
            audit('wa_send_blocked', 'prescription', $rxId, 'delivery configuration error');
        }
    }
}

head('Send on WhatsApp');
?>
<div class="page-h">
  <div><h1>Send prescription on WhatsApp</h1>
    <p><?= e($rx['name']) ?> · <?= e($rx['phone']) ?> · Rx #<?= $rxId ?> dated <?= e(fmt_date($rx['rx_date'])) ?></p></div>
  <div class="spacer"></div>
  <a class="btn ghost sm" href="patient.php?id=<?= (int)$rx['patient_id'] ?>">Patient chart</a>
  <a class="btn ghost sm" href="print.php?rx=<?= $rxId ?>" target="_blank">⎙ Print prescription</a>
</div>

<?php if ($sendError !== ''): ?>
  <div class="flash-err" role="alert"><?= e($sendError) ?></div>
<?php endif; ?>
<?php if ((int)($rx['wa_consent'] ?? 1) !== 1): ?>
  <div class="card" style="background:#fdeaea;border:1px solid var(--red)">
    <b style="font-size:13px">⚠ This patient has not consented to WhatsApp messages</b>
    <div class="ph" style="margin-top:5px">They replied STOP, or consent was never recorded. Under the DPDP Act
      you should not message them. Record consent on the patient record before sending.</div>
  </div>
<?php endif; ?>
<div class="grid g2" style="align-items:start">
  <div class="card">
    <h3>Message</h3><div class="sub">Edit before sending if you want to personalise it</div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="rx" value="<?= $rxId ?>">
      <input type="hidden" name="do" value="send">
      <?php if ($isInk): ?>
        <div class="flash-ok" style="margin-bottom:12px">✍ Handwritten prescription — the sheet is sent as an image with this caption.</div>
      <?php endif; ?>
      <div class="field" <?= $isInk?'style="display:none"':'' ?>><label>Language</label>
        <select name="lang" onchange="this.form.querySelector('[name=body]').value='';this.form.removeAttribute('data-x');this.form.do.value='';this.form.submit()">
          <?php foreach (picklist('lang',['English','Hindi','Marathi']) as $l): ?>
            <option <?= $lang===$l?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
        <div class="ph">Switching language rebuilds the message from that template.</div>
      </div>
      <div class="field"><label>Message body</label>
        <textarea name="body" rows="18" style="font-size:12.5px;line-height:1.55"><?= e($body) ?></textarea></div>
      <?php if ((int)($rx['wa_consent'] ?? 0) === 1): ?>
        <button class="btn wa" style="padding:11px 18px">Send to <?= e($rx['phone']) ?></button>
      <?php else: ?>
        <button class="btn wa" style="padding:11px 18px" disabled>Consent required before sending</button>
        <a class="btn ghost" href="patient.php?id=<?= (int)$rx['patient_id'] ?>">Record consent</a>
      <?php endif; ?>
      <a class="btn ghost" href="queue.php">Back to queue</a>
    </form>
  </div>

  <div>
    <?php if ($sentNow && $link): ?>
      <div class="card" style="border:2px solid #25d366">
        <h3>Ready to send</h3>
        <div class="sub">Click-to-chat mode — this opens WhatsApp with the message pre-filled</div>
        <a class="btn wa" style="width:100%;text-align:center;padding:13px;font-size:14px"
           href="<?= e($link) ?>" target="_blank" rel="noopener">Open WhatsApp &amp; send →</a>
        <div class="ph">Logged to the WhatsApp history either way.
          To send automatically without this step, set <code>WA_DRIVER</code> to <code>cloud</code> in
          <code>data/config.local.php</code> and add the Meta credentials.</div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Preview</h3><div class="sub">How it appears on the patient's phone</div>
      <div class="wa-phone">
        <div class="wa-head">
          <div class="av" style="background:#25d366;color:#0b141a"><?= e(mb_substr($rx['name'],0,1)) ?></div>
          <div><b style="color:#e9edef;font-size:12.5px"><?= e($rx['name']) ?></b>
            <div style="color:#8696a0;font-size:10.5px"><?= e($rx['phone']) ?></div></div>
          <div style="margin-left:auto;text-align:right">
            <div style="color:#25d366;font-size:9.5px;font-weight:800">✓ WHATSAPP BUSINESS</div>
            <div style="color:#8696a0;font-size:9.5px;font-weight:700"><?= e(clinic('name')) ?></div></div>
        </div>
        <div class="wa-body"><div class="wa-msg"><?php if ($isInk): ?>
          <a href="rximg.php?rx=<?= $rxId ?>" target="_blank">
            <img src="rximg.php?rx=<?= $rxId ?>" alt="Handwritten prescription"
                 style="width:100%;border-radius:7px;display:block;margin-bottom:7px"></a>
        <?php endif; ?><?php
          $h = e($body);
          $h = preg_replace('/\*([^*\n]+)\*/u','<b>$1</b>',$h);
          $h = preg_replace('/_([^_\n]+)_/u','<i>$1</i>',$h);
          echo $h;
        ?><div class="wa-t"><?= date('h:i a') ?> ✓✓</div></div></div>
      </div>
    </div>
  </div>
</div>
<?php foot(); ?>
