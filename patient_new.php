<?php
declare(strict_types=1);
require_once __DIR__.'/inc/boot.php';
  require_login();
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $name = pf('name'); $phone = pf('phone'); $age = pint('age');
  $sex = pf('sex'); $care = pf('care'); $risk = pf('risk'); $lang = pf('lang');
  if ($name === '' || mb_strlen($name) > 120 || !wa_valid_number($phone)
      || $age < 0 || $age > 120 || !in_array($sex, ['F','M','Other'], true)
      || !in_array($care, ['OPD','Home Care','Home ICU'], true)
      || !in_array($risk, ['Low','Medium','High'], true)
      || !in_array($lang, picklist('lang',['English','Hindi','Marathi']), true)) {
    $_SESSION['err'] = 'Enter a name, a valid WhatsApp phone number, age and the listed options.';
    redirect('patient_new.php');
  }
  $st=$pdo->prepare('INSERT INTO patients(name,age,sex,phone,abha,city,conditions,allergies,care,risk,lang)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?)');
  $st->execute([$name,$age,$sex,$phone,pf('abha'),pf('city'),
    pf('conditions'),pf('allergies'),$care,$risk,$lang]);
  $newPatientId = (int)$pdo->lastInsertId();
  audit('patient_add','patient',$newPatientId);
  $_SESSION['ok']='Patient added.';
  redirect('patient.php?id='.$newPatientId);
}
head('New Patient');
?>
<div class="page-h"><div><h1>New patient</h1><p>Add a record to the clinic register</p></div></div>
<div class="card" style="max-width:680px"><form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="row2">
    <?= field('name','Full name *','',['required'=>true]) ?>
    <?= field('phone','Phone (WhatsApp) *','',['required'=>true,'ph'=>'+91 98xxx xxxxx']) ?>
  </div>
  <div class="row3">
    <div class="field"><label>Age</label><input type="number" name="age" min="0" max="120"></div>
    <?= field_select('sex','Sex',['F','M','Other']) ?>
    <?= field_select('lang','Message language', picklist('lang',['English','Hindi','Marathi'])) ?>
  </div>
  <div class="row2">
    <?= field('abha','ABHA number','',['ph'=>'91-xxxx-xxxx-xxxx']) ?>
    <?= field('city','Area / locality','',['ph'=>'M.P. Nagar']) ?>
  </div>
  <?= field('conditions','Conditions (comma separated)','',['ph'=>'Type 2 Diabetes, Hypertension']) ?>
  <?= field('allergies','Allergies','',['ph'=>'Penicillin']) ?>
  <div class="row2">
    <?= field_select('care','Care setting', picklist('care',['OPD','Home Care','Home ICU'])) ?>
    <div class="field"><label>Risk</label><select name="risk">
      <option>Low</option><option>Medium</option><option>High</option></select></div>
  </div>
  <button class="btn">Save patient</button>
  <a class="btn ghost" href="patients.php">Cancel</a>
</form></div>
<?php foot(); ?>
