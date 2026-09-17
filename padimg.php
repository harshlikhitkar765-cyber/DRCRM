<?php
/* Serves a pad result image to the logged-in desk only. */
declare(strict_types=1);
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/auth.php';
require_once __DIR__.'/inc/pad.php';
app_session_start();
if (empty($_SESSION['user']) || !is_doctor()) { http_response_code(403); exit('Forbidden'); }
$s = pad_get((string)($_GET['t'] ?? ''));
if (!$s || empty($s['result_file'])) { http_response_code(404); exit('Not found'); }
$path = __DIR__.'/data/rx/'.basename((string)$s['result_file']);
if (!is_file($path)) { http_response_code(404); exit('Missing'); }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: '.($ext==='jpg'||$ext==='jpeg' ? 'image/jpeg' : 'image/png'));
header('Content-Length: '.filesize($path));
readfile($path);
