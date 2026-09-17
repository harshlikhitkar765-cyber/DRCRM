<?php
/* Settings — everything that used to be locked inside PHP files. */
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

require_login();

$pdo = db();
$tab = $_GET['tab'] ?? 'clinic';

/* The "Where data lives" and "App files" tabs were removed. An old
   bookmark or a stale cached page would otherwise land on a blank
   panel, because no branch matches. Send those back to the first tab. */
if (!in_array($tab, ['clinic', 'lists', 'brands', 'advice'], true)) {
    $tab = 'clinic';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    if ($do === 'clinic') {
        foreach (['name','doctor','qual','spec','reg','addr','phone','email','hours'] as $f) {
            setting_set('clinic_'.$f, trim((string)($_POST[$f] ?? '')));
        }
        setting_set('rx_footer', pf('rx_footer'));
        audit('settings_clinic','settings',0,'clinic details updated');
        $_SESSION['ok'] = 'Clinic details saved. They appear on every prescription and message.';
        redirect('settings.php?tab=clinic');
    }

    if ($do === 'pick_add') {
        $kind = pf('kind'); $val = pf('val');
        if ($kind !== '' && $val !== '') {
            $pdo->prepare('INSERT IGNORE INTO picklists(kind,val,sort) VALUES(?,?,999)')->execute([$kind,$val]);
            audit('settings_pick','picklists',0,"$kind + $val");
            $_SESSION['ok'] = 'Added "'.$val.'" to the '.$kind.' list.';
        }
        redirect('settings.php?tab=lists');
    }
    if ($do === 'pick_del') {
        $pdo->prepare('UPDATE picklists SET active=0 WHERE id=?')->execute([(int)$_POST['id']]);
        $_SESSION['ok'] = 'Removed. Existing prescriptions are not affected.';
        redirect('settings.php?tab=lists');
    }

    if ($do === 'brand_add') {
        $b = pf('brand'); $g = pf('generic');
        if ($b !== '' && $g !== '') {
            $pdo->prepare('INSERT INTO brands(brand,generic) VALUES(?,?)
                           ON DUPLICATE KEY UPDATE generic=VALUES(generic),active=1')->execute([$b,$g]);
            audit('settings_brand','brands',0,"$b = $g");
            $_SESSION['ok'] = $b.' now recognised as '.$g.'. Safety checks will catch it.';
        }
        redirect('settings.php?tab=brands');
    }
    if ($do === 'brand_del') {
        $pdo->prepare('UPDATE brands SET active=0 WHERE id=?')->execute([(int)$_POST['id']]);
        redirect('settings.php?tab=brands');
    }

    if ($do === 'advice_add') {
        $t = pf('text'); $l = pf('lang');
        if ($t !== '') {
            $pdo->prepare('INSERT INTO advice_lines(text,lang) VALUES(?,?)')->execute([$t,$l ?: 'English']);
            $_SESSION['ok'] = 'Advice line added.';
        }
        redirect('settings.php?tab=advice');
    }
    if ($do === 'advice_del') {
        $pdo->prepare('UPDATE advice_lines SET active=0 WHERE id=?')->execute([(int)$_POST['id']]);
        redirect('settings.php?tab=advice');
    }
}

$KINDS = ['unit'=>'Medicine unit','when'=>'When to take','freq'=>'Frequency','form'=>'Medicine form',
          'lang'=>'Message language','care'=>'Care setting','risk'=>'Risk level','paymode'=>'Payment mode'];

head('Settings');
$T = function(string $k, string $label) use ($tab) {
    $on = $tab === $k ? 'on' : '';
    echo '<a class="tab '.$on.'" href="settings.php?tab='.$k.'">'.e($label).'</a>';
};
?>
<div class="card p0">
  <div class="tabs" style="padding:0 14px">
    <?php $T('clinic','Clinic details'); $T('lists','Dropdown lists');
          $T('brands','Brand names'); $T('advice','Advice lines');
          ?>
  </div>
</div>

<?php if ($tab === 'clinic'): $c = clinic(); ?>
<div class="card">
  <h3>Clinic details</h3>
  <div class="sub">Printed on every prescription and sent in every WhatsApp message.</div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="clinic">
    <div class="grid g2">
      <div class="field"><label>Clinic name</label><input name="name" value="<?= e($c['name']) ?>" required></div>
      <div class="field"><label>Doctor</label><input name="doctor" value="<?= e($c['doctor']) ?>"></div>
      <div class="field"><label>Qualification</label><input name="qual" value="<?= e($c['qual']) ?>"></div>
      <div class="field"><label>Speciality</label><input name="spec" value="<?= e($c['spec']) ?>"></div>
      <div class="field"><label>Registration number</label><input name="reg" value="<?= e($c['reg']) ?>"></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($c['phone']) ?>"></div>
      <div class="field"><label>Email</label><input name="email" value="<?= e($c['email']) ?>"></div>
      <div class="field"><label>OPD hours</label><input name="hours" value="<?= e($c['hours']) ?>"></div>
    </div>
    <div class="field"><label>Address</label><input name="addr" value="<?= e($c['addr']) ?>"></div>
    <div class="field"><label>Prescription footer note</label>
      <input name="rx_footer" value="<?= e(setting('rx_footer')) ?>"></div>
    <button class="btn">Save clinic details</button>
  </form>
  <div class="ph" style="margin-top:10px">Your registration number appears on every printed
    prescription — make sure it is the real one before issuing any to patients.</div>
</div>

<?php elseif ($tab === 'lists'): ?>
<div class="card">
  <h3>Dropdown lists</h3>
  <div class="sub">Every menu in the prescription form. Add your own options — nothing is fixed in code.</div>
  <?php foreach ($KINDS as $k => $label):
    $q = $pdo->prepare('SELECT id,val FROM picklists WHERE kind=? AND active=1 ORDER BY sort,val');
    $q->execute([$k]); $rows = $q->fetchAll(); ?>
    <div style="padding:11px 0;border-bottom:1px solid var(--line)">
      <div style="font-size:12px;font-weight:700;margin-bottom:6px"><?= e($label) ?>
        <span style="color:var(--muted);font-weight:400">(<?= e($k) ?>)</span></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
        <?php foreach ($rows as $r): ?>
          <span class="tag" style="display:inline-flex;align-items:center;gap:5px"><?= e($r['val']) ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="do" value="pick_del">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="del" style="padding:0 2px;font-size:12px" title="Remove">×</button>
            </form></span>
        <?php endforeach; ?>
        <form method="post" style="display:inline-flex;gap:5px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="pick_add">
          <input type="hidden" name="kind" value="<?= e($k) ?>">
          <input name="val" placeholder="Add…" style="width:130px;padding:6px 9px;font-size:12px">
          <button class="btn ghost sm">Add</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php elseif ($tab === 'brands'):
  $rows = $pdo->query('SELECT id,brand,generic FROM brands WHERE active=1 ORDER BY brand')->fetchAll(); ?>
<div class="card">
  <h3>Brand names <span class="pill p-gray"><?= count($rows) ?></span></h3>
  <div class="sub">When a patient says "Dolo", the safety checks need to know that is paracetamol —
    otherwise a duplicate or an allergy is missed.</div>
  <form method="post" style="display:flex;gap:7px;flex-wrap:wrap;margin:10px 0 14px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="brand_add">
    <input name="brand" placeholder="Brand (e.g. Dolo)" required style="flex:1;min-width:150px">
    <input name="generic" placeholder="Generic (e.g. paracetamol)" required style="flex:1;min-width:170px">
    <button class="btn">Add brand</button>
  </form>
  <div class="tw"><table><thead><tr><th>Brand</th><th>Generic</th><th style="width:60px"></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><b><?= e($r['brand']) ?></b></td><td><?= e($r['generic']) ?></td>
      <td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="brand_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="del">🗑</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php elseif ($tab === 'advice'):
  $rows = $pdo->query('SELECT id,text,lang,uses FROM advice_lines WHERE active=1 ORDER BY lang,uses DESC,id')->fetchAll(); ?>
<div class="card">
  <h3>Advice lines <span class="pill p-gray"><?= count($rows) ?></span></h3>
  <div class="sub">The standard advice the recording recognises and the form offers.</div>
  <form method="post" style="display:flex;gap:7px;flex-wrap:wrap;margin:10px 0 14px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="advice_add">
    <input name="text" placeholder="e.g. Avoid cold drinks" required style="flex:2;min-width:200px">
    <select name="lang" style="flex:0 0 130px">
      <?php foreach (picklist('lang',['English','Hindi']) as $l): ?><option><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn">Add</button>
  </form>
  <div class="tw"><table><thead><tr><th>Advice</th><th style="width:90px">Language</th>
    <th style="width:60px">Used</th><th style="width:60px"></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e($r['text']) ?></td><td><span class="pill p-gray"><?= e($r['lang']) ?></span></td>
      <td><?= (int)$r['uses'] ?></td>
      <td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="advice_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="del">🗑</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php endif; foot();
