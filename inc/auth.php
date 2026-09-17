<?php
/* Real user accounts (hashed) + audit trail. */
declare(strict_types=1);
require_once __DIR__.'/db.php';

/* Accounts used only to initialise an empty database. Production credentials
   come from data/config.local.php or DRCRM_INITIAL_* environment variables;
   known demo passwords exist only when DEMO_MODE is explicitly enabled. */
function initial_users(): array {
    if (APP_DEMO_MODE) {
        return [
            ['drbakshi', 'clinic@2026', 'Dr. Raja Bakshi', 'doctor'],
            ['reception', 'front@2026', 'Front Desk', 'staff'],
        ];
    }

    $doctorUser = app_config('INITIAL_DOCTOR_USERNAME');
    $doctorPass = app_config('INITIAL_DOCTOR_PASSWORD');
    if ($doctorUser === '' || $doctorPass === '') return [];

    $users = [[
        $doctorUser,
        $doctorPass,
        app_config('INITIAL_DOCTOR_NAME', 'Doctor'),
        app_config('INITIAL_DOCTOR_ROLE', 'doctor') === 'staff' ? 'staff' : 'doctor',
    ]];
    $staffUser = app_config('INITIAL_STAFF_USERNAME');
    $staffPass = app_config('INITIAL_STAFF_PASSWORD');
    if ($staffUser !== '' && $staffPass !== '') {
        $users[] = [$staffUser, $staffPass, app_config('INITIAL_STAFF_NAME', 'Front Desk'), 'staff'];
    }
    return $users;
}

/* Seed accounts once, as password hashes. Existing user records are never
   touched by configuration changes. */
function ensure_users(): bool {
    $pdo = db();
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) return true;

    $users = initial_users();
    if (!$users) return false;
    $st = $pdo->prepare('INSERT INTO users(username,pass_hash,name,role) VALUES(?,?,?,?)');
    foreach ($users as [$username, $password, $name, $role]) {
        $st->execute([
            strtolower(trim($username)), password_hash($password, PASSWORD_DEFAULT), trim($name), $role,
        ]);
    }
    return true;
}

function auth_needs_setup(): bool {
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0 && !initial_users();
}

function auth_login(string $user, string $pass): ?array {
    if (!ensure_users()) return null;
    $q = db()->prepare('SELECT * FROM users WHERE username=? AND active=1');
    $q->execute([strtolower(trim($user))]);
    $u = $q->fetch();
    if (!$u || !password_verify($pass, (string)$u['pass_hash'])) return null;
    /* Transparently upgrade the hash if PHP's default changes. */
    if (password_needs_rehash((string)$u['pass_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    $role = (string)$u['role'];
    if (!in_array($role, ['doctor', 'staff'], true)) $role = 'staff';
    return ['username'=>(string)$u['username'], 'name'=>(string)$u['name'], 'role'=>$role];
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
