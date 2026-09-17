<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
  require_login();
$pdo=db();
$rows=$pdo->query("SELECT h.*,p.name,p.age,p.sex,p.conditions FROM homecare h
                   JOIN patients p ON p.id=h.patient_id WHERE h.status='Active' ORDER BY h.service DESC")->fetchAll();
$icu=count(array_filter($rows,fn($r)=>$r['service']==='Home ICU'));
$mrr=array_sum(array_map(fn($r)=>(int)$r['rate']*30,$rows));
head('Home Care');
?>
<div class="page-h"><div><h1>Home Healthcare &amp; Home ICU</h1>
  <p>Nursing, physiotherapy and doctor-supervised ICU setups across Bhopal</p></div></div>
<div class="grid kpis" style="margin-bottom:15px">
  <div class="card kpi"><div class="lbl">Active Home Patients</div><div class="val"><?= count($rows) ?></div><div class="dl">Across Bhopal</div></div>
  <div class="card kpi"><div class="lbl">Home ICU Setups</div><div class="val"><?= $icu ?></div><div class="dl">Doctor-supervised</div></div>
  <div class="card kpi"><div class="lbl">Monthly Recurring</div><div class="val">₹<?= number_format($mrr/1000,0) ?>k</div><div class="dl">Billing</div></div>
</div>
<div class="grid g3">
<?php foreach($rows as $h): $isIcu=$h['service']==='Home ICU'; ?>
  <div class="card" style="border-left:3px solid <?= $isIcu?'var(--red)':'var(--v)' ?>">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:11px">
      <div class="av"><?= e(mb_substr($h['name'],0,1)) ?></div>
      <div><b style="font-size:14px"><?= e($h['name']) ?></b>
        <div class="psub"><?= (int)$h['age'] ?><?= e($h['sex']) ?> · <?= e(explode(',',(string)$h['conditions'])[0]) ?></div></div>
      <span class="pill <?= $isIcu?'p-red':'p-v' ?>" style="margin-left:auto"><?= e($h['service']) ?></span>
    </div>
    <div style="font-size:11.5px;color:var(--muted);margin-bottom:9px">📍 <?= e($h['addr']) ?></div>
    <div style="background:var(--bg);border-radius:9px;padding:10px;margin-bottom:10px">
      <label style="margin-bottom:6px">Equipment on site</label>
      <?php foreach(array_map('trim',explode(',',(string)$h['equipment'])) as $eq): ?>
        <span class="tag"><?= e($eq) ?></span><?php endforeach; ?></div>
    <div style="font-size:12px;line-height:1.9;margin-bottom:10px">
      <div><span style="color:var(--muted)">Staff</span></div>
      <div style="font-weight:700"><?= e($h['staff']) ?></div>
      <div><span style="color:var(--muted)">Since</span> <b style="float:right"><?= e(date('j M Y',strtotime($h['started']))) ?></b></div>
      <div><span style="color:var(--muted)">Daily rate</span> <b style="float:right">₹<?= number_format((int)$h['rate']) ?></b></div>
    </div>
    <div style="background:<?= $isIcu?'#fdeaea':'var(--v-ll)' ?>;border-radius:8px;padding:9px 11px;font-size:11.5px;line-height:1.5;margin-bottom:11px">
      <b>Clinical note:</b> <?= e($h['note']) ?></div>
    <div style="display:flex;gap:7px;flex-wrap:wrap">
      <?php if (is_doctor()): ?><a class="btn sm" href="consult.php?patient=<?= (int)$h['patient_id'] ?>">Record visit</a><?php endif; ?>
      <a class="btn ghost sm" href="patient.php?id=<?= (int)$h['patient_id'] ?>">Chart</a></div>
  </div>
<?php endforeach; ?>
</div>
<?php foot(); ?>
