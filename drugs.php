<?php
/* The doctor's own formulary: medicines, lab tests and favourite prescription sets. */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

 require_login();
$pdo=db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $do=(string)($_POST['do']??'');
    if ($do==='drug_add') {
        $pdo->prepare('INSERT INTO drugs(name,generic,form,strength,def_dose,def_unit,def_when,def_freq,def_duration,notes)
                       VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([pf('name'),strtolower(pf('generic')),
                       (string)$_POST['form'],pf('strength'),
                       pf('def_dose'),pf('def_unit'),
                       (string)$_POST['def_when'],(string)$_POST['def_freq'],
                       pf('def_duration'),pf('notes')]);
        audit('drug_add','drug',(int)$pdo->lastInsertId(),(string)$_POST['name']);
        $_SESSION['ok']='Medicine added to your list.';
    } elseif ($do==='drug_edit') {
        $pdo->prepare('UPDATE drugs SET name=?,generic=?,form=?,strength=?,def_dose=?,def_unit=?,
                       def_when=?,def_freq=?,def_duration=?,notes=? WHERE id=?')
            ->execute([pf('name'),strtolower(pf('generic')),
                       (string)$_POST['form'],pf('strength'),
                       pf('def_dose'),pf('def_unit'),
                       (string)$_POST['def_when'],(string)$_POST['def_freq'],
                       pf('def_duration'),pf('notes'),(int)$_POST['id']]);
        audit('drug_edit','drug',(int)$_POST['id']);
        $_SESSION['ok']='Medicine updated.';
    } elseif ($do==='drug_del') {
        $pdo->prepare('UPDATE drugs SET active=0 WHERE id=?')->execute([(int)$_POST['id']]);
        audit('drug_remove','drug',(int)$_POST['id']);
        $_SESSION['ok']='Medicine removed from the list.';
    } elseif ($do==='lab_add') {
        $pdo->prepare('INSERT IGNORE INTO labs(name,grp) VALUES(?,?)')
            ->execute([pf('lname'),pf('grp') ?: 'General']);
        $_SESSION['ok']='Test added.';
    } elseif ($do==='lab_del') {
        $pdo->prepare('UPDATE labs SET active=0 WHERE id=?')->execute([(int)$_POST['id']]);
        $_SESSION['ok']='Test removed.';
    } elseif ($do==='set_del') {
        $pdo->prepare('DELETE FROM rx_sets WHERE id=?')->execute([(int)$_POST['id']]);
        $_SESSION['ok']='Favourite removed.';
    }
    redirect('drugs.php?tab='.urlencode((string)($_POST['tab']??'drugs')));
}

$tab=$_GET['tab'] ?? 'drugs';
$qs=trim((string)($_GET['q']??''));
$dsql='SELECT * FROM drugs WHERE active=1'; $args=[];
if($qs!==''){ $dsql.=' AND (name LIKE ? OR generic LIKE ?)'; $args=["%$qs%","%$qs%"]; }
$dsql.=' ORDER BY uses DESC, name';
$ds=$pdo->prepare($dsql); $ds->execute($args); $drugs=$ds->fetchAll();
$labs=$pdo->query('SELECT * FROM labs WHERE active=1 ORDER BY grp, name')->fetchAll();
$sets=$pdo->query('SELECT * FROM rx_sets ORDER BY uses DESC, name')->fetchAll();
$edit=null;
if(isset($_GET['edit'])){ $e=$pdo->prepare('SELECT * FROM drugs WHERE id=?'); $e->execute([(int)$_GET['edit']]); $edit=$e->fetch(); }

head('Drug Database');
?>
<div class="page-h"><div><h1>My drug database</h1>
  <p>Your own medicines, tests and favourite prescriptions — used everywhere in the app</p></div></div>

<div style="display:flex;gap:7px;margin-bottom:15px">
  <a class="btn <?= $tab==='drugs'?'':'ghost' ?> sm" href="?tab=drugs">💊 Medicines (<?= count($drugs) ?>)</a>
  <a class="btn <?= $tab==='labs'?'':'ghost' ?> sm" href="?tab=labs">🧪 Tests (<?= count($labs) ?>)</a>
  <a class="btn <?= $tab==='sets'?'':'ghost' ?> sm" href="?tab=sets">⭐ Favourites (<?= count($sets) ?>)</a>
</div>

<?php if($tab==='drugs'): ?>
<div class="grid g2" style="align-items:start">
  <div class="card p0">
    <div style="padding:13px 16px 0"><h3>Medicines</h3>
      <div class="sub">Most used first — these fill the dose automatically</div></div>
    <form method="get" style="padding:11px 16px;display:flex;gap:8px">
      <input type="hidden" name="tab" value="drugs">
      <input name="q" value="<?= e($qs) ?>" placeholder="Search medicine or generic">
      <button class="btn ghost sm">Find</button>
      <?php if($qs!==''): ?><a class="btn ghost sm" href="?tab=drugs">Clear</a><?php endif; ?>
    </form>
    <table><thead><tr><th>Medicine</th><th>Default dose</th><th style="text-align:right">Used</th><th></th></tr></thead>
    <tbody>
    <?php if(!$drugs): ?><tr><td colspan="4" class="empty">No medicines. Add one on the right.</td></tr><?php endif; ?>
    <?php foreach($drugs as $d): ?>
      <tr>
        <td><b style="font-size:12.5px"><?= e($d['name']) ?></b>
          <div class="psub"><?= e($d['generic']) ?><?= trim((string)$d['notes'])!==''?' · '.e($d['notes']):'' ?></div></td>
        <td style="font-size:12px"><?= e(trim($d['def_dose'].' '.$d['def_unit'])) ?> · <?= e($d['def_when']) ?>
          <div class="psub"><?= e($d['def_freq']) ?> · <?= e($d['def_duration']) ?></div></td>
        <td style="text-align:right"><span class="pill p-gray"><?= (int)$d['uses'] ?></span></td>
        <td style="text-align:right;white-space:nowrap">
          <a class="btn ghost sm" href="?tab=drugs&edit=<?= (int)$d['id'] ?>">Edit</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Remove <?= e($d['name']) ?>?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="drug_del"><input type="hidden" name="tab" value="drugs">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="btn ghost sm">✕</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="card">
    <h3><?= $edit ? 'Edit medicine' : 'Add a medicine' ?></h3>
    <div class="sub">Defaults fill in automatically when you pick it</div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="<?= $edit?'drug_edit':'drug_add' ?>">
      <input type="hidden" name="tab" value="drugs">
      <?php if($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="field"><label>Name as it should print *</label>
        <input name="name" required value="<?= e($edit['name']??'') ?>" placeholder="Tab Amoxicillin 500mg"></div>
      <div class="row2">
        <div class="field"><label>Generic / salt</label>
          <input name="generic" value="<?= e($edit['generic']??'') ?>" placeholder="amoxicillin">
          <div class="ph">Used by the safety checker</div></div>
        <div class="field"><label>Form</label><select name="form">
          <?php foreach(picklist('form',['Tab','Cap','Syp','Inj','Inh','Drops','Cream','Sachet']) as $f): ?>
            <option <?= ($edit['form']??'')===$f?'selected':'' ?>><?= $f ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="row3">
        <div class="field"><label>Strength</label><input name="strength" value="<?= e($edit['strength']??'') ?>" placeholder="500mg"></div>
        <div class="field"><label>Dose</label><input name="def_dose" value="<?= e($edit['def_dose']??'1') ?>"></div>
        <div class="field"><label>Unit</label><input name="def_unit" value="<?= e($edit['def_unit']??'tab') ?>"></div>
      </div>
      <div class="row3">
        <div class="field"><label>When</label><select name="def_when">
          <?php foreach(picklist('when',['After Food','Before Food','With Food','Empty Stomach','Bedtime','Anytime']) as $w): ?>
            <option <?= ($edit['def_when']??'After Food')===$w?'selected':'' ?>><?= $w ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Frequency</label><select name="def_freq">
          <?php foreach(picklist('freq',['OD','BD','TDS','QID','Weekly','SOS']) as $f): ?>
            <option <?= ($edit['def_freq']??'OD')===$f?'selected':'' ?>><?= $f ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Duration</label><input name="def_duration" value="<?= e($edit['def_duration']??'5 days') ?>"></div>
      </div>
      <div class="field"><label>Standing note to the patient</label>
        <input name="notes" value="<?= e($edit['notes']??'') ?>" placeholder="Rinse mouth after use"></div>
      <button class="btn"><?= $edit?'Save changes':'Add medicine' ?></button>
      <?php if($edit): ?><a class="btn ghost" href="?tab=drugs">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<?php elseif($tab==='labs'): ?>
<div class="grid g2" style="align-items:start">
  <div class="card"><h3>Tests</h3><div class="sub">These appear as tappable tags in the consultation</div>
    <?php $grp=''; foreach($labs as $l):
      if($l['grp']!==$grp){ $grp=$l['grp']; echo '<label style="margin-top:11px">'.e($grp).'</label>'; } ?>
      <span class="tag" style="margin:3px 4px 3px 0"><?= e($l['name']) ?>
        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="lab_del"><input type="hidden" name="tab" value="labs">
          <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button style="border:0;background:none;cursor:pointer;color:var(--red);font-weight:800">×</button></form>
      </span>
    <?php endforeach; ?>
  </div>
  <div class="card"><h3>Add a test</h3><div class="sub">Anything you order regularly</div>
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="lab_add"><input type="hidden" name="tab" value="labs">
      <div class="field"><label>Test name *</label><input name="lname" required placeholder="Vitamin D3"></div>
      <div class="field"><label>Group</label><input name="grp" list="grps" placeholder="Blood">
        <datalist id="grps"><?php foreach(array_unique(array_column($labs,'grp')) as $g): ?>
          <option value="<?= e($g) ?>"><?php endforeach; ?></datalist></div>
      <button class="btn">Add test</button></form>
  </div>
</div>

<?php else: ?>
<div class="card p0">
  <div style="padding:13px 16px 0"><h3>Favourite prescriptions</h3>
    <div class="sub">Save a whole prescription from the consultation screen, then reuse it in one click</div></div>
  <table><thead><tr><th>Name</th><th>Diagnosis</th><th>Medicines</th><th style="text-align:right">Used</th><th></th></tr></thead>
  <tbody>
  <?php if(!$sets): ?><tr><td colspan="5" class="empty">
    No favourites yet. Fill a prescription in the consultation screen and press <b>⭐ Save as favourite</b>.</td></tr><?php endif; ?>
  <?php foreach($sets as $s): $m=json_decode((string)$s['meds'],true)?:[]; ?>
    <tr>
      <td><b style="font-size:12.5px"><?= e($s['name']) ?></b></td>
      <td style="font-size:12px"><?= e($s['diagnosis']) ?></td>
      <td style="font-size:11.5px;color:var(--muted)">
        <?= e(implode(', ', array_map(fn($x)=>$x['name'], array_slice($m,0,3)))) ?><?= count($m)>3?' +'.(count($m)-3):'' ?></td>
      <td style="text-align:right"><span class="pill p-gray"><?= (int)$s['uses'] ?></span></td>
      <td style="text-align:right">
        <form method="post" style="display:inline" onsubmit="return confirm('Delete this favourite?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="set_del"><input type="hidden" name="tab" value="sets">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn ghost sm">✕</button></form></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php foot(); ?>
