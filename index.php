<?php
/* Entry point. This is a web app, not a marketing site — go straight to
   work if signed in, otherwise to the sign-in screen. */
declare(strict_types=1);
require_once __DIR__.'/inc/config.php';
app_session_start();
redirect(empty($_SESSION['user']) ? 'login.php' : 'queue.php');
