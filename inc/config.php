<?php
/* ==========================================================
   Dr Bakshi Clinic — configuration
   ========================================================== */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

const APP_NAME = 'Dr Bakshi Clinic';

/* ==========================================================
   Database — MySQL / MariaDB
   ----------------------------------------------------------
   These are the LIVE Hostinger values. They are used whenever
   the app runs on the real host.

   Keep DB_PASS out of any public folder and never commit it
   to a public repository.

   Note: the values below are read through db_cfg() in
   inc/db.php, which lets a local sandbox override them via a
   data/config.local.php file. On your host that file does not
   exist, so these values are used exactly as written.
   ========================================================== */
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'u835224156_drbd';
const DB_USER = 'u835224156_druser';
const DB_PASS = 'dr@bhopal@DR1';
const DB_CHARSET = 'utf8mb4';

/* MySQL is strict where the old engine was not: an empty string is not a
   valid DATE or DATETIME. Pass optional dates through this so a blank field
   is stored as a proper NULL instead of throwing "Incorrect datetime value". */
function dnull(?string $v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/* Clinic identity — used on prescriptions and every WhatsApp message */
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

/* ----------------------------------------------------------
   WhatsApp delivery.

   driver = 'link'  -> build a wa.me click-to-chat URL (no cost,
                       no approval; a human presses send). Default.
   driver = 'cloud' -> Meta WhatsApp Business Cloud API. Requires a
                       verified sender + an APPROVED message template
                       for business-initiated messages.
   ---------------------------------------------------------- */
const WA = [
    'driver'        => 'link',
    'phone_id'      => '',          // Cloud API phone number ID
    'token'         => '',          // permanent access token
    'template_name' => 'visit_summary',
    'template_lang' => 'en',
    'verify_token'  => 'drbakshi-webhook-2026',  /* set the same string in the Meta webhook config */
    'api_version'   => 'v21.0',
];

/* Simple demo login. Replace with real users before production. */
const USERS = [
    'drbakshi'  => ['pass' => 'clinic@2026', 'name' => 'Dr. Raja Bakshi', 'role' => 'doctor'],
    'reception' => ['pass' => 'front@2026',  'name' => 'Front Desk',      'role' => 'staff'],
];

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function redirect(string $to): never {
    header("Location: $to");
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $sess = (string)($_SESSION['csrf'] ?? '');
    $sent = (string)($_POST['csrf']    ?? '');

    /* An empty token must never be accepted. On a brand-new session
       $_SESSION['csrf'] does not exist yet, and hash_equals('', '') returns
       TRUE — so a request that posts no token at all would have passed.
       Require both sides to be present before comparing. */
    if ($sess === '' || $sent === '' || !hash_equals($sess, $sent)) {
        http_response_code(419);
        exit('CSRF token mismatch. Please reload the page and try again.');
    }
}

/* Secret used to sign patient-facing prescription image links.
   Generated once and stored beside the database. */
function app_secret(): string {
    $f = __DIR__ . '/../data/secret.key';
    if (!is_file($f)) {
        if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
        file_put_contents($f, bin2hex(random_bytes(32)));
        @chmod($f, 0600);
    }
    return trim((string)file_get_contents($f));
}

/* Unguessable per-prescription token so a patient can open their own image
   without logging in, and nobody can enumerate other patients' scripts. */
function rx_token(int $rxId): string {
    return substr(hash_hmac('sha256', 'rx:' . $rxId, app_secret()), 0, 32);
}
