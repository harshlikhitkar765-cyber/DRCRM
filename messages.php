<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
   require_doctor();
$pdo=db();
$rows=$pdo->query('SELECT w.*,p.name FROM wa_messages w JOIN patients p ON p.id=w.patient_id
                   ORDER BY w.id DESC LIMIT 100')->fetchAll();
$tot=(int)$pdo->query('SELECT COUNT(*) FROM wa_messages')->fetchColumn();
head('WhatsApp Log');
?>
<div class="page-h"><div><h1>WhatsApp log</h1>
  <p><?= $tot ?> message(s) sent from <?= e(clinic('name')) ?> · <?= e(clinic('phone')) ?></p></div></div>
<div class="card p0"><table>
<thead><tr><th>Patient</th><th>Phone</th><th>Language</th><th>Mode</th><th>Status</th><th>Sent</th><th style="text-align:right"></th></tr></thead>
<tbody>
<?php if(!$rows): ?><tr><td colspan="7" class="empty">No messages sent yet.</td></tr><?php endif; ?>
<?php foreach($rows as $w): ?>
  <tr>
    <td><a class="pname" href="patient.php?id=<?= (int)$w['patient_id'] ?>"><?= e($w['name']) ?></a></td>
    <td style="font-size:12.5px"><?= e($w['phone']) ?></td>
    <td><span class="pill p-v"><?= e($w['lang']) ?></span></td>
    <td style="font-size:12px"><?= e($w['driver']==='cloud'?'Cloud API':'Click-to-chat') ?></td>
    <td><span class="pill <?= $w['status']==='Sent'?'p-green':($w['status']==='Failed'?'p-red':'p-amber') ?>"><?= e($w['status']) ?></span></td>
    <td style="font-size:12px"><?= e($w['sent_at']) ?></td>
    <td style="text-align:right"><?php if($w['rx_id']): ?>
      <a class="btn ghost sm" href="send.php?rx=<?= (int)$w['rx_id'] ?>">Resend</a><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php foot(); ?>
