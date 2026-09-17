<?php
declare(strict_types=1);
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/pad.php';
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['user'])) { http_response_code(401); exit('{}'); }
$s = pad_get((string)($_GET['t'] ?? ''));
if (!$s) exit(json_encode(['status'=>'invalid']));
echo json_encode([
  'status' => !empty($s['expired']) ? 'expired' : $s['status'],
  'url'    => $s['status']==='done' ? 'padimg.php?t='.rawurlencode((string)$s['token']) : null,
]);
