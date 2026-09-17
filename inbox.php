<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

  require_login();
$pdo=db();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $pdo->prepare('UPDATE wa_replies SET handled=1 WHERE id=?')->execute([(int)$_POST['id']]);
    audit('reply_handled','wa_reply',(int)$_POST['id']);
    redirect('inbox.php');
}
$rows=$pdo->query('SELECT r.*,p.name FROM wa_replies r LEFT JOIN patients p ON p.id=r.patient_id
                   ORDER BY r.handled, r.id DESC LIMIT 100')->fetchAll();
$open=count(array_filter($rows,fn($r)=>!$r['handled']));
$badge=['confirm'=>'p-green','reschedule'=>'p-amber','optout'=>'p-red','help'=>'p-v','other'=>'p-gray'];
head('Patient Replies');
?>
<div class="page-h"><div><h1>Patient replies</h1>
  <p><?= $open ?> needing attention · incoming WhatsApp messages</p></div></div>

<?php if (WA['driver']!=='cloud'): ?>
  <div class="card" style="background:#fff6e5;border:1px solid var(--amber)">
    <b style="font-size:13px">⚠ Replies are not being received yet</b>
    <div class="ph" style="margin-top:5px">Your templates say “Reply <b>1</b> to confirm”, but click-to-chat
      mode cannot receive messages — replies land in your personal WhatsApp, not here.
      To make this real, switch <code>WA['driver']</code> to <code>cloud</code> and point the Meta webhook at
      <code>webhook.php</code>. Until then, either watch your phone or remove those lines in
      <a href="templates.php">Templates</a>.</div>
  </div>
<?php endif; ?>

<div class="card p0"><table>
<thead><tr><th>Patient</th><th>Message</th><th>Meaning</th><th>When</th><th style="text-align:right"></th></tr></thead>
<tbody>
<?php if(!$rows): ?><tr><td colspan="5" class="empty">No replies received.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?>
  <tr style="<?= $r['handled']?'opacity:.5':'' ?>">
    <td><?php if($r['patient_id']): ?>
      <a class="pname" href="patient.php?id=<?= (int)$r['patient_id'] ?>"><?= e($r['name']) ?></a>
    <?php else: ?><span class="psub">Unknown</span><?php endif; ?>
      <div class="psub"><?= e($r['phone']) ?></div></td>
    <td style="font-size:12.5px"><?= e($r['body']) ?></td>
    <td><span class="pill <?= $badge[$r['intent']] ?? 'p-gray' ?>"><?= e(ucfirst($r['intent'])) ?></span></td>
    <td style="font-size:12px"><?= e($r['received_at']) ?></td>
    <td style="text-align:right;white-space:nowrap">
      <?php if(!$r['handled']): ?>
        <a class="btn ghost sm" href="appointment_new.php">Book</a>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn sm">Done</button></form>
      <?php else: ?><span class="psub">✓ handled</span><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php foot(); ?>
