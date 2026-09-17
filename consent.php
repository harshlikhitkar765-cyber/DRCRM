<?php
declare(strict_types=1);
require_once __DIR__.'/inc/db.php'; require_once __DIR__.'/inc/auth.php';
require_once __DIR__.'/inc/config.php';
app_session_start();
if(empty($_SESSION['user'])) redirect('login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }
csrf_check();
$id=(int)($_POST['id']??0);
$st = db()->prepare('UPDATE patients SET wa_consent=1, consent_at=NOW() WHERE id=?');
$st->execute([$id]);
if ($st->rowCount()) {
    audit('consent_given','patient',$id);
    $_SESSION['ok']='WhatsApp consent recorded.';
} else {
    $_SESSION['err']='Patient record was not found.';
}
redirect('patient.php?id='.$id);
