<?php
/* Desk side of the Smart Pad Link: show a QR, wait for the phone. */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
require_once __DIR__.'/inc/pad.php';

require_doctor();

$pdo = db();
$apptId = gi('appt');
$pid    = gi('patient');
$mode   = ($_GET['mode'] ?? 'write') === 'scan' ? 'scan' : 'write';
if ($apptId) {
    $a = $pdo->prepare('SELECT * FROM appointments WHERE id=?'); $a->execute([$apptId]);
    $ap = $a->fetch(); if (!$ap) { http_response_code(404); exit('Appointment not found'); }
    $pid = (int)$ap['patient_id'];
    appointment_start($pdo, $apptId);
}
$q = $pdo->prepare('SELECT * FROM patients WHERE id=?'); $q->execute([$pid]); $pt = $q->fetch();
if (!$pt) { http_response_code(404); exit('Patient not found'); }
if (public_app_url() === '') {
    $_SESSION['err'] = 'Set the canonical APP_URL in data/config.local.php before creating a Smart Pad link.';
    redirect($apptId ? 'queue.php' : 'patient.php?id='.$pid);
}

$tok = pad_create($pid, $apptId, $mode);
$url = pad_url($tok);
audit('pad_link', 'patient', $pid, $mode);

head('Smart Pad Link');
?>
<div class="page-h">
  <div><h1>Smart Pad Link</h1>
    <p><?= e($pt['name']) ?> · scan with your phone or tablet to write the prescription</p></div>
  <div class="spacer"></div>
  <a class="btn ghost sm" href="consult.php?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>">⌨ Type instead</a>
</div>

<div class="grid g2" style="align-items:start">
  <div class="card" style="text-align:center">
    <h3>Scan this code</h3>
    <div class="sub">Point your phone camera at it — no app needed</div>
    <div style="display:inline-block;padding:14px;background:#fff;border:1px solid var(--line);
         border-radius:14px;margin:14px 0"><?= qr_svg($url, 250) ?></div>
    <div class="ph" style="word-break:break-all">Or open: <code><?= e($url) ?></code></div>
    <div style="margin-top:12px;display:flex;gap:7px;justify-content:center">
      <a class="btn <?= $mode==='write'?'':'ghost' ?> sm"
         href="?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>&mode=write">✍ Writing pad</a>
      <a class="btn <?= $mode==='scan'?'':'ghost' ?> sm"
         href="?<?= $apptId?'appt='.$apptId:'patient='.$pid ?>&mode=scan">📄 Photograph paper</a>
    </div>
    <div class="ph" style="margin-top:10px">Link expires in <?= PAD_TTL_MIN ?> minutes and works once.</div>
  </div>

  <div class="card" id="statusCard">
    <h3>Waiting for the pad…</h3>
    <div class="sub">This page updates by itself when you press Send on the phone</div>
    <div id="waitBox" style="display:flex;flex-direction:column;align-items:center;gap:13px;padding:34px 0">
      <div class="spin"></div>
      <div style="font-size:13px;color:var(--muted)">Nothing received yet</div>
    </div>
    <div id="doneBox" style="display:none"></div>
  </div>
</div>

<form method="post" action="padsave.php" id="saveForm" style="display:none">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="t" value="<?= e($tok) ?>">
  <input type="hidden" name="appt_id" value="<?= $apptId ?>">
  <input type="hidden" name="patient_id" value="<?= $pid ?>">
  <input type="hidden" name="diagnosis" id="dg">
</form>

<style>
.spin{width:38px;height:38px;border:3px solid var(--v-ll);border-top-color:var(--v);
 border-radius:50%;animation:sp .8s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
</style>
<script>
var TOK=<?= json_encode($tok) ?>;
var poll=setInterval(check,1800); check();
function check(){
  fetch('api/padstatus.php?t='+encodeURIComponent(TOK))
   .then(function(r){return r.json();})
   .then(function(j){
     if(j.status!=='done') return;
     clearInterval(poll);
     document.getElementById('waitBox').style.display='none';
     var d=document.getElementById('doneBox');
     d.style.display='block';
     d.innerHTML='<div class="flash-ok">✓ Received from the pad</div>'
       +'<img src="'+j.url+'" style="width:100%;border:1px solid var(--line);border-radius:10px;margin:11px 0">'
       +'<div class="field"><label>Diagnosis (optional — keeps the chart searchable)</label>'
       +'<input id="dgIn" placeholder="e.g. Acute viral fever"></div>'
       +'<button type="button" class="btn wa" id="acceptBtn" style="width:100%;padding:11px">'
       +'Save &amp; send on WhatsApp →</button>';
     document.getElementById('statusCard').querySelector('h3').textContent='Prescription received';
     document.getElementById('acceptBtn').onclick=function(){
       document.getElementById('dg').value=(document.getElementById('dgIn').value||'');
       document.getElementById('saveForm').submit();
     };
   }).catch(function(){});
}
</script>
<?php foot(); ?>
