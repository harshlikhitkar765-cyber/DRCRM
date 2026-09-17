<?php
/* Meta WhatsApp Cloud API webhook. Captures patient replies so
   "Reply 1 to confirm" in the template is actually true. */
declare(strict_types=1);
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/whatsapp.php';

/* Verification handshake */
if (($_GET['hub_mode'] ?? '') === 'subscribe') {
    if (($_GET['hub_verify_token'] ?? '') === (WA['verify_token'] ?? '')) {
        header('Content-Type: text/plain'); exit((string)($_GET['hub_challenge'] ?? ''));
    }
    http_response_code(403); exit('Bad verify token');
}

$raw = file_get_contents('php://input') ?: '';
$j   = json_decode($raw, true) ?: [];
$pdo = db();

function classify(string $t): string {
    $t = strtolower(trim($t));
    if ($t === '1' || str_contains($t,'confirm') || str_contains($t,'yes') || str_contains($t,'haan')) return 'confirm';
    if ($t === '2' || str_contains($t,'reschedul') || str_contains($t,'change'))                        return 'reschedule';
    if ($t === 'stop' || str_contains($t,'unsubscribe'))                                                return 'optout';
    if (str_contains($t,'help') || str_contains($t,'madad'))                                            return 'help';
    return 'other';
}

foreach (($j['entry'] ?? []) as $entry) {
  foreach (($entry['changes'] ?? []) as $ch) {
    foreach (($ch['value']['messages'] ?? []) as $msg) {
        $from = (string)($msg['from'] ?? '');
        $body = (string)($msg['text']['body'] ?? '');
        if ($from === '') continue;
        $tail = substr(preg_replace('/\D+/', '', $from), -10);
        $pq = $pdo->prepare("SELECT id FROM patients
                             WHERE replace(replace(replace(phone,' ',''),'+',''),'-','') LIKE ?");
        $pq->execute(['%'.$tail]);
        $pid = $pq->fetchColumn();
        $intent = classify($body);
        $pdo->prepare('INSERT INTO wa_replies(patient_id,phone,body,intent) VALUES(?,?,?,?)')
            ->execute([$pid ?: null, $from, $body, $intent]);
        if ($intent === 'optout' && $pid) {
            $pdo->prepare('UPDATE patients SET wa_consent=0 WHERE id=?')->execute([$pid]);
        }
    }
  }
}
http_response_code(200); echo 'OK';
