<?php
/* Serves a handwritten prescription PNG. Login-gated. */
declare(strict_types=1);
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/config.php';
app_session_start();
$id=(int)($_GET['rx']??0);
$tok=(string)($_GET['t']??'');
$pdo=db();
$q=$pdo->prepare('SELECT ink_file FROM prescriptions WHERE id=?'); $q->execute([$id]);
$fn=(string)$q->fetchColumn();
/* Either a logged-in user, or a share token = hmac of the id (for the patient link). */
$valid = !empty($_SESSION['user']) || ($tok !== '' && hash_equals(rx_token($id), $tok));
if(!$fn || !$valid){ http_response_code(404); exit('Not found'); }
$path=__DIR__.'/data/rx/'.basename($fn);
if(!is_file($path)){ http_response_code(404); exit('Missing file'); }
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="prescription-'.$id.'.'.$ext.'"');
header('Cache-Control: private, max-age=86400');
readfile($path);
