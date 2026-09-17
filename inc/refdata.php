<?php
/* ==================================================================
   Reference data: settings, dropdown lists, brand names, advice.

   Everything here used to be hardcoded in PHP files. It now lives in
   the database so the doctor can change it from the Settings page
   without anyone editing code.

   Each lookup is cached for the life of the request, so putting a
   dropdown inside a loop does not mean one query per row.
   ================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/* ---- settings -------------------------------------------------- */

function settings_all(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        foreach (db()->query('SELECT skey,sval FROM settings') as $r) $c[$r['skey']] = $r['sval'];
    } catch (Throwable $e) { $c = []; }
    return $c;
}

function setting(string $key, string $default = ''): string {
    $a = settings_all();
    return isset($a[$key]) && $a[$key] !== '' ? (string)$a[$key] : $default;
}

function setting_set(string $key, string $val): void {
    db()->prepare('INSERT INTO settings(skey,sval) VALUES(?,?)
                   ON DUPLICATE KEY UPDATE sval=VALUES(sval)')->execute([$key, $val]);
}

/* The clinic block, read from the database but falling back to the
   CLINIC constant so nothing breaks if the table is empty. */
function clinic(string $field = ''): array|string {
    static $c = null;
    if ($c === null) {
        $const = defined('CLINIC') ? CLINIC : [];
        $c = [
            'name'   => setting('clinic_name',   $const['name']   ?? ''),
            'doctor' => setting('clinic_doctor', $const['doctor'] ?? ''),
            'qual'   => setting('clinic_qual',   $const['qual']   ?? ''),
            'spec'   => setting('clinic_spec',   $const['spec']   ?? ''),
            'reg'    => setting('clinic_reg',    $const['reg']    ?? ''),
            'addr'   => setting('clinic_addr',   $const['addr']   ?? ''),
            'phone'  => setting('clinic_phone',  $const['phone']  ?? ''),
            'email'  => setting('clinic_email',  $const['email']  ?? ''),
            'hours'  => setting('clinic_hours',  $const['hours']  ?? ''),
        ];
    }
    return $field === '' ? $c : (string)($c[$field] ?? '');
}

/* ---- dropdown lists -------------------------------------------- */

function picklist(string $kind, array $fallback = []): array {
    static $cache = [];
    if (isset($cache[$kind])) return $cache[$kind];
    $out = [];
    try {
        $q = db()->prepare('SELECT val FROM picklists WHERE kind=? AND active=1 ORDER BY sort, val');
        $q->execute([$kind]);
        $out = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { $out = []; }
    if (!$out) $out = $fallback;          /* never render an empty <select> */
    return $cache[$kind] = $out;
}

/* ---- brand -> generic ------------------------------------------ */

function brand_rows(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        foreach (db()->query('SELECT brand,generic FROM brands WHERE active=1') as $r) {
            $c[strtolower($r['brand'])] = strtolower($r['generic']);
        }
    } catch (Throwable $e) { $c = []; }
    return $c;
}

/* ---- diagnoses / advice ---------------------------------------- */

function diagnosis_list(int $limit = 300): array {
    try {
        $q = db()->query('SELECT name FROM diagnoses WHERE active=1 ORDER BY uses DESC, name LIMIT '.(int)$limit);
        return $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }
}

/* Remember what the doctor actually writes, so the list improves by
   itself instead of needing to be curated. */
function diagnosis_learn(string $name): void {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 160) return;
    try {
        db()->prepare('INSERT INTO diagnoses(name,uses) VALUES(?,1)
                       ON DUPLICATE KEY UPDATE uses=uses+1')->execute([$name]);
    } catch (Throwable $e) { /* never block a save over a lookup table */ }
}

function advice_list(string $lang = ''): array {
    try {
        if ($lang !== '') {
            $q = db()->prepare('SELECT text FROM advice_lines WHERE active=1 AND lang=? ORDER BY uses DESC, id');
            $q->execute([$lang]);
        } else {
            $q = db()->query('SELECT text FROM advice_lines WHERE active=1 ORDER BY uses DESC, id');
        }
        return $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }
}
