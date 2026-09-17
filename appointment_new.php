<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
   require_login();
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $n=(int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn()+1;
  $mode=(string)$_POST['mode'];
  $st=$pdo->prepare('INSERT INTO appointments(patient_id,appt_date,appt_time,visit_type,mode,reason,status,token)
                     VALUES(?,?,?,?,?,?,?,?)');
  $st->execute([(int)$_POST['patient_id'],dnull((string)$_POST['appt_date']),(string)$_POST['appt_time'],
    (string)$_POST['visit_type'],$mode,pf('reason'),'Waiting',
    ($mode==='Teleconsult'?'T-':'A-').str_pad((string)$n,2,'0',STR_PAD_LEFT)]);
  $_SESSION['ok']='Appointment booked.';
  redirect('queue.php?date='.urlencode((string)$_POST['appt_date']));
}
$pts=$pdo->query('SELECT id,name,phone FROM patients ORDER BY name')->fetchAll();
head('New Appointment');
?>
<div class="page-h"><div><h1>Book appointment</h1><p>Evening OPD · <?= e(clinic('hours')) ?></p></div></div>
<div class="card" style="max-width:620px"><form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="field"><label>Patient *</label><select name="patient_id" required>
    <?php foreach($pts as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> — <?= e($p['phone']) ?></option><?php endforeach; ?>
  </select></div>
  <div class="row2">
    <div class="field"><label>Date</label><input type="date" name="appt_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label>Time</label><input type="time" name="appt_time" value="19:00" required></div>
  </div>
  <div class="row2">
    <div class="field"><label>Visit type</label><select name="visit_type">
      <option>New</option><option>Follow Up (1)</option><option>Follow Up (2)</option><option>Follow Up (3)</option></select></div>
    <div class="field"><label>Mode</label><select name="mode"><option>In-clinic</option><option>Teleconsult</option></select></div>
  </div>
  <div class="field"><label>Reason</label><input name="reason" placeholder="Fever, review of reports…"></div>
  <button class="btn">Book appointment</button>
  <a class="btn ghost" href="queue.php">Cancel</a>
</form></div>
<?php foot(); ?>
