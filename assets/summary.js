/* ------------------------------------------------------------------
   The end-of-consultation record.

   When the doctor stops recording, everything the conversation
   produced is gathered into one summary: the disease, what the
   patient reported, what the doctor found and decided, the
   medicines, the tests, the advice and the follow-up.

   This is the "main points" record for the visit. It is built from
   what was actually said, shown on screen for the doctor to read,
   and saved with the consultation.
   ------------------------------------------------------------------ */
(function () {

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function hhmm(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }

  function dur(secs) {
    if (!secs || secs < 1) return '';
    var m = Math.floor(secs / 60), s = secs % 60;
    if (m < 1) return s + ' sec';
    return m + ' min' + (s ? ' ' + s + ' sec' : '');
  }

  /* Build the record. Everything here came from the conversation. */
  function build(o) {
    var d = o.draft || {};
    /* startedAt is a local "YYYY-MM-DD HH:MM:SS" stamp; Safari and Chrome
       disagree on parsing that, so split it rather than trusting Date(). */
    var started = null;
    if (o.startedAt) {
      var m = String(o.startedAt).match(/^(\d{4})-(\d\d)-(\d\d)[T ](\d\d):(\d\d):(\d\d)/);
      started = m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6])
                  : new Date(o.startedAt);
      if (isNaN(started.getTime())) started = null;
    }
    /* Use the server's clock for the end time too, so the header never mixes
       a server start with a browser end. */
    var ended = new Date();
    var em = o.endedAt && String(o.endedAt).match(/^(\d\d):(\d\d)/);
    var endLabel = em ? em[1] + ':' + em[2] : hhmm(ended);
    /* Prefer the server's measurement; fall back to the local clock. */
    var secs = (typeof o.forceSecs === 'number' && o.forceSecs > 0)
             ? o.forceSecs
             : (started ? Math.max(0, Math.round((ended - started) / 1000)) : 0);

    return {
      disease:   o.disease || '',
      when:      (started ? hhmm(started) + '–' : '') + endLabel,
      took:      dur(secs),
      secs:      secs,
      lang:      o.lang || 'en-IN',

      complaints: (d.symptoms || []).slice(),
      duration:   d.dur || '',
      vitals:     d.vitals || {},
      diagnosis:  d.diagnosis || '',
      meds:       (d.meds || []).map(function (m) {
                    return {
                      name: m.name, dose: m.dose, unit: m.unit,
                      when: m.when, freq: m.freq, dur: m.dur
                    };
                  }),
      labs:       (d.labs || []).slice(),
      advice:     (d.advice || []).slice(),
      followUp:   d.followUp ? d.followUp.iso : '',
      followDays: d.followUp ? d.followUp.days : 0,

      patientSaid: (o.ptPoints || []).slice(),
      doctorSaid:  (o.drPoints || []).slice(),

      turns:  (o.turns || []).length,
      words:  (o.text || '').split(/\s+/).filter(Boolean).length
    };
  }

  /* What is missing that a prescription usually has. Worth telling the
     doctor before they close the visit. */
  function gaps(s) {
    var g = [];
    if (!s.diagnosis)              g.push('no diagnosis was stated');
    if (!s.meds.length)            g.push('no medicine was mentioned');
    if (!Object.keys(s.vitals).length) g.push('no vitals were mentioned');
    if (!s.followUp)               g.push('no follow-up was given');
    return g;
  }

  var VL = { bp:'BP', sugar:'Sugar', temp:'Temp', pulse:'Pulse',
             spo2:'SpO2', weight:'Weight' };

  function esc(x) {
    return String(x == null ? '' : x)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function li(arr) {
    return arr.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('');
  }

  /* The card the doctor reads after they press stop. */
  function html(s) {
    var v = Object.keys(s.vitals).map(function (k) {
      return VL[k] + ' ' + s.vitals[k];
    });
    var meds = s.meds.map(function (m) {
      return m.name + ' — ' + [m.dose, m.unit].filter(Boolean).join(' ') +
             ' · ' + [m.when, m.freq, m.dur].filter(Boolean).join(' · ');
    });

    var h = '<div class="sumcard">';
    h += '<div class="sum-head"><b>Consultation record</b>'
       + '<span>' + esc(s.when) + (s.took ? ' · ' + esc(s.took) : '') + '</span></div>';

    if (s.disease) h += '<div class="sum-dx">🩺 ' + esc(s.disease) + '</div>';

    function block(title, body, cls) {
      if (!body) return '';
      return '<div class="sum-b ' + (cls || '') + '"><div class="sum-t">' + title + '</div>' + body + '</div>';
    }

    h += block('Complaints',
          s.complaints.length
            ? '<div>' + esc(s.complaints.join(', ')) +
              (s.duration ? ' <i>— since ' + esc(s.duration) + '</i>' : '') + '</div>'
            : '');
    h += block('Vitals',    v.length    ? '<div>' + esc(v.join(' · ')) + '</div>' : '');
    h += block('Diagnosis', s.diagnosis ? '<div><b>' + esc(s.diagnosis) + '</b></div>' : '');
    h += block('Medicines', meds.length ? '<ul>' + li(meds) + '</ul>' : '');
    h += block('Tests',     s.labs.length   ? '<div>' + esc(s.labs.join(', ')) + '</div>' : '');
    h += block('Advice',    s.advice.length ? '<ul>' + li(s.advice) + '</ul>' : '');
    h += block('Follow-up', s.followUp
            ? '<div>' + esc(s.followUp) +
              (s.followDays ? ' <i>(in ' + s.followDays + ' days)</i>' : '') + '</div>' : '');

    h += '<div class="sum-two">'
       +   block('Patient said', s.patientSaid.length ? '<ul>' + li(s.patientSaid) + '</ul>' : '', 'sum-pt')
       +   block('Doctor said',  s.doctorSaid.length  ? '<ul>' + li(s.doctorSaid)  + '</ul>' : '', 'sum-dr')
       + '</div>';

    var g = gaps(s);
    if (g.length) {
      h += '<div class="sum-gap"><b>Not mentioned in the conversation:</b> '
         + esc(g.join(', ')) + '. Add it by hand if the visit needs it.</div>';
    }

    h += '<div class="sum-foot">' + s.turns + ' turns · ' + s.words
       + ' words · saved with this visit</div>';
    h += '</div>';
    return h;
  }

  /* Plain text, for copying into another system or a WhatsApp message. */
  function text(s) {
    var L = [];
    L.push('CONSULTATION RECORD' + (s.when ? '  (' + s.when + (s.took ? ', ' + s.took : '') + ')' : ''));
    if (s.disease) L.push('Disease: ' + s.disease);
    if (s.complaints.length) L.push('Complaints: ' + s.complaints.join(', ') + (s.duration ? ' (since ' + s.duration + ')' : ''));
    var v = Object.keys(s.vitals).map(function (k) { return VL[k] + ' ' + s.vitals[k]; });
    if (v.length) L.push('Vitals: ' + v.join(', '));
    if (s.diagnosis) L.push('Diagnosis: ' + s.diagnosis);
    s.meds.forEach(function (m, i) {
      L.push('Medicine ' + (i + 1) + ': ' + m.name + ' ' +
             [m.dose, m.unit, m.when, m.freq, m.dur].filter(Boolean).join(' · '));
    });
    if (s.labs.length)   L.push('Tests: ' + s.labs.join(', '));
    if (s.advice.length) L.push('Advice: ' + s.advice.join('; '));
    if (s.followUp)      L.push('Follow-up: ' + s.followUp);
    if (s.patientSaid.length) L.push('\nPatient said:\n- ' + s.patientSaid.join('\n- '));
    if (s.doctorSaid.length)  L.push('\nDoctor said:\n- '  + s.doctorSaid.join('\n- '));
    return L.join('\n');
  }

  window.RxSummary = { build: build, html: html, text: text, gaps: gaps };
})();
