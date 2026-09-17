<?php
/* ==========================================================
   Dr Bakshi Clinic — configuration
   ========================================================== */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

const APP_NAME = 'Dr Bakshi Clinic';

/* ------------------------------------------------------------------
   Private configuration

   Keep credentials OUT of Git. The app reads values in this order:
     1. environment variable, e.g. DRCRM_DB_PASS
     2. data/config.local.php (not tracked; Apache denies access to data/)
     3. the non-secret default below

   Copy data/config.local.php.example to data/config.local.php and fill it
   in on a shared host. Environment variables are preferable where the host
   supports them. The local file must return an associative array.
   ------------------------------------------------------------------ */
function app_config_values(): array {
    static $values = null;
    if ($values !== null) return $values;

    $values = [];
    $file = __DIR__ . '/../data/config.local.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            foreach ($loaded as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $values[strtoupper($key)] = (string)$value;
                }
            }
        }
    }
    return $values;
}

function app_config(string $key, string $default = ''): string {
    $key = strtoupper($key);
    $env = getenv('DRCRM_' . $key);
    if ($env !== false) return trim((string)$env);
    $values = app_config_values();
    return trim((string)($values[$key] ?? $default));
}

function app_config_bool(string $key, bool $default = false): bool {
    $value = strtolower(app_config($key, $default ? '1' : '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/* Public URL used in QR codes and patient-facing WhatsApp image links. Never
   derive it from HTTP_HOST: that request header is attacker-controlled. Set
   APP_URL to the canonical HTTPS URL, including a subdirectory if applicable. */
function public_app_url(): string {
    $url = app_config('APP_URL');
    if ($url === '') return '';
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
        return '';
    }
    $host = (string)$parts['host'];
    if ($host === '') return '';
    $port = isset($parts['port']) ? ':'.(int)$parts['port'] : '';
    $path = rtrim((string)($parts['path'] ?? ''), '/');
    return strtolower((string)$parts['scheme']).'://'.$host.$port.$path;
}

/* Database — MySQL / MariaDB. Fill these through the private config source
   described above. The defaults make a conventional local setup explicit,
   but no password is stored in source control. */
define('DB_HOST',    app_config('DB_HOST', 'localhost'));
define('DB_PORT',    (int)app_config('DB_PORT', '3306'));
define('DB_NAME',    app_config('DB_NAME', 'drcrm'));
define('DB_USER',    app_config('DB_USER', 'drcrm'));
define('DB_PASS',    app_config('DB_PASS'));
define('DB_CHARSET', 'utf8mb4');

/* Demo access is intentionally opt-in. Never turn it on for a public clinic
   site. It seeds the sample doctor and front-desk accounts on a fresh DB. */
define('APP_DEMO_MODE', app_config_bool('DEMO_MODE'));

/* MySQL is strict where the old engine was not: an empty string is not a
   valid DATE or DATETIME. Pass optional dates through this so a blank field
   is stored as a proper NULL instead of throwing "Incorrect datetime value". */
function dnull(?string $v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/* Clinic identity — used on prescriptions and every WhatsApp message.
   Settings can replace these values without editing code. */
const CLINIC = [
    'name'   => 'Dr Bakshi Clinic',
    'doctor' => 'Dr. Raja Bakshi',
    'qual'   => 'MBBS, MD (General Medicine)',
    'spec'   => 'Consultant Physician',
    'reg'    => 'MPMC-2009-14872',
    'addr'   => 'R-32, Zone-1, M.P. Nagar, Bhopal 462011',
    'phone'  => '+91 97551 08769',
    'email'  => 'care@drbakshiclinic.com',
    'hours'  => 'Mon-Sat 7:00-9:00 PM',
];

/* WhatsApp delivery. Keep Meta credentials in the private config source.
   `app_secret` is the Meta App Secret used to verify webhook signatures. */
define('WA', [
    'driver'        => app_config('WA_DRIVER', 'link'),
    'phone_id'      => app_config('WA_PHONE_ID'),
    'token'         => app_config('WA_TOKEN'),
    'app_secret'    => app_config('WA_APP_SECRET'),
    'template_name' => app_config('WA_TEMPLATE_NAME', 'visit_summary'),
    'template_lang' => app_config('WA_TEMPLATE_LANG', 'en'),
    'verify_token'  => app_config('WA_VERIFY_TOKEN'),
    'api_version'   => app_config('WA_API_VERSION', 'v21.0'),
]);

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function redirect(string $to): never {
    header("Location: $to");
    exit;
}

/* Start one hardened browser session. Calling it repeatedly is harmless and
   lets public entry points, APIs and regular pages share the same policy. */
function app_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string {
    app_session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    app_session_start();
    $sess = (string)($_SESSION['csrf'] ?? '');
    $sent = (string)($_POST['csrf']    ?? '');
    if ($sess === '' || $sent === '' || !hash_equals($sess, $sent)) {
        http_response_code(419);
        exit('CSRF token mismatch. Please reload the page and try again.');
    }
}

/* Secret used to sign patient-facing prescription image links.
   Generated once and stored beside the database, never in source control. */
function app_secret(): string {
    $f = __DIR__ . '/../data/secret.key';
    if (!is_file($f)) {
        if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
        file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    return trim((string)file_get_contents($f));
}

/* Unguessable per-prescription token so a patient can open their own image
   without logging in, and nobody can enumerate other patients' scripts. */
function rx_token(int $rxId): string {
    return substr(hash_hmac('sha256', 'rx:' . $rxId, app_secret()), 0, 32);
}
