<?php
/* ==================================================================
   Live consultation recording.

   The browser posts the conversation here every few seconds while
   the doctor and patient are still talking, so nothing is lost if
   the tab closes, the laptop sleeps or the power goes.

   It keeps ONE row per consultation and updates it, rather than
   inserting a row every few seconds. The row is created on the
   first post and its id is handed back; the browser sends that id
   with every later post.

   The prescription is saved separately by consult.php, which then
   links this note to it.
   ================================================================== */
declare(strict_types=1);
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user'])) { http_response_code(401); exit('{"error":"login"}'); }

/* Same CSRF token as the rest of the app. */
$tok = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $tok)) {
    http_response_code(419); exit('{"error":"csrf"}');
}

$pdo   = db();
$id    = (int)($_POST['id'] ?? 0);
$pid   = (int)($_POST['patient_id'] ?? 0);
$appt  = (int)($_POST['appt_id'] ?? 0);
$text  = trim((string)($_POST['transcript'] ?? ''));
$turns = (string)($_POST['turns'] ?? '');
$dis   = mb_substr(trim((string)($_POST['disease'] ?? '')), 0, 200);
$dr    = (string)($_POST['dr_points'] ?? '');
$pt    = (string)($_POST['pt_points'] ?? '');
$lang  = (string)($_POST['lang'] ?? 'en-IN');
$done  = (int)($_POST['done'] ?? 0);
$sum   = (string)($_POST['summary'] ?? '');
$secs  = max(0, min(86400, (int)($_POST['secs'] ?? 0)));
$start = dnull((string)($_POST['started_at'] ?? ''));

if ($pid <= 0) { http_response_code(400); exit('{"error":"patient"}'); }

/* Guard against a runaway transcript filling the disk. MEDIUMTEXT holds
   16 MB; a two-hour consultation is well under 100 KB. */
if (mb_strlen($text) > 200000) $text = mb_substr($text, 0, 200000);

try {
    if ($id > 0) {
        /* Only ever update a row belonging to this patient. */
        $own = $pdo->prepare('SELECT id FROM consult_notes WHERE id=? AND patient_id=?');
        $own->execute([$id, $pid]);
        if (!$own->fetchColumn()) { http_response_code(403); exit('{"error":"not yours"}'); }

        /* Work the length out from the row's own start time. The browser and
           the server can be in different timezones (or the PC clock can simply
           be wrong), so the database is the single source of truth here. */
        $pdo->prepare('UPDATE consult_notes
                       SET transcript=?, turns=?, disease=?, dr_points=?, pt_points=?,
                           lang=?, summary=?, ended_at=?,
                           started_at=COALESCE(started_at, NOW()),
                           secs=GREATEST(0, TIMESTAMPDIFF(SECOND,
                                  COALESCE(started_at, NOW()), NOW()))
                       WHERE id=?')
            ->execute([$text, $turns, $dis, $dr, $pt, $lang, $sum,
                       $done ? date('Y-m-d H:i:s') : null, $id]);
    } else {
        $pdo->prepare('INSERT INTO consult_notes
                       (patient_id, appt_id, transcript, turns, disease, dr_points, pt_points,
                        lang, summary, secs, started_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,COALESCE(?, NOW()))')
            ->execute([$pid, $appt ?: null, $text, $turns, $dis, $dr, $pt, $lang,
                       $sum, $secs, $start]);
        $id = (int)$pdo->lastInsertId();
        audit('consult_live_start', 'patient', $pid, 'live recording began');
    }

    if ($done) audit('consult_live_end', 'patient', $pid, 'note #'.$id.', '.mb_strlen($text).' chars');

    $row = $pdo->prepare('SELECT secs, started_at FROM consult_notes WHERE id=?');
    $row->execute([$id]);
    $r = $row->fetch() ?: ['secs' => 0, 'started_at' => null];

    echo json_encode([
        'ok'      => true,
        'id'      => $id,
        'chars'   => mb_strlen($text),
        'at'      => date('H:i:s'),
        'secs'    => (int)$r['secs'],
        'started' => $r['started_at'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'save failed', 'detail' => $e->getMessage()]);
}
