<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
  require_login();
$pdo=db(); $qs=trim((string)($_GET['q']??''));
$sql='SELECT * FROM patients'; $args=[];
if($qs!==''){ $sql.=' WHERE name LIKE ? OR phone LIKE ? OR conditions LIKE ?'; $args=["%$qs%","%$qs%","%$qs%"]; }
$sql.=' ORDER BY name';
$st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll();
head('Patients');
?>
<div class="page-h"><div><h1>Patients</h1><p><?= count($rows) ?> records · ABHA linked</p></div>
  <div class="spacer"></div><a class="btn" href="patient_new.php">+ New Patient</a></div>
<div class="card p0">
  <form method="get" style="padding:13px 16px;display:flex;gap:9px">
    <input name="q" value="<?= e($qs) ?>" placeholder="Search name, phone or condition" style="max-width:330px">
    <button class="btn ghost sm">Search</button>
    <?php if($qs!==''): ?><a class="btn ghost sm" href="patients.php">Clear</a><?php endif; ?>
  </form>
  <table><thead><tr><th>Patient</th><th>Contact</th><th>Conditions</th><th>Care</th><th>Risk</th><th>Lang</th><th style="text-align:right">Action</th></tr></thead><tbody>
  <?php if(!$rows): ?><tr><td colspan="7" class="empty">No patients found.</td></tr><?php endif; ?>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><div class="person"><div class="av"><?= e(mb_substr($r['name'],0,1)) ?></div>
        <div><a class="pname" href="patient.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a>
        <div class="psub"><?= (int)$r['age'] ?><?= e($r['sex']) ?> · <?= e($r['city']) ?></div></div></div></td>
      <td style="font-size:12.5px"><?= e($r['phone']) ?></td>
      <td><?php foreach(array_filter(array_map('trim',explode(',',(string)$r['conditions']))) as $c): ?>
        <span class="tag"><?= e($c) ?></span><?php endforeach; ?></td>
      <td><span class="pill <?= $r['care']==='Home ICU'?'p-red':($r['care']==='Home Care'?'p-v':'p-gray') ?>"><?= e($r['care']) ?></span></td>
      <td><span class="pill <?= $r['risk']==='High'?'p-red':($r['risk']==='Medium'?'p-amber':'p-green') ?>"><?= e($r['risk']) ?></span></td>
      <td style="font-size:12px"><?= e($r['lang']) ?></td>
      <td style="text-align:right;white-space:nowrap">
        <?php if (is_doctor()): ?><a class="btn sm" href="consult.php?patient=<?= (int)$r['id'] ?>">Consult</a><?php endif; ?>
        <a class="btn ghost sm" href="patient.php?id=<?= (int)$r['id'] ?>">Chart</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php foot(); ?>
