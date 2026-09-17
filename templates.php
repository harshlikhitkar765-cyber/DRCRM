<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';

require_login();
$pdo=db();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    if (($_POST['do']??'')==='reset') {
        foreach (default_templates() as $lang=>$body) {
            $pdo->prepare('UPDATE templates SET body=? WHERE lang=? AND is_default=1')->execute([$body,$lang]);
        }
        $_SESSION['ok']='Templates reset to the clinic default.';
    } else {
        $pdo->prepare('UPDATE templates SET body=? WHERE id=?')
            ->execute([(string)$_POST['body'], (int)$_POST['id']]);
        $_SESSION['ok']='Template saved. New messages will use it immediately.';
    }
    redirect('templates.php?lang='.urlencode((string)($_POST['lang']??'English')));
}

$lang=$_GET['lang']??'English';
$t=$pdo->prepare('SELECT * FROM templates WHERE lang=? ORDER BY is_default DESC, id LIMIT 1');
$t->execute([$lang]); $tpl=$t->fetch();
if(!$tpl){ $tpl=['id'=>0,'lang'=>$lang,'body'=>default_templates()[$lang]??'']; }

head('WhatsApp Templates');
?>
<div class="page-h">
  <div><h1>WhatsApp message templates</h1>
    <p>Customise exactly how the prescription reads. Changes apply to every message sent afterwards.</p></div>
</div>

<div style="display:flex;gap:7px;margin-bottom:15px">
  <?php foreach (picklist('lang',['English','Hindi','Marathi']) as $l): ?>
    <a class="btn <?= $lang===$l?'':'ghost' ?> sm" href="?lang=<?= $l ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<div class="grid g2" style="align-items:start">
  <div class="card">
    <h3><?= e($lang) ?> template</h3><div class="sub">WhatsApp formatting: *bold*, _italic_</div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$tpl['id'] ?>">
      <input type="hidden" name="lang" value="<?= e($lang) ?>">
      <textarea name="body" rows="26" style="font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.6"><?= e($tpl['body']) ?></textarea>
      <div style="display:flex;gap:9px;margin-top:12px">
        <button class="btn">Save template</button>
        <button class="btn ghost" name="do" value="reset"
          onclick="return confirm('Reset all three templates to the clinic default?')">Reset to default</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Available placeholders</h3><div class="sub">Swapped for real values when the message is built</div>
    <table><tbody>
    <?php foreach ([
      '{{clinic}}'=>'Clinic name — '.clinic('name'),
      '{{doctor}}'=>'Doctor — '.clinic('doctor'),
      '{{qual}}'=>'Qualification — '.clinic('qual'),
      '{{phone}}'=>'Clinic phone — '.clinic('phone'),
      '{{address}}'=>'Clinic address',
      '{{hours}}'=>'OPD hours — '.clinic('hours'),
      '{{date}}'=>'Today\'s date',
      '{{patient}}'=>'Patient name',
      '{{diagnosis}}'=>'Diagnosis entered in the consultation',
      '{{vitals}}'=>'Temp, BP, pulse, SpO2, weight, sugar',
      '{{medicines}}'=>'Numbered medicine list with dose and duration',
      '{{labs}}'=>'Tests advised',
      '{{advice}}'=>'Doctor\'s advice',
      '{{followup}}'=>'Next check-up date',
    ] as $k=>$v): ?>
      <tr><td style="width:130px"><code><?= e($k) ?></code></td><td style="font-size:12px"><?= e($v) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <div class="ph" style="margin-top:12px">If a placeholder has no value for a given visit, its whole
      section (heading included) is removed automatically — the patient never sees an empty field.</div>
  </div>
</div>
<?php foot(); ?>
