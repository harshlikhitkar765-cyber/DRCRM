<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
   require_login();
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $patientId = pint('patient_id');
  $date = dnull(pf('appt_date'));
  $time = pf('appt_time');
  $mode = pf('mode') === 'Teleconsult' ? 'Teleconsult' : 'In-clinic';
  $visitType = pf('visit_type');
  if (!in_array($visitType, ['New','Follow Up (1)','Follow Up (2)','Follow Up (3)'], true)) $visitType = 'New';

  $exists = $pdo->prepare('SELECT id FROM patients WHERE id=?'); $exists->execute([$patientId]);
  $parsedDate = $date === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $date);
  $validDate = $parsedDate !== false && $parsedDate->format('Y-m-d') === $date;
  $validTime = (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
  if (!$exists->fetchColumn() || !$validDate || !$validTime) {
    $_SESSION['err'] = 'Choose a patient, a valid date and a valid time.';
    redirect('appointment_new.php');
  }

  /* Tokens restart each day and stay distinct between in-clinic and phone
     visits. The database unique key plus a short retry prevents two reception
     desks booking the same next token concurrently. */
  $prefix = $mode === 'Teleconsult' ? 'T-' : 'A-';
  $newId = 0;
  for ($attempt = 0; $attempt < 4 && !$newId; $attempt++) {
    try {
      $pdo->beginTransaction();
      $next = $pdo->prepare('SELECT COALESCE(MAX(CAST(SUBSTRING(token,3) AS UNSIGNED)),0)
                             FROM appointments WHERE appt_date=? AND token LIKE ?');
      $next->execute([$date, $prefix.'%']);
      $token = $prefix . str_pad((string)((int)$next->fetchColumn() + 1), 2, '0', STR_PAD_LEFT);
      $st = $pdo->prepare('INSERT INTO appointments(patient_id,appt_date,appt_time,visit_type,mode,reason,status,token)
                           VALUES(?,?,?,?,?,?,?,?)');
      $st->execute([$patientId, $date, $time, $visitType, $mode, pf('reason'), 'Waiting', $token]);
      $newId = (int)$pdo->lastInsertId();
      $pdo->commit();
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      /* 23000 is the expected duplicate-key race; select a fresh token. */
      if ($e->getCode() !== '23000') throw $e;
    }
  }
  if (!$newId) {
    $_SESSION['err'] = 'Another appointment was booked at the same time. Please try again.';
    redirect('appointment_new.php?patient='.$patientId);
  }
  audit('appointment_add', 'appointment', $newId, $date.' '.$time);
  $_SESSION['ok']='Appointment booked.';
  redirect('queue.php?date='.urlencode($date));
}
$selectedId = gi('patient');
$pts=$pdo->query('SELECT id,name,phone FROM patients ORDER BY name')->fetchAll();
head('New Appointment');
?>
<div class="page-h"><div><h1>Book appointment</h1><p>Evening OPD · <?= e(clinic('hours')) ?></p></div></div>
<div class="card" style="max-width:620px"><form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="field"><label>Patient *</label><select name="patient_id" required>
    <?php foreach($pts as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $selectedId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> — <?= e($p['phone']) ?></option><?php endforeach; ?>
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
