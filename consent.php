<?php
declare(strict_types=1);
require_once __DIR__.'/inc/db.php'; require_once __DIR__.'/inc/auth.php';
require_once __DIR__.'/inc/config.php';
session_start();
if(empty($_SESSION['user'])) redirect('login.php');
csrf_check();
$id=(int)($_POST['id']??0);
db()->prepare('UPDATE patients SET wa_consent=1, consent_at=NOW() WHERE id=?')->execute([$id]);
audit('consent_given','patient',$id);
$_SESSION['ok']='WhatsApp consent recorded.';
redirect('patient.php?id='.$id);
