<?php
declare(strict_types=1);
require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/refdata.php';
require_once __DIR__.'/inc/auth.php';
require_once __DIR__.'/inc/icons.php';   /* shared ico(), no login needed */
session_start();


/* Real counts from the clinic's own data, so the panel is never a lie. */
try {
    $pdo = db();
    $c = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $stats = ['pt'=>$c('patients'), 'dr'=>$c('drugs'), 'br'=>$c('brands')];
} catch (Throwable $e) { $stats = ['pt'=>0,'dr'=>0,'br'=>0]; }

$err=''; $last='';

/* One-click demo access. Signs in with a known demo account so the app can
   be tried without typing credentials. Guarded exactly like a normal login:
   it is a POST with a CSRF token, it goes through auth_login() so the real
   password still has to match, and it is written to the audit trail. */
const DEMO_ACCOUNTS = [
    'doctor'    => ['drbakshi',  'clinic@2026'],
    'reception' => ['reception', 'front@2026'],
];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['demo'])) {
    csrf_check();
    $which = (string)$_POST['demo'];
    if (isset(DEMO_ACCOUNTS[$which])) {
        [$du, $dp] = DEMO_ACCOUNTS[$which];
        $acct = auth_login($du, $dp);
        if ($acct) {
            session_regenerate_id(true);
            $_SESSION['user'] = $acct;
            $_SESSION['demo'] = true;          /* so the app can say so */
            audit('login_demo', 'user', 0, $du);
            redirect('queue.php');
        }
        $err = 'The demo account could not sign in. Its password may have been changed.';
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $u=trim((string)($_POST['username']??'')); $p=(string)($_POST['password']??'');
    $last=$u;
    $acct = auth_login($u, $p);
    if ($acct) {
        session_regenerate_id(true);
        $_SESSION['user'] = $acct;
        audit('login', 'user', 0, $acct['username']);
        redirect('queue.php');
    }
    audit('login_failed', 'user', 0, $u);
    $err='Invalid username or password.';
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · <?= e(clinic('name')) ?></title>
<link rel="stylesheet" href="assets/design.css?v=<?= filemtime(__DIR__."/assets/design.css") ?>">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__."/assets/app.css") ?>">
</head>
<body class="auth">

<div class="auth-grid">

  <!-- Left: the product, so the sign-in screen is not a dead end -->
  <aside class="auth-side">
    <div class="auth-side-in">
      <div class="auth-brand">
        <span class="lp-mark">B</span>
        <div>
          <b><?= e(clinic('name')) ?></b>
          <i><?= e(clinic('spec')) ?></i>
        </div>
      </div>

      <h2>Write a prescription in seconds, not minutes.</h2>

      <ul class="auth-pts">
        <li><span><?= ico('<path d="M12 3.6a2.9 2.9 0 0 1 2.9 2.9v5.2a2.9 2.9 0 1 1-5.8 0V6.5A2.9 2.9 0 0 1 12 3.6Z"/><path d="M5.9 11.3v.4a6.1 6.1 0 0 0 12.2 0v-.4"/><path d="M12 17.8v2.6"/>') ?></span>
          <div><b>Speak it</b>Say the medicine and the row fills itself.</div></li>
        <li><span><?= ico('<rect x="3" y="6.4" width="18" height="13.2" rx="2.4"/><circle cx="12" cy="13" r="3.4"/><path d="M8.6 6.4 10 4h4l1.4 2.4"/>') ?></span>
          <div><b>Scan it</b>Photograph your handwriting; keep using paper.</div></li>
        <li><span><?= ico('<path d="M20 11.6a7.4 7.4 0 0 1-7.6 7.4 8.4 8.4 0 0 1-3.4-.7L4 20l1.7-4.6A7.2 7.2 0 0 1 4.8 11.6 7.4 7.4 0 0 1 12.4 4 7.4 7.4 0 0 1 20 11.6Z"/><path d="M9 11.6h.01M12 11.6h.01M15 11.6h.01"/>') ?></span>
          <div><b>Let it listen</b>Record the visit; tick what goes in.</div></li>
      </ul>

      <div class="auth-foot">
        <?= number_format($stats['pt']) ?> patients ·
        <?= number_format($stats['dr']) ?> medicines ·
        <?= number_format($stats['br']) ?> Indian brands recognised
      </div>
    </div>
  </aside>

  <!-- Right: the actual job -->
  <main class="auth-main">
    <div class="auth-card">
      <div class="auth-mob">
        <span class="lp-mark">B</span>
        <b><?= e(clinic('name')) ?></b>
      </div>

      <h1>Sign in</h1>
      <p class="auth-sub"><?= e(clinic('doctor')) ?> · <?= e(clinic('qual')) ?></p>

      <?php if ($err): ?>
        <div class="auth-err" role="alert">
          <?= ico('<circle cx="12" cy="12" r="8.6"/><path d="M12 8v4.6M12 15.8h.01"/>') ?>
          <span><?= e($err) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label class="auth-l" for="u">Username</label>
        <input class="auth-i" id="u" name="username" autocomplete="username"
               autofocus required value="<?= e($last) ?>">

        <label class="auth-l" for="p">Password</label>
        <div class="auth-pw">
          <input class="auth-i" id="p" name="password" type="password"
                 autocomplete="current-password" required>
          <button type="button" class="auth-eye" id="eye"
                  aria-label="Show password" title="Show password">
            <?= ico('<path d="M2.6 12S6.4 5.4 12 5.4 21.4 12 21.4 12 17.6 18.6 12 18.6 2.6 12 2.6 12Z"/><circle cx="12" cy="12" r="3"/>') ?>
          </button>
        </div>

        <button class="b b-main auth-go">Sign in</button>
      </form>

      <div class="auth-or"><span>or try it without an account</span></div>

      <form method="post" class="auth-demos">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button class="auth-demo-b" name="demo" value="doctor">
          <span class="adb-ico"><?= ico('<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>') ?></span>
          <span class="adb-t"><b>Enter as the doctor</b><i>Full access — prescribe, record, settings</i></span>
          <span class="adb-go">→</span>
        </button>
        <button class="auth-demo-b" name="demo" value="reception">
          <span class="adb-ico"><?= ico('<rect x="3.2" y="9" width="17.6" height="11" rx="2"/><path d="M7.6 9V6.6a4.4 4.4 0 0 1 8.8 0V9"/>') ?></span>
          <span class="adb-t"><b>Enter as the front desk</b><i>Queue, patients, billing</i></span>
          <span class="adb-go">→</span>
        </button>
      </form>

      <p class="auth-note">
        Demo signs in as <code>drbakshi</code> / <code>reception</code> with the
        sample clinic data. <b>Change both passwords before real patient data goes in.</b>
      </p>
    </div>
  </main>
</div>

<script>
(function(){
  var p=document.getElementById('p'), e=document.getElementById('eye');
  if(!p||!e) return;
  e.onclick=function(){
    var show = p.type==='password';
    p.type = show ? 'text' : 'password';
    e.classList.toggle('on', show);
    e.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    p.focus();
  };
})();
</script>
</body></html>
