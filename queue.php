<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

/* Housekeeping runs itself on the first queue load of the day: expired
   pad sessions cleared, yesterday's untouched appointments closed. No
   cron needed, though one can still be used. */
$autoDid = auto_run();

require_login();

$pdo = db();
$date = $_GET['date'] ?? date('Y-m-d');
$tab  = $_GET['tab']  ?? 'Queue';
$qs   = trim((string)($_GET['q'] ?? ''));

$statusFilter = match($tab){
    'Finished'  => "a.status='Completed'",
    'Cancelled' => "a.status='Cancelled'",
    default     => "a.status NOT IN ('Completed','Cancelled')",
};
$sql = "SELECT a.*, p.name, p.age, p.sex, p.phone, p.conditions, p.care
        FROM appointments a JOIN patients p ON p.id=a.patient_id
        WHERE a.appt_date=? AND $statusFilter";
$args = [$date];
if ($qs !== '') { $sql .= " AND p.name LIKE ?"; $args[] = "%$qs%"; }
$sql .= " ORDER BY a.appt_time";
$rows = $pdo->prepare($sql); $rows->execute($args); $rows = $rows->fetchAll();

$cnt = fn(string $w) => (int)$pdo->query(
    "SELECT COUNT(*) FROM appointments WHERE appt_date=".$pdo->quote($date)." AND $w")->fetchColumn();
$counts = [
    'Queue'     => $cnt("status NOT IN ('Completed','Cancelled')"),
    'Finished'  => $cnt("status='Completed'"),
    'Cancelled' => $cnt("status='Cancelled'"),
];
$hcActive = (int)$pdo->query("SELECT COUNT(*) FROM homecare WHERE status='Active'")->fetchColumn();

head('OPD Queue');
?>
<div class="banner">
  <div>
    <h2>Good evening, <?= e(clinic('doctor')) ?></h2>
    <p>Evening OPD · <?= e(clinic('hours')) ?></p>
    <div class="wmeta">
      <span>📍 <?= e(clinic('addr')) ?></span>
      <span>📞 <?= e(clinic('phone')) ?></span>
      <span>🏠 <?= $hcActive ?> home care patients active</span>
    </div>
  </div>
  <div class="acts">
    <a class="btn" href="appointment_new.php">+ Add Appointment</a>
    <a class="btn ghost" href="patients.php">Patient Records</a>
  </div>
</div>

<div class="card p0">
  <div style="display:flex;gap:20px;border-bottom:1px solid var(--line);padding:0 16px">
    <?php foreach ($counts as $k=>$v): ?>
      <a href="?tab=<?= $k ?>&date=<?= e($date) ?>"
         style="padding:12px 2px;font-size:13px;font-weight:700;border-bottom:2.5px solid <?= $tab===$k?'var(--v)':'transparent' ?>;color:<?= $tab===$k?'var(--v)':'var(--muted)' ?>;margin-bottom:-1px">
        <?= e($k) ?> (<?= $v ?>)</a>
    <?php endforeach; ?>
  </div>

  <form method="get" style="display:flex;gap:9px;align-items:center;padding:13px 16px;flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input name="q" value="<?= e($qs) ?>" placeholder="Search by patient name" style="max-width:290px">
    <div class="spacer"></div>
    <input type="date" name="date" value="<?= e($date) ?>" style="width:auto">
    <button class="btn ghost sm">Filter</button>
    <a class="btn ghost sm" href="?tab=<?= e($tab) ?>">Today</a>
  </form>

  <table class="stack">
    <thead><tr><th style="width:40px">#</th><th>Name</th><th>Contact</th><th>Visit Type</th>
      <th>Slot</th><th>Care</th><th style="text-align:right">Action</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7" class="empty">No appointments in <?= e(strtolower($tab)) ?> for this date.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i=>$r): ?>
      <tr>
        <td class="q-num" data-l=""><?= $i+1 ?></td>
        <td class="q-name" data-l="">
          <a class="pname" href="patient.php?id=<?= (int)$r['patient_id'] ?>"><?= e($r['name']) ?></a>
          <div class="psub"><?= $r['sex']==='F'?'Female':'Male' ?>, <?= (int)$r['age'] ?>y</div>
        </td>
        <td data-l="Contact" style="font-size:12.5px"><?= e($r['phone']) ?></td>
        <td data-l="Visit"><?= e($r['visit_type']) ?><div class="psub"><?= e($r['mode']) ?></div></td>
        <td data-l="Slot"><b><?= e($r['appt_time']) ?></b><div class="psub"><?= e(date('j M Y', strtotime($r['appt_date']))) ?></div></td>
        <td data-l="Care"><span class="pill <?= $r['care']==='Home ICU'?'p-red':($r['care']==='Home Care'?'p-v':'p-gray') ?>"><?= e($r['care']) ?></span></td>
        <td class="q-act" data-l="" style="text-align:right;white-space:nowrap">
          <?php if ($r['status']==='Completed'): ?>
            <span class="pill p-green">Done</span>
            <a class="btn ghost sm" href="patient.php?id=<?= (int)$r['patient_id'] ?>">Chart</a>
          <?php elseif ($r['status']==='Cancelled'): ?>
            <span class="pill p-gray">Cancelled</span>
          <?php else: ?>
            <a class="btn sm" href="consult.php?appt=<?= (int)$r['id'] ?>">Consult</a>
            <a class="btn ghost sm" href="padlink.php?appt=<?= (int)$r['id'] ?>" title="Write on your phone or tablet">📱 Pad</a>
            <a class="btn ghost sm" href="consult.php?appt=<?= (int)$r['id'] ?>&prev=1" title="Repeat the last prescription">⟲ Repeat</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php foot(); ?>
