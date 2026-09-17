<?php
/* What this doctor usually prescribes for a diagnosis. Called as the
   diagnosis is typed, so the suggestions follow what is actually written
   rather than only the stored condition. */
declare(strict_types=1);
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/auto.php';

app_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['user'])) { http_response_code(401); exit('{"error":"login"}'); }
if (!is_doctor()) { http_response_code(403); exit('{"error":"doctor"}'); }

$dx = trim((string)($_GET['dx'] ?? ''));
echo json_encode(['meds' => $dx === '' ? [] : learn_meds_for($dx, 4)]);
