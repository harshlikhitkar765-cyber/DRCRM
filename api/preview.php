<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';
require_once __DIR__.'/../inc/whatsapp.php';
require_once __DIR__.'/../inc/safety.php';
app_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['error'=>'method'])); }
if (empty($_SESSION['user'])) { http_response_code(401); exit(json_encode(['html'=>'Session expired.'])); }
if (!is_doctor()) { http_response_code(403); exit(json_encode(['error'=>'doctor'])); }

$pdo=db();
$pid=(int)($_POST['patient_id']??0);
$q=$pdo->prepare('SELECT * FROM patients WHERE id=?'); $q->execute([$pid]); $pt=$q->fetch();
if(!$pt){ exit(json_encode(['html'=>'Patient not found.'])); }

$lang=(string)($_POST['lang']??$pt['lang']??'English');

$meds=[];
foreach(($_POST['med_name']??[]) as $i=>$nm){
  if(trim((string)$nm)==='') continue;
  $meds[]=['name'=>trim((string)$nm),
    'dose'=>trim((string)($_POST['med_dose'][$i]??'')),'unit'=>trim((string)($_POST['med_unit'][$i]??'')),
    'when'=>trim((string)($_POST['med_when'][$i]??'')),'freq'=>trim((string)($_POST['med_freq'][$i]??'')),
    'duration'=>trim((string)($_POST['med_dur'][$i]??'')),'notes'=>trim((string)($_POST['med_notes'][$i]??''))];
}
$vitals=['temp'=>$_POST['v_temp']??'','bp'=>$_POST['v_bp']??'','pulse'=>$_POST['v_pulse']??'',
         'spo2'=>$_POST['v_spo2']??'','weight'=>$_POST['v_weight']??'','sugar'=>$_POST['v_sugar']??''];
$labs=array_values(array_filter(array_map('trim',explode(',',(string)($_POST['labs']??'')))));

$tplRow=$pdo->prepare('SELECT body FROM templates WHERE lang=? ORDER BY is_default DESC, id LIMIT 1');
$tplRow->execute([$lang]);
$tpl=(string)($tplRow->fetchColumn() ?: (default_templates()[$lang] ?? default_templates()['English']));

$body=render_template($tpl,[
  'lang'=>$lang,'patient'=>$pt['name'],
  'diagnosis'=>(string)($_POST['diagnosis']??''),
  'medicines'=>format_meds($meds,$lang),
  'vitals'=>format_vitals($vitals),
  'labs'=>implode(', ',$labs),
  'advice'=>(string)($_POST['advice']??''),
  'followup'=>(string)($_POST['follow_up']??''),
]);

/* Render WhatsApp *bold* and _italic_ for the preview bubble */
$h=htmlspecialchars($body,ENT_QUOTES,'UTF-8');
$h=preg_replace('/\*([^*\n]+)\*/u','<b>$1</b>',$h);
$h=preg_replace('/_([^_\n]+)_/u','<i>$1</i>',$h);

$alerts = safety_check($meds, $pt);

echo json_encode(['html'=>$h,'text'=>$body,'alerts'=>$alerts], JSON_UNESCAPED_UNICODE);
