<?php
/* Current asset build stamp. The page compares the stamp it was rendered
   with against this live value; if they differ, the page in front of the
   doctor is out of date and it offers to reload itself. */
declare(strict_types=1);
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/sync.php';

app_session_start();
header('Content-Type: application/json; charset=utf-8');
/* This response must never be cached — it is the thing detecting staleness. */
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['user'])) { http_response_code(401); exit('{"error":"login"}'); }

echo json_encode([
    'build'  => sync_build(),
    'latest' => sync_latest(),
    'state'  => sync_report()['state'],
]);
