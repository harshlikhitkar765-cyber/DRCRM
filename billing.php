<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

require_login();
$pdo=db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    if (($_POST['do']??'')==='pay') {
        $pdo->prepare('UPDATE payments SET paid=1, mode=? WHERE id=?')
            ->execute([(string)$_POST['mode'], (int)$_POST['id']]);
        audit('payment_settle','payment',(int)$_POST['id']);
        $_SESSION['ok']='Marked as paid.';
    } else {
        $pdo->prepare('INSERT INTO payments(patient_id,rx_id,pay_date,item,amount,paid,mode,note)
                       VALUES(?,?,?,?,?,?,?,?)')
            ->execute([(int)$_POST['patient_id'], (int)($_POST['rx_id']?:0) ?: null, dnull((string)$_POST['pay_date']),
                       pf('item'), (int)$_POST['amount'],
                       isset($_POST['paid'])?1:0, (string)$_POST['mode'], pf('note')]);
        audit('payment_add','payment',(int)$pdo->lastInsertId(),(string)$_POST['amount']);
        $_SESSION['ok']='Charge added.';
    }
    redirect('billing.php?date='.urlencode((string)($_POST['pay_date'] ?? date('Y-m-d'))));
}

$date=$_GET['date'] ?? date('Y-m-d');
$rows=$pdo->prepare('SELECT y.*,p.name,p.phone FROM payments y JOIN patients p ON p.id=y.patient_id
                     WHERE y.pay_date=? ORDER BY y.id DESC');
$rows->execute([$date]); $list=$rows->fetchAll();

$collected=array_sum(array_map(fn($r)=>$r['paid']?(int)$r['amount']:0,$list));
$pending  =array_sum(array_map(fn($r)=>$r['paid']?0:(int)$r['amount'],$list));
$mtd=(int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE paid=1
      AND substr(pay_date,1,7)='".substr($date,0,7)."'")->fetchColumn();
$duesAll=(int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE paid=0")->fetchColumn();
$pts=$pdo->query('SELECT id,name FROM patients ORDER BY name')->fetchAll();

head('Billing');
?>
<div class="page-h"><div><h1>Billing &amp; collection</h1>
  <p>Day book for <?= e(date('l, j F Y', strtotime($date))) ?></p></div>
  <div class="spacer"></div>
  <form method="get" style="display:flex;gap:7px"><input type="date" name="date" value="<?= e($date) ?>">
    <button class="btn ghost sm">Go</button></form></div>

<div class="grid kpis" style="margin-bottom:15px">
  <div class="card kpi"><div class="lbl">Collected Today</div>
    <div class="val" style="color:var(--green)">₹<?= number_format($collected) ?></div>
    <div class="dl"><?= count(array_filter($list,fn($r)=>$r['paid'])) ?> paid</div></div>
  <div class="card kpi"><div class="lbl">Pending Today</div>
    <div class="val" style="color:var(--amber)">₹<?= number_format($pending) ?></div>
    <div class="dl">Not yet settled</div></div>
  <div class="card kpi"><div class="lbl">Month to Date</div>
    <div class="val">₹<?= number_format($mtd) ?></div><div class="dl"><?= date('F Y', strtotime($date)) ?></div></div>
  <div class="card kpi"><div class="lbl">Total Outstanding</div>
    <div class="val" style="color:var(--red)">₹<?= number_format($duesAll) ?></div>
    <div class="dl">All time</div></div>
</div>

<div class="grid g2" style="align-items:start">
  <div class="card p0">
    <div style="padding:13px 16px 0"><h3>Day book</h3><div class="sub"><?= count($list) ?> entries</div></div>
    <table><thead><tr><th>Patient</th><th>For</th><th style="text-align:right">Amount</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if(!$list): ?><tr><td colspan="5" class="empty">No charges recorded for this day.</td></tr><?php endif; ?>
    <?php foreach($list as $r): ?>
      <tr>
        <td><a class="pname" href="patient.php?id=<?= (int)$r['patient_id'] ?>"><?= e($r['name']) ?></a></td>
        <td style="font-size:12.5px"><?= e($r['item']) ?>
          <?php if(trim((string)$r['note'])!==''): ?><div class="psub"><?= e($r['note']) ?></div><?php endif; ?></td>
        <td style="text-align:right"><b>₹<?= number_format((int)$r['amount']) ?></b></td>
        <td><span class="pill <?= $r['paid']?'p-green':'p-amber' ?>"><?= $r['paid']?e($r['mode']):'Pending' ?></span></td>
        <td style="text-align:right">
          <?php if(!$r['paid']): ?>
            <form method="post" style="display:flex;gap:5px;justify-content:flex-end">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="do" value="pay"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="pay_date" value="<?= e($date) ?>">
              <select name="mode" style="padding:5px;font-size:12px"><option>Cash</option><option>UPI</option><option>Card</option></select>
              <button class="btn sm">Settle</button></form>
          <?php else: ?><span class="psub">✓</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="card"><h3>Add a charge</h3><div class="sub">Consultation, procedure, home visit…</div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field"><label>Patient</label><select name="patient_id" required>
        <?php foreach($pts as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="row2">
        <div class="field"><label>Date</label><input type="date" name="pay_date" value="<?= e($date) ?>" required></div>
        <div class="field"><label>Amount (₹)</label><input type="number" name="amount" min="0" value="400" required></div>
      </div>
      <div class="field"><label>For</label>
        <input name="item" list="items" value="Consultation" required>
        <datalist id="items"><option>Consultation</option><option>Follow-up consultation</option>
          <option>Home visit</option><option>Home ICU - daily</option><option>Home nursing - daily</option>
          <option>Injection / procedure</option><option>Dressing</option><option>ECG</option></datalist></div>
      <div class="row2">
        <div class="field"><label>Mode</label><select name="mode">
          <option>Cash</option><option>UPI</option><option>Card</option></select></div>
        <div class="field" style="display:flex;align-items:flex-end">
          <label style="display:flex;gap:7px;align-items:center;font-size:13px;text-transform:none;color:var(--navy)">
            <input type="checkbox" name="paid" checked style="width:auto"> Already paid</label></div>
      </div>
      <div class="field"><label>Note</label><input name="note" placeholder="Optional"></div>
      <button class="btn">Add charge</button>
    </form>
  </div>
</div>
<?php foot(); ?>
