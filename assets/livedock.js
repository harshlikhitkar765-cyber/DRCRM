/* ------------------------------------------------------------------
   Live consultation dock.

   While recording, the doctor needs to watch two things at once: the
   words being heard, and the prescription being built from them. On
   this page those are 300px apart and the page is taller than the
   screen, so only one is ever visible.

   This pins a panel to the bottom of the screen for the length of the
   recording: the transcript on the left, and every item captured so
   far on the right, appearing as it is found. It disappears when the
   recording stops.
   ------------------------------------------------------------------ */
(function () {
  var dock, tBody, iBody, cCount, timer = null, seen = {};

  var LBL = {
    bp: 'BP', temp: 'Temp', pulse: 'Pulse',
    sugar: 'Sugar', spo2: 'SpO2', weight: 'Weight'
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function build() {
    if (dock) return;
    dock = document.createElement('div');
    dock.className = 'ldock';
    dock.innerHTML =
      '<div class="ldock-head">' +
        '<span class="ldock-live"><i></i>Listening</span>' +
        '<span class="ldock-ttl">Live consultation</span>' +
        '<span class="ldock-n" id="ldockN">nothing yet</span>' +
        '<button type="button" class="ldock-min" id="ldockMin" ' +
                'aria-label="Hide panel" title="Hide">–</button>' +
      '</div>' +
      '<div class="ldock-body">' +
        '<div class="ldock-col ldock-words">' +
          '<div class="ldock-k">What is being heard</div>' +
          '<div class="ldock-t" id="ldockT"><i>Speak normally…</i></div>' +
        '</div>' +
        '<div class="ldock-col ldock-found">' +
          '<div class="ldock-k">Going into the prescription</div>' +
          '<div class="ldock-i" id="ldockI"><i>Nothing found yet.</i></div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(dock);

    tBody  = document.getElementById('ldockT');
    iBody  = document.getElementById('ldockI');
    cCount = document.getElementById('ldockN');

    document.getElementById('ldockMin').onclick = function () {
      dock.classList.toggle('small');
      this.textContent = dock.classList.contains('small') ? '+' : '–';
      this.setAttribute('aria-label',
        dock.classList.contains('small') ? 'Show panel' : 'Hide panel');
    };
  }

  /* Read what the scribe has already worked out, straight from the
     form, so the dock can never disagree with what will be saved. */
  function readForm() {
    var out = [];

    /* The illness and the patient's own complaints come first: that is
       what the doctor is looking for at a glance, and it was missing
       entirely — only the diagnosis field was shown. */
    var sc = (window.ScribeLive && window.ScribeLive.draft) ? window.ScribeLive.draft() : null;
    if (sc && sc.symptoms && sc.symptoms.length) {
      out.push({ k: 'cc', t: 'Problem', v: sc.symptoms.join(', '),
                 sub: sc.dur ? 'since ' + sc.dur : '', big: true });
    }

    var dx = document.querySelector('[name="diagnosis"]');
    if (dx && dx.value.trim()) out.push({ k: 'dx', t: 'Illness', v: dx.value.trim(), big: true });

    Object.keys(LBL).forEach(function (k) {
      var el = document.querySelector('[name="v_' + k + '"]');
      if (el && el.value.trim()) out.push({ k: 'v' + k, t: LBL[k], v: el.value.trim() });
    });

    document.querySelectorAll('[name="med_name[]"]').forEach(function (el, i) {
      if (!el.value.trim()) return;
      var row = el.closest('tr');
      var f = row ? row.querySelector('[name="med_freq[]"]') : null;
      var d = row ? row.querySelector('[name="med_dur[]"]')  : null;
      out.push({
        k: 'm' + i, t: 'Medicine', v: el.value.trim(),
        sub: [f && f.value, d && d.value].filter(Boolean).join(' · ')
      });
    });

    var labs = document.getElementById('labsInput');
    if (labs && labs.value.trim()) out.push({ k: 'lab', t: 'Tests', v: labs.value.trim() });

    var fu = document.querySelector('[name="follow_up"]');
    if (fu && fu.value.trim()) out.push({ k: 'fu', t: 'Follow-up', v: fu.value.trim() });

    return out;
  }

  function paint() {
    if (!dock) return;

    /* transcript — show the tail, which is what is being said now */
    var box = document.getElementById('scribeText');
    var txt = box ? box.value.trim() : '';
    if (txt) {
      tBody.textContent = txt.length > 420 ? '…' + txt.slice(-420) : txt;
      tBody.scrollTop = tBody.scrollHeight;
    }

    /* captured items */
    var items = readForm();
    if (!items.length) {
      iBody.innerHTML = '<i>Nothing found yet.</i>';
      cCount.textContent = 'nothing yet';
      return;
    }

    var h = '';
    items.forEach(function (it) {
      var isNew = !seen[it.k + '=' + it.v];
      seen[it.k + '=' + it.v] = 1;
      h += '<div class="ldock-item' + (isNew ? ' pop' : '') + (it.big ? ' big' : '') + '">' +
             '<span class="ldock-tick" aria-hidden="true">✓</span>' +
             '<span class="ldock-lab">' + esc(it.t) + '</span>' +
             '<span class="ldock-val">' + esc(it.v) +
               (it.sub ? ' <em>' + esc(it.sub) + '</em>' : '') +
             '</span></div>';
    });
    iBody.innerHTML = h;
    /* Only chase the bottom once the list actually overflows, so short
       prescriptions stay fully visible from the top. */
    if (iBody.scrollHeight > iBody.clientHeight + 4) iBody.scrollTop = iBody.scrollHeight;
    cCount.textContent = items.length + (items.length === 1 ? ' item' : ' items') + ' captured';
  }

  function start() {
    build();
    seen = {};
    dock.classList.add('on');
    document.body.classList.add('has-ldock');
    if (!timer) timer = setInterval(paint, 900);
    paint();
  }

  function stop() {
    if (!dock) return;
    if (timer) { clearInterval(timer); timer = null; }
    paint();
    /* leave it up briefly so the last few items are seen, then clear */
    dock.classList.add('done');
    var d = dock;
    setTimeout(function () {
      d.classList.remove('on', 'done');
      document.body.classList.remove('has-ldock');
    }, 2600);
  }

  window.LiveDock = { start: start, stop: stop, paint: paint };
})();
