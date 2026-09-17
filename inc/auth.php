<?php
/* Real user accounts (hashed) + audit trail. Replaces the plaintext USERS constant. */
declare(strict_types=1);
require_once __DIR__.'/db.php';

/* Seed the two demo accounts once, as proper hashes. */
function ensure_users(): void {
    $pdo = db();
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) return;
    $st = $pdo->prepare('INSERT INTO users(username,pass_hash,name,role) VALUES(?,?,?,?)');
    foreach ([
        ['drbakshi','clinic@2026','Dr. Raja Bakshi','doctor'],
        ['reception','front@2026','Front Desk','staff'],
    ] as [$u,$p,$n,$r]) {
        $st->execute([$u, password_hash($p, PASSWORD_DEFAULT), $n, $r]);
    }
}

function auth_login(string $user, string $pass): ?array {
    ensure_users();
    $q = db()->prepare('SELECT * FROM users WHERE username=? AND active=1');
    $q->execute([strtolower(trim($user))]);
    $u = $q->fetch();
    if (!$u || !password_verify($pass, (string)$u['pass_hash'])) return null;
    /* transparently upgrade the hash if PHP's default changes */
    if (password_needs_rehash((string)$u['pass_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    return ['username'=>$u['username'], 'name'=>$u['name'], 'role'=>$u['role']];
}

function current_user(): array {
    return is_array($_SESSION['user'] ?? null)
        ? $_SESSION['user']
        : ['username'=>(string)($_SESSION['user'] ?? 'system'), 'name'=>'', 'role'=>'staff'];
}

function is_doctor(): bool { return (current_user()['role'] ?? '') === 'doctor'; }

/* DPDP-style audit: who did what, to which record, when. */
function audit(string $action, string $entity = '', int $entityId = 0, string $detail = ''): void {
    try {
        db()->prepare('INSERT INTO audit(username,action,entity,entity_id,detail,ip) VALUES(?,?,?,?,?,?)')
            ->execute([current_user()['username'] ?? 'system', $action, $entity, $entityId, $detail,
                       $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) { /* auditing must never break the app */ }
}
