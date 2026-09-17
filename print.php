<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

app_session_start(); if(empty($_SESSION['user'])) redirect('login.php');
$pdo=db(); $id=gi('rx');
$q=$pdo->prepare('SELECT r.*,p.name,p.age,p.sex,p.abha,p.phone,p.allergies
                  FROM prescriptions r JOIN patients p ON p.id=r.patient_id WHERE r.id=?');
$q->execute([$id]); $r=$q->fetch();
if(!$r){ http_response_code(404); exit('Not found'); }
$meds=json_decode((string)$r['meds'],true)?:[]; $labs=json_decode((string)$r['labs'],true)?:[];
$v=json_decode((string)$r['vitals'],true)?:[];

/* The recorded consultation, if there was one. Its main points go on the
   prescription so the patient takes home what was actually discussed. */
$nq=$pdo->prepare('SELECT summary,disease,pt_points,secs,started_at
                   FROM consult_notes WHERE rx_id=? ORDER BY id DESC LIMIT 1');
$nq->execute([$id]); $note=$nq->fetch() ?: null;
$sum = $note ? (json_decode((string)$note['summary'], true) ?: []) : [];
$ptp = $note ? (json_decode((string)$note['pt_points'], true) ?: []) : [];

/* Complaints in the patient's own words. Prefer the structured list. */
$complaints = '';
if (!empty($sum['complaints'])) {
    $complaints = implode(', ', $sum['complaints']);
    if (!empty($sum['duration'])) $complaints .= ' — since '.$sum['duration'];
}
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>Prescription — <?= e($r['name']) ?></title>
<style>
/* ==================================================================
   Printed prescription — designed for A4 paper, not for a screen.

   The old sheet used Georgia at 11.5px: fine on a monitor, small and
   dated on paper, and a pharmacist reading a drug name at arm's length
   is exactly who this is for. Rebuilt around print units (mm, pt),
   a clear typographic hierarchy, and a medicine table that can be read
   across a counter.
   ================================================================== */

@page { size: A4; margin: 12mm 12mm 16mm; }

:root{
  --ink:#111827; --soft:#4b5563; --faint:#9ca3af;
  --line:#d1d5db; --hair:#e5e7eb; --brand:#4f46e5;
}

*{ box-sizing:border-box; }

body{
  /* A humanist sans reads better on paper at small sizes than a serif,
     and stays legible on a cheap inkjet. */
  font-family:"Inter","Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  font-size:10.5pt; line-height:1.45; color:var(--ink);
  margin:0 auto; padding:14mm; max-width:210mm;
  -webkit-font-smoothing:antialiased;
  font-variant-numeric:tabular-nums;   /* doses and numbers line up */
}

/* ---------- letterhead ---------- */
.hd{ display:flex; gap:11px; align-items:flex-start;
     border-bottom:2.5pt solid var(--brand); padding-bottom:8pt; margin-bottom:10pt; }
.lg{ width:13mm; height:13mm; border-radius:3mm; background:var(--brand); color:#fff;
     display:grid; place-items:center; font-weight:800; font-size:17pt; flex:none;
     letter-spacing:-.02em; }
.cn{ font-size:16pt; font-weight:800; letter-spacing:-.02em; line-height:1.15; }
.cd{ font-size:9.5pt; color:var(--soft); margin-top:1pt; }
.cr{ font-size:8pt; color:var(--faint); letter-spacing:.02em; margin-top:1pt; }
.meta{ margin-left:auto; text-align:right; font-size:8.5pt; color:var(--soft); line-height:1.7; }
.meta b{ color:var(--ink); font-size:9.5pt; }

/* ---------- patient strip ---------- */
.pi{ display:flex; flex-wrap:wrap; gap:4mm 9mm; font-size:10pt;
     background:#f9fafb; border:0.6pt solid var(--hair); border-radius:2mm;
     padding:5pt 8pt; margin-bottom:9pt; }
.pi .nm{ font-weight:700; font-size:11.5pt; letter-spacing:-.01em; }
.pi .lbl{ color:var(--faint); font-size:8pt; text-transform:uppercase;
          letter-spacing:.08em; display:block; }

/* ---------- allergy warning: must be impossible to miss ---------- */
.warn{ background:#fef2f2; border:1pt solid #fca5a5; border-left:3pt solid #dc2626;
       color:#991b1b; padding:6pt 9pt; border-radius:2mm;
       font-size:10pt; font-weight:700; margin-bottom:9pt; }

/* ---------- section headings ---------- */
h4{ font-size:8.5pt; font-weight:800; letter-spacing:.1em; text-transform:uppercase;
    color:var(--brand); margin:10pt 0 3pt; }

.body-line{ font-size:10.5pt; }
.dx{ font-size:13pt; font-weight:700; letter-spacing:-.015em; margin-bottom:2pt; }

/* ---------- the Rx table ---------- */
.rx-mark{ font-size:19pt; font-weight:800; color:var(--brand);
          font-family:Georgia,serif; line-height:1; margin:11pt 0 2pt; }
table{ width:100%; border-collapse:collapse; margin-top:3pt; }
th{ text-align:left; font-size:7.5pt; font-weight:800; letter-spacing:.09em;
    text-transform:uppercase; color:var(--faint);
    border-bottom:1pt solid var(--line); padding:3pt 5pt 3pt 0; }
td{ border-bottom:0.6pt solid var(--hair); padding:5pt 5pt 5pt 0; vertical-align:top; }
tbody tr:last-child td{ border-bottom:1pt solid var(--line); }
/* the drug name is the single most important thing on the page */
.med-n{ font-size:11pt; font-weight:700; letter-spacing:-.01em; }
.med-note{ font-size:8.5pt; color:var(--soft); font-style:italic; margin-top:1pt; }
.med-num{ color:var(--faint); font-size:9pt; width:7mm; }
td.dose{ font-weight:600; white-space:nowrap; }

/* ---------- advice ---------- */
ul{ margin:2pt 0 0; padding-left:5mm; }
li{ font-size:10pt; margin-bottom:1.5pt; }

/* ---------- signature ---------- */
.sig{ margin-top:16mm; display:flex; justify-content:flex-end; }
.sig div{ border-top:0.8pt solid var(--ink); padding-top:4pt; min-width:58mm;
          text-align:center; font-size:10pt; font-weight:700; }
.sig span{ display:block; font-weight:400; font-size:8.5pt; color:var(--soft); margin-top:1pt; }

/* ---------- footer ---------- */
.foot{ margin-top:8mm; padding-top:4pt; border-top:0.6pt solid var(--hair);
       font-size:7.5pt; color:var(--faint); text-align:center; line-height:1.6; }

/* ---------- print behaviour ---------- */
@media print{
  body{ padding:0; max-width:none; }
  .np{ display:none !important; }
  /* never split a medicine row or a section across two pages */
  tr, li, .warn, .pi{ break-inside:avoid; page-break-inside:avoid; }
  thead{ display:table-header-group; }   /* repeat headers on page 2 */
  .sig{ break-inside:avoid; }
  a[href]::after{ content:""; }
}
</style></head><body>
<div class="np" style="margin-bottom:14px;font-family:sans-serif">
  <button onclick="window.print()" style="padding:8px 15px;border:0;background:#4f46e5;color:#fff;border-radius:7px;font-weight:700;cursor:pointer">⎙ Print</button>
  <a href="patient.php?id=<?= (int)$r['patient_id'] ?>" style="margin-left:8px;font-size:13px;color:#4f46e5">← Back</a>
</div>
<div class="hd">
  <div class="lg">B</div>
  <div><div class="cn"><?= e(clinic('name')) ?></div>
    <div class="cd"><?= e(clinic('doctor')) ?> · <?= e(clinic('qual')) ?></div>
    <div class="cr">Reg <?= e(clinic('reg')) ?> · <?= e(clinic('spec')) ?></div></div>
  <div class="meta"><?= e(clinic('addr')) ?><br><?= e(clinic('phone')) ?><br>
    OPD: <?= e(clinic('hours')) ?><br><b><?= e(fmt_date($r['rx_date'])) ?></b></div>
</div>
<div class="pi">
  <div><span class="lbl">Patient</span><span class="nm"><?= e($r['name']) ?></span></div>
  <div><span class="lbl">Age / Sex</span><?= (int)$r['age'] ?> <?= e($r['sex']) ?></div>
  <?php if (trim((string)$r['abha']) !== ''): ?>
    <div><span class="lbl">ABHA</span><?= e($r['abha']) ?></div>
  <?php endif; ?>
  <div><span class="lbl">Phone</span><?= e($r['phone']) ?></div>
  <div><span class="lbl">Rx No.</span><?= str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT) ?></div>
</div>
<?php if(trim((string)$r['allergies'])!==''): ?>
  <div class="warn">⚠ Allergies: <?= e($r['allergies']) ?></div><?php endif; ?>
<?php if(format_vitals($v)!=='—'): ?><h4>Vitals</h4>
  <div class="body-line"><?= e(format_vitals($v)) ?></div><?php endif; ?>
<?php if($complaints!==''): ?><h4>Complaints</h4>
  <div class="body-line"><?= e($complaints) ?></div><?php endif; ?>
<h4>Diagnosis</h4><div class="dx"><?= e($r['diagnosis']) ?></div>
<div class="rx-mark">&#8478;</div>
<table>
  <thead><tr>
    <th style="width:7mm"></th>
    <th>Medicine</th>
    <th style="width:18mm">Dose</th>
    <th style="width:26mm">When</th>
    <th style="width:16mm">Freq</th>
    <th style="width:20mm">Duration</th>
  </tr></thead>
  <tbody>
  <?php if (!$meds): ?>
    <tr><td colspan="6" style="color:#9ca3af">No medicines prescribed</td></tr>
  <?php endif; ?>
  <?php foreach ($meds as $i => $m): ?>
    <tr>
      <td class="med-num"><?= $i + 1 ?>.</td>
      <td>
        <div class="med-n"><?= e($m['name']) ?></div>
        <?php if (trim((string)($m['notes'] ?? '')) !== ''): ?>
          <div class="med-note"><?= e($m['notes']) ?></div>
        <?php endif; ?>
      </td>
      <td class="dose"><?= e(trim(($m['dose'] ?? '') . ' ' . ($m['unit'] ?? ''))) ?></td>
      <td><?= e($m['when'] ?? '') ?></td>
      <td class="dose"><?= e($m['freq'] ?? '') ?></td>
      <td class="dose"><?= e($m['duration'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if($labs): ?><h4>Investigations advised</h4><div class="body-line"><?= e(implode(', ', $labs)) ?></div><?php endif; ?>
<?php if(trim((string)$r['advice'])!==''):
  $adv=array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$r['advice'])))); ?>
  <h4>Advice</h4>
  <?php if(count($adv)>1): ?>
    <ul style="margin:0;padding-left:16px;font-size:12px;line-height:1.6">
      <?php foreach($adv as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
  <?php else: ?>
    <div style="font-size:12px"><?= e($r['advice']) ?></div>
  <?php endif; ?>
<?php endif; ?>
<?php if(trim((string)$r['follow_up'])!==''): ?><h4>Follow-up</h4><div class="body-line"><b><?= e(fmt_date($r['follow_up'])) ?></b></div><?php endif; ?>
<?php /* Only worth printing when it adds something beyond the fields above:
         a recorded visit has the patient's own words; a typed one does not. */
      $isRec = !empty($ptp) || !empty($sum['patientSaid']);
      if ($isRec): ?>
<div class="np" style="margin-top:12px;border:1px solid #d5dde3;border-radius:6px;padding:8px 10px">
  <div style="font-size:9.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
              color:#667;margin-bottom:4px">Discussed in the consultation</div>
  <?php if(!empty($sum['disease'])): ?>
    <div style="font-size:11.5px;margin-bottom:3px"><b><?= e($sum['disease']) ?></b></div>
  <?php endif; ?>
  <?php if($ptp): ?>
    <ul style="margin:0;padding-left:15px;font-size:11px;line-height:1.5;color:#333">
      <?php foreach(array_slice($ptp,0,5) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if(!empty($sum['when'])): ?>
    <div style="font-size:9.5px;color:#888;margin-top:4px">
      Recorded <?= e($sum['when']) ?><?= !empty($sum['took'])?' · '.e($sum['took']):'' ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="sig"><div><?= e(clinic('doctor')) ?><br><span style="font-size:10px;color:#666"><?= e(clinic('qual')) ?></span></div></div>
<div class="foot">
  <?= e(setting('rx_footer', 'This prescription is valid only for the named patient.')) ?><br>
  <?= e(clinic('name')) ?> &middot; <?= e(clinic('addr')) ?> &middot; <?= e(clinic('phone')) ?>
</div>
</body></html>
