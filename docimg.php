<?php
declare(strict_types=1);
require_once __DIR__.'/inc/db.php';
session_start();
if(empty($_SESSION['user'])){ http_response_code(403); exit('Forbidden'); }
$q=db()->prepare('SELECT file FROM documents WHERE id=?'); $q->execute([(int)($_GET['id']??0)]);
$fn=(string)$q->fetchColumn();
$path=__DIR__.'/data/docs/'.basename($fn);
if(!$fn || !is_file($path)){ http_response_code(404); exit('Not found'); }
$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
header('Content-Type: '.($ext==='png'?'image/png':'image/jpeg'));
header('Content-Length: '.filesize($path));
readfile($path);
