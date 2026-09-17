<?php
/* Follow-up recall list. The follow_up date was being captured on every
   prescription and never used again — this turns it into actual continuity
   of care (and repeat visits). */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

require_login();

$pdo   = db();
$today = date('Y-m-d');

$rows = $pdo->query("
  SELECT r.id AS rx_id, r.follow_up, r.diagnosis, r.rx_date,
         p.id AS pid, p.name, p.phone, p.age, p.sex, p.lang, p.care, p.risk
  FROM prescriptions r
  JOIN patients p ON p.id = r.patient_id
  WHERE r.follow_up IS NOT NULL AND r.follow_up != ''
    AND r.id = (SELECT MAX(r2.id) FROM prescriptions r2
                WHERE r2.patient_id = r.patient_id AND r2.follow_up != '')
  ORDER BY r.follow_up
")->fetchAll();

$overdue = $due = $upcoming = [];
foreach ($rows as $r) {
    /* already came back after the follow-up was set? then they are done */
    $seen = $pdo->prepare('SELECT COUNT(*) FROM appointments
                           WHERE patient_id=? AND appt_date>=? AND status="Completed"');
    $seen->execute([$r['pid'], $r['follow_up']]);
    if ((int)$seen->fetchColumn() > 0) continue;

    if     ($r['follow_up'] <  $today) $overdue[]  = $r;
    elseif ($r['follow_up'] === $today) $due[]     = $r;
    else                                $upcoming[] = $r;
}

function days_late(string $d): int {
    return (int)((strtotime(date('Y-m-d')) - strtotime($d)) / 86400);
}

/* Pre-built reminder text for the click-to-chat link */
function recall_msg(array $r): string {
    $l = [];
    $l[] = '🏥 *'.clinic('name').'*';
    $l[] = '';
    $l[] = 'Namaste '.$r['name'].',';
    $l[] = '';
    $l[] = 'This is a reminder from '.clinic('doctor').' — your follow-up check-up was due on *'
           .fmt_date($r['follow_up']).'*.';
    if (trim((string)$r['diagnosis']) !== '') {
        $l[] = '';
        $l[] = 'Last visit: '.$r['diagnosis'].' ('.fmt_date($r['rx_date']).')';
    }
    $l[] = '';
    $l[] = 'Please reply with a convenient day and we will book your slot.';
    $l[] = '';
    $l[] = '🕖 OPD: '.clinic('hours');
    $l[] = '📞 '.clinic('phone');
    $l[] = '📍 '.clinic('addr');
    return implode("\n", $l);
}

head('Follow-up Recalls');
?>
<div class="page-h">
  <div><h1>Follow-up recalls</h1>
    <p>Patients who were told to come back and have not returned</p></div>
</div>

<div class="grid kpis" style="margin-bottom:15px">
  <div class="card kpi"><div class="lbl">Overdue</div>
    <div class="val" style="color:var(--red)"><?= count($overdue) ?></div>
    <div class="dl">Missed their date</div></div>
  <div class="card kpi"><div class="lbl">Due Today</div>
    <div class="val" style="color:var(--amber)"><?= count($due) ?></div>
    <div class="dl">Expected today</div></div>
  <div class="card kpi"><div class="lbl">Upcoming</div>
    <div class="val"><?= count($upcoming) ?></div>
    <div class="dl">Scheduled ahead</div></div>
</div>

<?php
function recall_table(string $title, string $sub, array $list, string $tone): void {
    if (!$list) return; ?>
  <div class="card p0" style="margin-bottom:15px;border-left:3px solid <?= $tone ?>">
    <div style="padding:13px 16px 0"><h3><?= e($title) ?></h3><div class="sub"><?= e($sub) ?></div></div>
    <table><thead><tr><th>Patient</th><th>Last visit</th><th>Follow-up was</th>
      <th>Care</th><th style="text-align:right">Action</th></tr></thead><tbody>
    <?php foreach ($list as $r):
      $late = days_late((string)$r['follow_up']); ?>
      <tr>
        <td><div class="person"><div class="av"><?= e(mb_substr($r['name'],0,1)) ?></div>
          <div><a class="pname" href="patient.php?id=<?= (int)$r['pid'] ?>"><?= e($r['name']) ?></a>
          <div class="psub"><?= (int)$r['age'] ?><?= e($r['sex']) ?> · <?= e($r['phone']) ?></div></div></div></td>
        <td style="font-size:12.5px"><?= e($r['diagnosis'] ?: '—') ?>
          <div class="psub"><?= e(fmt_date($r['rx_date'])) ?></div></td>
        <td><b style="font-size:12.5px"><?= e(fmt_date($r['follow_up'])) ?></b>
          <?php if ($late > 0): ?>
            <div class="psub" style="color:var(--red)"><?= $late ?> day<?= $late>1?'s':'' ?> late</div>
          <?php endif; ?></td>
        <td><span class="pill <?= $r['care']==='Home ICU'?'p-red':($r['care']==='Home Care'?'p-v':'p-gray') ?>"><?= e($r['care']) ?></span></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn wa sm" target="_blank" rel="noopener"
             href="<?= e(wa_link((string)$r['phone'], recall_msg($r))) ?>">Remind on WhatsApp</a>
          <a class="btn ghost sm" href="appointment_new.php">Book</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
<?php }

recall_table('Overdue',   'Chase these first — they were expected before today', $overdue, 'var(--red)');
recall_table('Due today', 'Expected in this evening\'s OPD',                     $due,     'var(--amber)');
recall_table('Upcoming',  'Coming up — send a reminder a day before',            $upcoming,'var(--v)');

if (!$overdue && !$due && !$upcoming): ?>
  <div class="card"><div class="empty">No pending follow-ups. Everyone who was asked to return has returned.</div></div>
<?php endif; ?>
<?php foot(); ?>
