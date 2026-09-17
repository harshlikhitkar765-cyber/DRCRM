/* ------------------------------------------------------------------
   Keeps the open page in step with the server.

   The problem this solves: a page that has been sitting open on the
   clinic PC all evening is still running the CSS and JavaScript it
   loaded hours ago. If the app is updated in between, the doctor is
   using an old copy and has no way of knowing.

   Every few minutes — and whenever the tab is brought back to the
   front — this asks the server for the current build stamp. If it
   differs from the one this page was rendered with, it says so and
   offers to reload. It never reloads on its own: a forced refresh in
   the middle of a half-written prescription would lose work.
   ------------------------------------------------------------------ */
(function () {
  var meta = document.querySelector('meta[name="app-build"]');
  if (!meta) return;

  var mine   = meta.content;
  var every  = 5 * 60 * 1000;     /* check every five minutes */
  var last   = 0;
  var shown  = false;

  function unsavedWork() {
    /* Do not nag mid-consultation. If anything has been typed into the
       prescription form, the doctor is working — stay quiet until they
       have saved and moved on. */
    var f = document.querySelector('form[method="post"]');
    if (!f) return false;
    var typed = false;
    f.querySelectorAll('input[type=text],input:not([type]),textarea,select').forEach(function (el) {
      if (el.name && el.name.indexOf('csrf') === 0) return;
      if (el.tagName === 'SELECT') return;
      if ((el.value || '').trim() !== '' && (el.defaultValue || '') !== el.value) typed = true;
    });
    return typed;
  }

  function banner(build) {
    if (shown) return;
    shown = true;

    var bar = document.createElement('div');
    bar.className = 'syncbar';
    bar.setAttribute('role', 'status');
    bar.innerHTML =
      '<span class="syncbar-i" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
          'stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M20.5 12a8.5 8.5 0 0 1-14.6 6"/><path d="M3.5 12a8.5 8.5 0 0 1 14.6-6"/>' +
          '<path d="M18.1 2.6V6h-3.4"/><path d="M5.9 21.4V18h3.4"/>' +
        '</svg></span>' +
      '<span class="syncbar-t"><b>The clinic app has been updated.</b> ' +
        'This page is still running the older version.</span>' +
      '<button type="button" class="syncbar-b" id="syncGo">Reload now</button>' +
      '<button type="button" class="syncbar-x" id="syncNo" aria-label="Dismiss">×</button>';
    document.body.appendChild(bar);
    requestAnimationFrame(function () { bar.classList.add('on'); });

    document.getElementById('syncGo').onclick = function () {
      /* Cache-bust the reload itself, so we cannot land on the old copy. */
      var u = new URL(window.location.href);
      u.searchParams.set('_b', build);
      window.location.replace(u.toString());
    };
    document.getElementById('syncNo').onclick = function () {
      bar.classList.remove('on');
      setTimeout(function () { bar.remove(); }, 200);
      /* Ask again in ten minutes rather than never. */
      shown = false;
      last = Date.now() + (10 * 60 * 1000) - every;
    };
  }

  function check(force) {
    var now = Date.now();
    if (!force && now - last < every) return;
    last = now;

    fetch('api/build.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.build) return;
        if (j.build !== mine && !unsavedWork()) banner(j.build);
      })
      .catch(function () { /* offline or logged out — try again later */ });
  }

  setInterval(function () { check(false); }, 60 * 1000);

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') check(true);
  });

  /* Expose for the Settings page button and for testing. */
  window.AppSync = { check: function () { check(true); }, build: mine };
})();
