/* ------------------------------------------------------------------
   Make wide tables usable on a phone.

   A clinic list has 6-8 columns. On a 390px screen that means names
   wrapping to three lines and buttons falling off the edge. Below
   760px each row becomes a card, and every cell is labelled with its
   own column heading so nothing loses its meaning.

   This reads the labels from the table's own <th> cells, so any table
   added later is handled without touching this file. Tables that opt
   out with class="no-stack" are left alone (they scroll instead).
   ------------------------------------------------------------------ */
(function () {
  var MOBILE = '(max-width:760px)';

  function label(table) {
    if (table.dataset.stacked === '1') return;

    var heads = [].map.call(table.querySelectorAll('thead th'), function (th) {
      return (th.textContent || '').trim();
    });
    if (heads.length < 4) return;          /* narrow tables are fine as they are */

    [].forEach.call(table.querySelectorAll('tbody tr'), function (tr) {
      [].forEach.call(tr.children, function (td, i) {
        if (td.hasAttribute('data-l')) return;      /* hand-tuned already */
        if (td.hasAttribute('colspan')) return;     /* empty-state row */
        var h = heads[i] || '';
        /* The headline cell and the button row explain themselves — a
           "PATIENT" label above the patient's name is just noise. */
        if (i === 0 || /^(action|actions)$/i.test(h)) h = '';
        /* A cell holding only buttons or only a number needs no label. */
        var onlyBtns = td.querySelector('.btn,button') && !td.textContent.trim().replace(/\s+/g, '').length;
        td.setAttribute('data-l', (onlyBtns || /^#$|^$/.test(h)) ? '' : h);
        if (td.querySelector('.btn,button')) td.classList.add('q-act');
      });
      /* first cell that links to a patient/record is the card headline */
      var first = tr.querySelector('td');
      if (first && !first.classList.contains('q-name')) {
        var lead = tr.querySelector('td a.pname, td a');
        if (lead && lead.closest('td') === first) first.classList.add('q-name');
      }
    });
    table.classList.add('stack');
    table.dataset.stacked = '1';
  }

  function apply() {
    if (!window.matchMedia || !window.matchMedia(MOBILE).matches) return;
    [].forEach.call(document.querySelectorAll('table'), function (t) {
      if (t.classList.contains('no-stack') || t.classList.contains('rxt')) return;
      label(t);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else { apply(); }

  /* Rotating the phone should not leave a half-converted table. */
  if (window.matchMedia) {
    var mq = window.matchMedia(MOBILE);
    (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(apply);
  }
})();

/* ------------------------------------------------------------------
   Drawer navigation on small screens.

   The sidebar and the drawer are the same element — it is simply slid
   off-screen below 760px. That means one set of navigation markup to
   maintain instead of two that can drift apart.
   ------------------------------------------------------------------ */
(function () {
  var burger = document.getElementById('burger');
  var rail   = document.getElementById('rail');
  var scrim  = document.getElementById('scrim');
  if (!burger || !rail || !scrim) return;

  function open(yes) {
    rail.classList.toggle('open', yes);
    burger.setAttribute('aria-expanded', yes ? 'true' : 'false');
    if (yes) {
      scrim.hidden = false;
      requestAnimationFrame(function () { scrim.classList.add('show'); });
      document.body.style.overflow = 'hidden';   /* stop the page scrolling behind */
    } else {
      scrim.classList.remove('show');
      setTimeout(function () { scrim.hidden = true; }, 240);
      document.body.style.overflow = '';
    }
  }

  burger.addEventListener('click', function () {
    open(!rail.classList.contains('open'));
  });
  scrim.addEventListener('click', function () { open(false); });

  /* Escape closes it, and tapping a link closes it before navigating. */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && rail.classList.contains('open')) open(false);
  });
  rail.addEventListener('click', function (e) {
    if (e.target.closest('a')) open(false);
  });

  /* Rotating to a wide screen must not leave the drawer state stuck on. */
  if (window.matchMedia) {
    var mq = window.matchMedia('(min-width:761px)');
    var off = function (m) { if (m.matches) open(false); };
    mq.addEventListener ? mq.addEventListener('change', off) : mq.addListener(off);
  }
})();
