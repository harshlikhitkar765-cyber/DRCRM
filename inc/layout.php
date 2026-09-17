<?php
declare(strict_types=1);
require_once __DIR__ . '/refdata.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/config.php';

function require_login(): void {
    app_session_start();
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) redirect('login.php');
}

/* Clinical and system-administration actions must stay with a doctor. The
   front-desk account can run the queue, registration and billing, but cannot
   issue prescriptions, alter the formulary or download the full database. */
function require_doctor(): void {
    require_login();
    if (!is_doctor()) {
        http_response_code(403);
        exit('Doctor access is required for this action.');
    }
}

/* ------------------------------------------------------------------
   Navigation.

   Icons are inline SVG on a 24x24 grid, drawn with currentColor and a
   1.7 stroke so they inherit the active/hover colour and stay crisp at
   any zoom. Emoji were used before, but they render differently on
   every operating system, cannot be recoloured, and read as toy-like
   on a screen a patient may be looking at.

   Items are grouped the way the clinic day actually runs, so the menu
   reads as a workflow rather than an alphabetical pile.
   ------------------------------------------------------------------ */
function nav_icon(string $k): string {
    $p = [
        'queue'     => '<path d="M4 6h16M4 12h16M4 18h10"/><circle cx="19" cy="18" r="2.2"/>',
        'patients'  => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
        'homecare'  => '<path d="M4 11.2 12 4l8 7.2"/><path d="M6.4 10.4V20h11.2v-9.6"/><path d="M12 13v4M10 15h4"/>',
        'recalls'   => '<circle cx="12" cy="13" r="7.2"/><path d="M12 9.4V13l2.4 1.6"/><path d="M5 4.2 7.6 2.4M19 4.2 16.4 2.4"/>',
        'drugs'     => '<rect x="2.8" y="9.4" width="18.4" height="7.2" rx="3.6" transform="rotate(-45 12 13)"/><path d="M9.2 9.2 14.8 14.8"/>',
        'billing'   => '<path d="M7 5h10M7 9.4h10M7 5v14l3.5-2.4L14 19l3-2.4V5"/><path d="M9.6 13.4h6"/>',
        'replies'   => '<path d="M9 5.4 3.8 10.6 9 15.8"/><path d="M3.8 10.6h9.4a6.6 6.6 0 0 1 6.6 6.6v1.4"/>',
        'whatsapp'  => '<path d="M12 3.6a8.4 8.4 0 0 0-7.2 12.7L3.6 20.4l4.2-1.2A8.4 8.4 0 1 0 12 3.6Z"/><path d="M9.1 8.4c.3 0 .5.5.8 1.2.2.5-.4.7-.5 1a5.6 5.6 0 0 0 2.9 2.6c.4.1.6-.6 1-.5.7.2 1.3.5 1.3.8a1.9 1.9 0 0 1-1.5 1.5 7.1 7.1 0 0 1-5.2-5.2 1.9 1.9 0 0 1 1.2-1.4Z"/>',
        'templates' => '<rect x="4.2" y="3.6" width="15.6" height="16.8" rx="2.4"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'backup'    => '<ellipse cx="12" cy="6.4" rx="7.2" ry="2.8"/><path d="M4.8 6.4v11.2c0 1.5 3.2 2.8 7.2 2.8s7.2-1.3 7.2-2.8V6.4"/><path d="M4.8 12c0 1.5 3.2 2.8 7.2 2.8s7.2-1.3 7.2-2.8"/>',
        'tv'        => '<rect x="3" y="4.6" width="18" height="12" rx="2"/><path d="M8.5 20h7M12 16.6V20"/>',
        'settings'  => '<circle cx="12" cy="12" r="3.1"/><path d="M19.4 14.4a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5v.2a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1h-.2a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3h.1a1.6 1.6 0 0 0 1-1.5v-.2a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8v.1a1.6 1.6 0 0 0 1.5 1h.2a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z"/>',
        'logout'    => '<path d="M12 3.4v8.2"/><path d="M7.6 6a7.4 7.4 0 1 0 8.8 0"/>',
    ];
    $d = $p[$k] ?? $p['queue'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $d . '</svg>';
}

function nav_groups(): array {
    $groups = [
        'Today' => [
            ['queue.php',    'queue',    'OPD Queue'],
            ['display.php',  'tv',       'TV Board'],
        ],
        'Records' => [
            ['patients.php', 'patients', 'Patients'],
            ['homecare.php', 'homecare', 'Home Care'],
            ['billing.php',  'billing',  'Billing'],
        ],
    ];

    if (is_doctor()) {
        $groups['Today'][] = ['recall.php', 'recalls', 'Recalls'];
        $groups['Messaging'] = [
            ['inbox.php',    'replies',  'Replies'],
            ['messages.php', 'whatsapp', 'WhatsApp'],
            ['templates.php','templates','Templates'],
        ];
        $groups['Setup'] = [
            ['drugs.php',    'drugs',    'Drugs'],
            ['settings.php', 'settings', 'Settings'],
            ['backup.php',   'backup',   'Backup'],
        ];
    }
    return $groups;
}

/* Kept so any older code calling nav_items() still works. */
function nav_items(): array {
    $out = [];
    foreach (nav_groups() as $items) foreach ($items as $i) $out[] = $i;
    return $out;
}

/* Cache-busting stamp. A stylesheet edited on the server must reach the
   browser immediately — otherwise the doctor sees a half-styled page and
   there is no obvious way to tell them to hard-refresh. filemtime changes
   only when the file changes, so caching still works the rest of the time. */
function asset_v(string $rel): string {
    $f = __DIR__ . '/../' . ltrim($rel, '/');
    return is_file($f) ? (string)filemtime($f) : (string)time();
}

function head(string $title): void {
    $cur = basename($_SERVER['PHP_SELF']);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<meta name="app-build" content="<?= e(sync_build()) ?>">
<link rel="stylesheet" href="assets/app.css?v=<?= asset_v('assets/app.css') ?>">
</head>
<body>
<div class="shell">
  <a class="skip" href="#main">Skip to content</a>
  <div class="scrim" id="scrim" hidden></div>
  <aside class="rail" id="rail" aria-label="Main navigation">

    <a class="rbrand" href="queue.php" title="<?= e(clinic('name')) ?>">
      <span class="rlogo" aria-hidden="true">B</span>
      <span class="rbrand-t">
        <b>Dr Bakshi</b>
        <i>Clinic</i>
      </span>
    </a>

    <nav class="rnav">
    <?php $u = $_SESSION['user'] ?? []; ?>
    <?php foreach (nav_groups() as $group => $items): ?>
      <div class="rgroup" role="group" aria-label="<?= e($group) ?>">
        <div class="rgroup-t"><?= e($group) ?></div>
        <?php foreach ($items as [$href,$icon,$label]):
          $on = ($cur === $href); ?>
          <a class="rail-item <?= $on ? 'on' : '' ?>" href="<?= $href ?>"
             <?= $on ? 'aria-current="page"' : '' ?> title="<?= e($label) ?>">
            <span class="ic"><?= nav_icon($icon) ?></span>
            <span class="tx"><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </nav>

    <div class="rail-sp"></div>

    <div class="rfoot">
      <div class="ruser">
        <span class="ruser-av" aria-hidden="true"><?php
          $nm = (string)($u['name'] ?? 'User');
          $pp = preg_split('/\s+/', trim($nm));
          echo e(mb_strtoupper(mb_substr($pp[0] ?? 'U', 0, 1) . (count($pp) > 1 ? mb_substr(end($pp), 0, 1) : '')));
        ?></span>
        <span class="ruser-t">
          <b><?= e($u['name'] ?? 'User') ?></b>
          <i><?= e(ucfirst((string)($u['role'] ?? 'staff'))) ?></i>
        </span>
      </div>
      <a class="rail-item rlogout" href="logout.php" title="Sign out">
        <span class="ic"><?= nav_icon('logout') ?></span>
        <span class="tx">Sign out</span>
      </a>
    </div>
  </aside>
  <main id="main">
    <header>
      <button type="button" class="burger" id="burger"
              aria-label="Menu" aria-expanded="false" aria-controls="rail">
        <span></span><span></span><span></span>
      </button>
      <span class="hlogo"><?= e($title) ?></span>
      <span class="hsub"><?= e(clinic('hours')) ?></span>
      <div class="spacer"></div>
      <span class="wa-badge <?= WA['driver']==='cloud'?'live':'' ?>">
        WhatsApp: <?= WA['driver']==='cloud' ? 'Cloud API' : 'Click-to-chat' ?></span>
      <div class="uav" title="<?= e($_SESSION['user']['name'] ?? '') ?>">
        <?= e(mb_substr($_SESSION['user']['name'] ?? 'U',0,1)) ?><?php
        $p = explode(' ', (string)($_SESSION['user']['name'] ?? '')); echo e(mb_substr(end($p),0,1)); ?></div>
    </header>
    <div class="wrap">
    <?php
    foreach (['ok'=>'flash-ok','err'=>'flash-err'] as $k=>$cls) {
        if (!empty($_SESSION[$k])) { echo '<div class="'.$cls.'">'.e($_SESSION[$k]).'</div>'; unset($_SESSION[$k]); }
    }
}

function foot(): void { ?>
    </div>
  </main>
</div>
<script src="assets/responsive.js?v=<?= asset_v('assets/responsive.js') ?>"></script>
<script src="assets/sync.js?v=<?= asset_v('assets/sync.js') ?>"></script>
</body>
</html><?php }
