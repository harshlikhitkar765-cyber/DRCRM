<?php
/* ---------------------------------------------------------------
   Smart Pad Link.

   The doctor's desk shows a QR code. The doctor scans it with a
   phone or tablet, the pad opens ALREADY PAIRED to that patient,
   they write or photograph, and it lands back on the desk screen.

   No app to install, no login on the phone: the one-time token in
   the URL is the credential, and it dies in 15 minutes.
   --------------------------------------------------------------- */
declare(strict_types=1);
require_once __DIR__.'/db.php';

const PAD_TTL_MIN = 15;

function pad_create(int $patientId, int $apptId = 0, string $mode = 'write'): string {
    $tok = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    /* store both timestamps in UTC so comparisons never depend on the OS timezone */
    db()->prepare('INSERT INTO pad_sessions(token,patient_id,appt_id,mode,created_at,expires_at)
                   VALUES(?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL '.PAD_TTL_MIN.' MINUTE))')
        ->execute([$tok, $patientId, $apptId, $mode]);
    return $tok;
}

function pad_get(string $token): ?array {
    /* let the database do the comparison - no PHP timezone involved */
    $q = db()->prepare('SELECT *, (expires_at < NOW()) AS is_expired
                        FROM pad_sessions WHERE token=?');
    $q->execute([$token]);
    $s = $q->fetch();
    if (!$s) return null;
    if ((int)$s['is_expired'] === 1 && $s['status'] === 'waiting') {
        return ['expired' => true] + $s;
    }
    return $s;
}

function pad_complete(string $token, string $file, string $kind): void {
    db()->prepare('UPDATE pad_sessions SET status="done", result_file=?, result_kind=? WHERE token=?')
        ->execute([$file, $kind, $token]);
}

/* Absolute URL the QR encodes. Must be reachable from the phone, so we use the
   LAN IP the desk browser is talking to rather than "localhost". */
function pad_url(string $token): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme.'://'.$host.$dir.'/pad.php?t='.$token;
}

/* ---------------------------------------------------------------
   Minimal QR encoder (byte mode, ECC level L, mask 0).
   Self-contained so the app keeps its "no composer, no CDN" promise
   and the QR still renders inside the sandboxed preview iframe.
   --------------------------------------------------------------- */
function qr_matrix(string $data): array {
    $len = strlen($data);
    /* pick the smallest version 1-10 that fits byte mode at ECC-L */
    $capL = [1=>17,2=>32,3=>53,4=>78,5=>106,6=>134,7=>154,8=>192,9=>230,10=>271];
    $ver = 0;
    foreach ($capL as $v => $cap) { if ($len <= $cap) { $ver = $v; break; } }
    if (!$ver) throw new RuntimeException('QR data too long');

    $size = 17 + 4 * $ver;
    /* total data codewords for ECC-L, versions 1..10 */
    $dataCw = [1=>19,2=>34,3=>55,4=>80,5=>108,6=>136,7=>156,8=>194,9=>232,10=>274][$ver];
    $eccCw  = [1=>7, 2=>10,3=>15,4=>20,5=>26, 6=>18, 7=>20, 8=>24, 9=>30, 10=>18][$ver];
    $blocks = [1=>1, 2=>1, 3=>1, 4=>1, 5=>1,  6=>2,  7=>2,  8=>2,  9=>2,  10=>2][$ver];

    /* ---- bit stream ---- */
    $bits = '';
    $bits .= '0100';                                   /* byte mode */
    $bits .= str_pad(decbin($len), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    $cap = $dataCw * 8;
    $bits .= str_repeat('0', min(4, max(0, $cap - strlen($bits))));
    while (strlen($bits) % 8) $bits .= '0';
    $pad = ['11101100','00010001']; $k = 0;
    while (strlen($bits) < $cap) { $bits .= $pad[$k++ % 2]; }

    $dcw = [];
    for ($i = 0; $i < $cap; $i += 8) $dcw[] = bindec(substr($bits, $i, 8));

    /* ---- Reed-Solomon ---- */
    $exp = []; $log = []; $x = 1;
    for ($i = 0; $i < 256; $i++) { $exp[$i] = $x; if ($i < 255) $log[$x] = $i;
        $x <<= 1; if ($x & 0x100) $x ^= 0x11d; }
    $gen = [1];
    for ($i = 0; $i < $eccCw; $i++) {
        $ng = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $g) {
            $ng[$j]     ^= $g;
            $ng[$j + 1] ^= ($g ? $exp[($log[$g] + $i) % 255] : 0);
        }
        $gen = $ng;
    }
    $rs = function(array $blk) use ($gen, $eccCw, $exp, $log): array {
        $r = array_merge($blk, array_fill(0, $eccCw, 0));
        for ($i = 0; $i < count($blk); $i++) {
            $c = $r[$i]; if (!$c) continue;
            for ($j = 0; $j < count($gen); $j++) {
                $r[$i + $j] ^= ($gen[$j] ? $exp[($log[$gen[$j]] + $log[$c]) % 255] : 0);
            }
        }
        return array_slice($r, count($blk));
    };

    /* split into blocks, interleave */
    $per = intdiv(count($dcw), $blocks);
    $dBlocks = []; $eBlocks = []; $off = 0;
    for ($b = 0; $b < $blocks; $b++) {
        $n = $per + ($b < count($dcw) % $blocks ? 1 : 0);
        $blk = array_slice($dcw, $off, $n); $off += $n;
        $dBlocks[] = $blk; $eBlocks[] = $rs($blk);
    }
    $final = [];
    $maxD = max(array_map('count', $dBlocks));
    for ($i = 0; $i < $maxD; $i++) foreach ($dBlocks as $b) if (isset($b[$i])) $final[] = $b[$i];
    for ($i = 0; $i < $eccCw; $i++)  foreach ($eBlocks as $b) if (isset($b[$i])) $final[] = $b[$i];

    /* ---- matrix ---- */
    $m = array_fill(0, $size, array_fill(0, $size, null));
    $put = function(int $r, int $c, int $v) use (&$m, $size) {
        if ($r >= 0 && $r < $size && $c >= 0 && $c < $size) $m[$r][$c] = $v; };

    $finder = function(int $r, int $c) use ($put) {
        for ($i = -1; $i <= 7; $i++) for ($j = -1; $j <= 7; $j++) {
            $on = ($i >= 0 && $i <= 6 && ($j === 0 || $j === 6))
               || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6))
               || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
            $put($r + $i, $c + $j, $on ? 1 : 0);
        }
    };
    $finder(0, 0); $finder(0, $size - 7); $finder($size - 7, 0);

    for ($i = 8; $i < $size - 8; $i++) {
        $v = ($i % 2 === 0) ? 1 : 0;
        if ($m[6][$i] === null) $m[6][$i] = $v;
        if ($m[$i][6] === null) $m[$i][6] = $v;
    }
    /* alignment pattern (versions >= 2 use a single extra one for v2-6) */
    $alignPos = [1=>[],2=>[6,18],3=>[6,22],4=>[6,26],5=>[6,30],6=>[6,34],
                 7=>[6,22,38],8=>[6,24,42],9=>[6,26,46],10=>[6,28,50]][$ver];
    foreach ($alignPos as $ar) foreach ($alignPos as $ac) {
        if (($ar <= 7 && $ac <= 7) || ($ar <= 7 && $ac >= $size - 8) || ($ar >= $size - 8 && $ac <= 7)) continue;
        for ($i = -2; $i <= 2; $i++) for ($j = -2; $j <= 2; $j++) {
            $on = (abs($i) === 2 || abs($j) === 2 || ($i === 0 && $j === 0));
            $put($ar + $i, $ac + $j, $on ? 1 : 0);
        }
    }
    $put($size - 8, 8, 1);   /* dark module */
    /* reserve format areas */
    for ($i = 0; $i <= 8; $i++) {
        if ($m[8][$i] === null) $m[8][$i] = 0;
        if ($m[$i][8] === null) $m[$i][8] = 0;
        if ($i < 8) {
            if ($m[8][$size - 1 - $i] === null) $m[8][$size - 1 - $i] = 0;
            if ($m[$size - 1 - $i][8] === null) $m[$size - 1 - $i][8] = 0;
        }
    }
    if ($ver >= 7) {
        for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) {
            if ($m[$i][$size - 11 + $j] === null) $m[$i][$size - 11 + $j] = 0;
            if ($m[$size - 11 + $j][$i] === null) $m[$size - 11 + $j][$i] = 0;
        }
    }

    /* place data, mask 0 */
    $bitStr = '';
    foreach ($final as $cw) $bitStr .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    $idx = 0; $up = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) $col--;
        for ($n = 0; $n < $size; $n++) {
            $row = $up ? $size - 1 - $n : $n;
            for ($c = 0; $c < 2; $c++) {
                $cc = $col - $c;
                if ($m[$row][$cc] !== null) continue;
                $bit = $idx < strlen($bitStr) ? (int)$bitStr[$idx] : 0; $idx++;
                if ((($row + $cc) % 2) === 0) $bit ^= 1;      /* mask 0 */
                $m[$row][$cc] = $bit;
            }
        }
        $up = !$up;
    }

    /* format info: ECC-L (01) + mask 0 (000) */
    $fmt = 0b01000;
    $r = $fmt << 10;
    for ($i = 4; $i >= 0; $i--) if ($r & (1 << ($i + 10))) $r ^= 0b10100110111 << $i;
    $fmtBits = (($fmt << 10) | $r) ^ 0b101010000010010;
    $seq = [];
    for ($i = 14; $i >= 0; $i--) $seq[] = ($fmtBits >> $i) & 1;
    $coords1 = [[8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],[7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]];
    foreach ($coords1 as $k => [$rr,$cc]) $m[$rr][$cc] = $seq[$k];
    for ($i = 0; $i < 8; $i++)  $m[$size - 1 - $i][8] = $seq[$i];
    for ($i = 8; $i < 15; $i++) $m[8][$size - 15 + $i] = $seq[$i];

    foreach ($m as &$row) foreach ($row as &$v) if ($v === null) $v = 0;
    return $m;
}

/* Render the matrix as an inline SVG - works in the sandboxed preview. */
function qr_svg(string $data, int $px = 220): string {
    try { $m = qr_matrix($data); }
    catch (Throwable $e) { return '<div style="font-size:12px;color:#a33">QR unavailable</div>'; }
    $n = count($m); $q = 4; $tot = $n + $q * 2;
    $s  = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$px.'" height="'.$px.'" '
        . 'viewBox="0 0 '.$tot.' '.$tot.'" shape-rendering="crispEdges" role="img" aria-label="Pad QR code">';
    $s .= '<rect width="'.$tot.'" height="'.$tot.'" fill="#fff"/><path fill="#0f2a3d" d="';
    for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++)
        if ($m[$r][$c]) $s .= 'M'.($c + $q).' '.($r + $q).'h1v1h-1z';
    return $s.'"/></svg>';
}
