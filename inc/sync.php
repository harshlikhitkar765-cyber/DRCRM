<?php
/* ==================================================================
   Asset sync.

   The app stamps every CSS and JS file with its modification time so
   a change reaches the browser immediately. This file is the checking
   half: it can tell you, from inside the app, whether the files on the
   server are the ones the app expects — which is what you actually
   want to know after an upload.
   ================================================================== */
declare(strict_types=1);

/* Every asset the app depends on, and roughly how big it should be.
   The minimum sizes catch the common failure: an upload that stopped
   half way and left a truncated file that still "exists". */
function sync_assets(): array {
    return [
        'assets/app.css'        => 20000,
        'assets/design.css'     =>  4000,
        'assets/scribe.js'      => 12000,
        'assets/voice.js'       =>  3000,
        'assets/speakers.js'    =>  3000,
        'assets/summary.js'     =>  3000,
        'assets/responsive.js'  =>  1000,
    ];
}

/* Look at what is actually on disk. */
function sync_report(): array {
    $root = dirname(__DIR__);
    $rows = []; $worst = 'ok';

    foreach (sync_assets() as $rel => $minBytes) {
        $path = $root . '/' . $rel;
        $row  = ['file' => $rel, 'expect' => $minBytes];

        if (!is_file($path)) {
            $row += ['state' => 'missing', 'size' => 0, 'mtime' => null, 'v' => null,
                     'note'  => 'Not on the server — upload the assets folder again.'];
            $worst = 'missing';
        } else {
            $size = (int)filesize($path);
            $row += ['size' => $size, 'mtime' => (int)filemtime($path), 'v' => (string)filemtime($path)];
            if ($size < $minBytes) {
                $row['state'] = 'short';
                $row['note']  = 'Smaller than expected — the upload was probably cut off.';
                if ($worst !== 'missing') $worst = 'short';
            } else {
                $row['state'] = 'ok';
                $row['note']  = '';
            }
        }
        $rows[] = $row;
    }

    return ['rows' => $rows, 'state' => $worst, 'checked_at' => date('d M Y, H:i')];
}

/* A single stamp for the whole asset set. If this changes, something
   was uploaded; if a browser is still showing an old one, it is cached. */
function sync_build(): string {
    $root = dirname(__DIR__); $parts = [];
    foreach (array_keys(sync_assets()) as $rel) {
        $p = $root . '/' . $rel;
        $parts[] = is_file($p) ? filemtime($p) . ':' . filesize($p) : '0:0';
    }
    return substr(hash('sha256', implode('|', $parts)), 0, 10);
}

/* Newest asset time — shown as "last updated". */
function sync_latest(): int {
    $root = dirname(__DIR__); $t = 0;
    foreach (array_keys(sync_assets()) as $rel) {
        $p = $root . '/' . $rel;
        if (is_file($p)) $t = max($t, (int)filemtime($p));
    }
    return $t;
}
