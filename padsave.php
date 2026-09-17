<?php
/* Desk accepts the pad result -> becomes a real prescription -> send flow. */
declare(strict_types=1);
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/pad.php';
require_once __DIR__.'/inc/auth.php';
require_once __DIR__.'/inc/layout.php';
require_doctor();
csrf_check();

$pdo = db();
$s = pad_get((string)($_POST['t'] ?? ''));
if (!$s || $s['status'] !== 'done' || empty($s['result_file'])) {
    $_SESSION['err'] = 'Nothing has been received from the pad yet.';
    redirect('queue.php');
}
$pid    = (int)$s['patient_id'];
/* The pad session, not a browser-supplied field, decides which queue entry
   can be completed. */
$apptId = (int)($s['appt_id'] ?? 0);

try {
    $pdo->beginTransaction();
    /* A desk-side double-click must not turn one phone submission into two
       prescriptions. Claim the result and insert the prescription together. */
    $claim = $pdo->prepare("UPDATE pad_sessions SET status='claimed'
                            WHERE token=? AND status='done'");
    $claim->execute([(string)$s['token']]);
    if (!$claim->rowCount()) throw new RuntimeException('This pad result has already been accepted.');

    $st = $pdo->prepare('INSERT INTO prescriptions(patient_id,rx_date,diagnosis,vitals,meds,labs,advice,follow_up,ink_file,ink_mode)
                         VALUES(?,?,?,?,?,?,?,?,?,1)');
    $st->execute([$pid, date('Y-m-d'), trim((string)($_POST['diagnosis'] ?? '')),
                  json_encode([]), json_encode([]), json_encode([]), '', '',
                  (string)$s['result_file']]);
    $rxId = (int)$pdo->lastInsertId();
    if ($apptId) $pdo->prepare("UPDATE appointments SET status='Completed' WHERE id=?")->execute([$apptId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['err'] = $e->getMessage();
    redirect('queue.php');
}

audit('rx_create', 'prescription', $rxId, 'via smart pad');
$_SESSION['ok'] = 'Prescription received from the pad.';
redirect("send.php?rx=$rxId");
