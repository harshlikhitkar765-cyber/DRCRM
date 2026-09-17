<?php
/* Meta WhatsApp Cloud API webhook. Captures patient replies after verifying
   that the request was actually signed by Meta. */
declare(strict_types=1);
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/whatsapp.php';

/* Meta's one-time verification handshake. Keep the verify token private too:
   without one, there is nothing safe to verify against. */
if (($_GET['hub_mode'] ?? '') === 'subscribe') {
    $verify = (string)(WA['verify_token'] ?? '');
    if ($verify !== '' && hash_equals($verify, (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain; charset=utf-8');
        exit((string)($_GET['hub_challenge'] ?? ''));
    }
    http_response_code(403); exit('Bad verify token');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method not allowed');
}

/* Meta retries webhooks. A valid X-Hub-Signature-256 is required before the
   database is touched, otherwise anyone could forge a STOP and revoke consent. */
$raw = file_get_contents('php://input') ?: '';
$appSecret = (string)(WA['app_secret'] ?? '');
$signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$expected = $appSecret === '' ? '' : 'sha256='.hash_hmac('sha256', $raw, $appSecret);
if ($expected === '' || $signature === '' || !hash_equals($expected, $signature)) {
    http_response_code(403); exit('Invalid webhook signature');
}

$j = json_decode($raw, true);
if (!is_array($j) || (($j['object'] ?? '') !== 'whatsapp_business_account')) {
    http_response_code(400); exit('Invalid webhook payload');
}
$pdo = db();

function classify(string $t): string {
    $t = strtolower(trim($t));
    if ($t === '1' || str_contains($t,'confirm') || str_contains($t,'yes') || str_contains($t,'haan')) return 'confirm';
    if ($t === '2' || str_contains($t,'reschedul') || str_contains($t,'change')) return 'reschedule';
    if ($t === 'stop' || str_contains($t,'unsubscribe')) return 'optout';
    if (str_contains($t,'help') || str_contains($t,'madad')) return 'help';
    return 'other';
}

foreach (($j['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $ch) {
        foreach (($ch['value']['messages'] ?? []) as $msg) {
            /* Ignore non-text events without treating a missing text body as
               an opt-out. Interactive replies still leave their ID in the log. */
            $from = (string)($msg['from'] ?? '');
            $body = trim((string)($msg['text']['body'] ?? ''));
            $messageId = substr((string)($msg['id'] ?? ''), 0, 100);
            if ($from === '' || $body === '' || $messageId === '') continue;

            $tail = substr(preg_replace('/\D+/', '', $from) ?? '', -10);
            $pq = $pdo->prepare("SELECT id FROM patients
                                 WHERE replace(replace(replace(phone,' ',''),'+',''),'-','') LIKE ?");
            $pq->execute(['%'.$tail]);
            $pid = $pq->fetchColumn();
            $intent = classify($body);

            /* message_id has a unique index: an acknowledged retry is a no-op */
            $insert = $pdo->prepare('INSERT IGNORE INTO wa_replies(patient_id,phone,body,intent,message_id)
                                     VALUES(?,?,?,?,?)');
            $insert->execute([$pid ?: null, $from, $body, $intent, $messageId]);
            if ($insert->rowCount() && $intent === 'optout' && $pid) {
                $pdo->prepare('UPDATE patients SET wa_consent=0 WHERE id=?')->execute([$pid]);
                /* There is no authenticated browser session in a webhook; log
                   the source explicitly instead of attributing it to "system". */
                $pdo->prepare('INSERT INTO audit(username,action,entity,entity_id,detail,ip) VALUES(?,?,?,?,?,?)')
                    ->execute(['meta-webhook', 'consent_revoked', 'patient', $pid, 'STOP reply', $_SERVER['REMOTE_ADDR'] ?? '']);
            }
        }
    }
}
http_response_code(200); echo 'OK';
