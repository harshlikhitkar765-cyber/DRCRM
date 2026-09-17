/* ------------------------------------------------------------------
   Voice dictation for the consultation form.

   Uses the browser's built-in SpeechRecognition (Chrome / Edge on
   Android and desktop). No server, no API key, no cost. The spoken
   text is parsed against the doctor's OWN drug list, so "paracetamol"
   becomes whatever they call it in their formulary.
   ------------------------------------------------------------------ */
(function () {
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var btn = document.getElementById('micBtn');
  if (!btn) return;
  if (!SR) {
    btn.disabled = true;
    btn.title = 'Voice needs Chrome or Edge';
    btn.textContent = '🎤 Not supported';
    return;
  }

  /* Spoken numbers. Stopping at ten meant "come back in fifteen days" and
     "for thirty days" produced no follow-up and no duration at all —
     ordinary ways to say a month. Note panch was mapped to 6; it is 5. */
  var NUM = {
    one:1,two:2,three:3,four:4,five:5,six:6,seven:7,eight:8,nine:9,ten:10,
    eleven:11,twelve:12,thirteen:13,fourteen:14,fifteen:15,sixteen:16,
    seventeen:17,eighteen:18,nineteen:19,twenty:20,thirty:30,forty:40,
    fifty:50,sixty:60,ninety:90,hundred:100,
    ek:1,do:2,teen:3,char:4,chaar:4,paanch:5,panch:5,chhe:6,cheh:6,saat:7,
    aath:8,nau:9,das:10,gyarah:11,barah:12,pandrah:15,bees:20,tees:30,
    chalis:40,pachas:50,
    half:0.5,aadha:0.5
  };

  var FREQ = [
    [/\b(once|one time|1 time|din me ek|ek baar|1 baar|od)\b/i, 'OD'],
    [/\b(twice|two times|2 times|din me do|do baar|2 baar|bd)\b/i, 'BD'],
    [/\b(thrice|three times|3 times|din me teen|teen baar|3 baar|tds|tid)\b/i, 'TDS'],
    [/\b(four times|4 times|char baar|4 baar|qid)\b/i, 'QID'],
    [/\b(weekly|hafte)\b/i, 'Weekly'],
    [/\b(sos|if needed|zarurat|as needed)\b/i, 'SOS']
  ];
  /* Ordered most specific first: the first rule that matches is used. */
  var WHEN = [
    [/\b(empty stomach|khali pet)\b/i, 'Empty Stomach'],
    [/\b(after food|after meal|khane ke baad|baad)\b/i, 'After Food'],
    [/\b(before food|before meal|khane se pehle|pehle)\b/i, 'Before Food'],
    [/\b(bed ?time|at night|raat ko|sote)\b/i, 'Bedtime'],
    [/\b(with food|khane ke saath)\b/i, 'With Food']
  ];

  function words2num(t) {
    return t.replace(/\b([a-z]+)\b/gi, function (w) {
      var k = w.toLowerCase();
      return NUM[k] !== undefined ? NUM[k] : w;
    });
  }

  /* fuzzy match a spoken name against the doctor's formulary */
  function matchDrug(said) {
    var names = Object.keys(DRUGMAP);
    var s = said.toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
    if (!s) return null;

    /* Brand names first. A doctor says "Dolo 650", not "Paracetamol 650",
       and the patient certainly does. An exact brand word is a far stronger
       signal than a fuzzy match on the generic, so check it before scoring. */
    var BM = window.BRANDMAP || {};
    var words = s.split(' ');
    var spokenMg = (s.match(/\b(\d{2,4})\s*(?:mg)?\b/) || [])[1];
    for (var w = 0; w < words.length; w++) {
      if (words[w].length > 2 && BM[words[w]]) {
        var pick = BM[words[w]];
        /* The brand maps to one drug, but the doctor may stock the same
           molecule in several strengths. "glycomet 500" was returning
           Metformin 1000mg because the map keeps only the first match.
           Prefer the strength that was actually said. */
        if (spokenMg) {
          var gen = pick.toLowerCase().replace(/\d+\s*(mg|mcg|ml)/g, '').trim();
          for (var k = 0; k < names.length; k++) {
            var cand = names[k].toLowerCase();
            if (cand.indexOf(gen.split(/\s+/).pop()) >= 0 && cand.indexOf(spokenMg) >= 0) {
              return names[k];
            }
          }
        }
        return pick;
      }
    }
    var best = null, bestScore = 0;
    names.forEach(function (n) {
      var hay = n.toLowerCase();
      /* Compare whole words, and only count a token if it is a real prefix of
         a word in the drug name. Substring matching used to make "ache" hit
         "s-ache-ts" and prescribe ORS off the word "body ache". */
      var hayWords = hay.split(/[^a-z0-9]+/).filter(Boolean);
      var score = 0;
      s.split(' ').forEach(function (tok) {
        if (tok.length < 4) return;
        for (var i = 0; i < hayWords.length; i++) {
          var hw = hayWords[i];
          if (hw === tok) { score += tok.length + 2; return; }
          /* prefix match handles "paracetamol" vs "paracetamol650" mishearings */
          if (tok.length >= 5 && (hw.indexOf(tok) === 0 || tok.indexOf(hw) === 0)) {
            score += Math.min(tok.length, hw.length); return;
          }
        }
      });
      /* A strength is corroborating evidence, never evidence on its own.
         "temperature 100" was scoring 6 on the 100 in Doxycycline 100mg and
         prescribing an antibiotic nobody mentioned. It only counts once a
         drug WORD has already matched. */
      if (score > 0) {
        var num = s.match(/\b(\d{2,4})\s*(mg|mcg|ml)?\b/);
        if (num && hay.indexOf(num[1]) >= 0) score += 6;
      }
      /* A combination product should not beat the single ingredient the
         doctor actually named. "telma 40" was selecting Telmisartan +
         HCTZ, which is a different prescription. */
      if (/\+|\band\b/.test(hay) && !/\+|\band\b/.test(s)) score -= 3;
      if (score > bestScore) { bestScore = score; best = n; }
    });

    /* Several rows share a molecule at different strengths and forms.
       If a strength was spoken, the row carrying that number wins — a
       spoken "650" must not land on the 250mg/5ml syrup. */
    var mg = (s.match(/\b(\d{2,4})\b/) || [])[1];
    if (best && mg && best.indexOf(mg) < 0) {
      var stem = best.toLowerCase()
        .replace(/^(tab|cap|syp|inj|inh|drops?|cream|spray|gargle|lotion|oint)\s+/, '')
        .split(/\s+/)[0];
      for (var z = 0; z < names.length; z++) {
        var cand = names[z].toLowerCase();
        if (cand.indexOf(stem) >= 0 && cand.indexOf(mg) >= 0) { best = names[z]; break; }
      }
    }

    /* A drug name that is also an everyday clinical word must not be
       matched on that word alone. With 20 drugs this never arose; with
       136, "dry cough" selected Syp Cough Expectorant and "folic" was
       reached from "paanch". Require a prescribing cue nearby. */
    var GENERIC_WORD = /\b(cough|cold|fever|pain|acid|iron|calcium|zinc|multivitamin|antacid|probiotic|expectorant|tonic|gel|drops?|spray|cream|gargle|drink|ready|water|oral|salt|paediatric|pain relief)\b/i;
    var PRESCRIBE_CUE = /\b(tab|tablet|cap|capsule|syp|syrup|inj|inhaler|puff|mg|mcg|ml|give|giving|giv|start|take|lijiye|dijiye|de raha|khaiye|daily|twice|thrice|times|od|bd|tds|qid|sos)\b/i;
    if (best && GENERIC_WORD.test(best) && !PRESCRIBE_CUE.test(s)) return null;

    /* A sentence of plain advice is not a prescription, whatever words it
       happens to share with a product name. "drink plenty of fluids" was
       selecting ORS Ready Drink; "warm salt water gargle" would have hit
       the gargle. With 243 products these collisions multiply, so the
       rule is now about the shape of the sentence, not a word list. */
    var ADVICE_SHAPE = /\b(plenty of|lots of|drink water|take rest|avoid|do not|don'?t|keep|apply warm|stay|walk|gargle with|piye|piyo|khaiye|mat |aaram|parhez)\b/i;
    if (best && ADVICE_SHAPE.test(s) && !PRESCRIBE_CUE.test(s)) return null;

    /* Never guess a medicine out of a sentence that is plainly about
       something else. A vital sign, a test result or a date is not a
       prescription. */
    if (/\b(temperature|temp|pulse|bp|blood pressure|sugar|spo2|weight|saturation)\b/i.test(s) &&
        !/\b(tab|cap|syrup|syp|inj|mg|ml|give|giving|take|start|daily|bd|od|tds)\b/i.test(s)) {
      return null;
    }
    return bestScore >= 4 ? best : null;
  }

  function parseDuration(t0) {
    var t = words2num(String(t0));

    /* "crocin 650 din mein teen baar" means three times A day, not 650
       days. Remove the drug strength before looking for a duration, or
       the milligrams are read as the number of days. */
    t = t.replace(/\b(\d{2,4})\s*(mg|mcg|ml)\b/gi, ' ')
         .replace(/\b(\d{3,4})\s+(?=din\s+mein|a day|per day|times)/gi, ' ');

    /* "din mein teen baar" / "times a day" is a frequency phrase; the
       number beside it is never a duration. */
    t = t.replace(/\b\d+\s*(?:din\s+mein|times?\s+(?:a|per)\s+day)\b/gi, ' ');

    var m = t.match(/\b(\d+(?:\.\d+)?)\s*(day|days|din|week|weeks|hafta|hafte|month|months|mahina|mahine)\b/i);
    if (!m) return '';
    var n = m[1], u = m[2].toLowerCase();
    if (/week|hafta|hafte/.test(u))  return n + (n == 1 ? ' week' : ' weeks');
    if (/month|mahina|mahine/.test(u)) return n + (n == 1 ? ' month' : ' months');
    return n + (n == 1 ? ' day' : ' days');
  }

  /* Split a long utterance into one chunk per medicine */
  function chunks(text) {
    /* Speech recognition returns no punctuation, so a whole consultation
       arrives as one run of words. The medicine's timing must be read from
       the medicine's own phrase — otherwise a timing word from an earlier
       symptom ("raat ko jalan" = burning at night) is taken as the dosing
       instruction. Break immediately BEFORE each drug name, which is where
       the prescribing phrase actually begins. */
    var t = String(text);
    var known = Object.keys(window.DRUGMAP || {})
                  .map(function (n) { return n.toLowerCase().replace(/^(tab|cap|syp|inj)\s+/, ''); })
                  .concat(Object.keys(window.BRANDMAP || {}));
    known.forEach(function (n) {
      var w = String(n).split(/\s+/)[0];
      if (w && w.length > 2) {
        t = t.replace(new RegExp('\\s+(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')\\b', 'gi'),
                      '. $1');
      }
    });
    return t.split(/\b(?:and then|then|also|aur|next|comma)\b|[,;.!?\n]/i)
            .map(function (x) { return x.trim(); })
            .filter(function (x) { return x.length > 2; });
  }

  function emptyRow() {
    var rows = document.querySelectorAll('#medRows tr');
    for (var i = 0; i < rows.length; i++) {
      var n = rows[i].querySelector('[name="med_name[]"]');
      if (n && !n.value.trim()) return rows[i];
    }
    addMed();
    var all = document.querySelectorAll('#medRows tr');
    return all[all.length - 1];
  }

  function applyMed(chunk) {
    var drug = matchDrug(chunk);
    if (!drug) return false;
    var row = emptyRow();
    var t = words2num(chunk);

    row.querySelector('[name="med_name[]"]').value = drug;
    var d = DRUGMAP[drug] || {};
    function set(sel, v) { var el = row.querySelector(sel); if (el && v) el.value = v; }
    set('[name="med_dose[]"]', d.dose); set('[name="med_unit[]"]', d.unit);
    set('[name="med_when[]"]', d.when); set('[name="med_freq[]"]', d.freq);
    set('[name="med_dur[]"]',  d.duration);
    if (d.notes) set('[name="med_notes[]"]', d.notes);

    /* spoken values override the defaults */
    var dose = t.match(/\b(\d+(?:\.\d+)?)\s*(tab|tablet|cap|capsule|ml|puff|sachet|drop|unit)s?\b/i);
    if (dose) { set('[name="med_dose[]"]', dose[1]);
                set('[name="med_unit[]"]', dose[2].toLowerCase().replace('tablet','tab').replace('capsule','cap')); }
    FREQ.forEach(function (f) { if (f[0].test(t) || f[0].test(chunk)) set('[name="med_freq[]"]', f[1]); });
    /* First match wins, so the list order is the priority order. Without
       this, a later rule silently overwrote an earlier, more specific one
       — "khali pet" was being replaced by "raat ko" from a symptom. */
    for (var wi = 0; wi < WHEN.length; wi++) {
      if (WHEN[wi][0].test(t) || WHEN[wi][0].test(chunk)) {
        set('[name="med_when[]"]', WHEN[wi][1]);
        break;
      }
    }
    var du = parseDuration(t); if (du) set('[name="med_dur[]"]', du);
    return true;
  }

  function applyLab(text) {
    var input = document.getElementById('labsInput');
    if (!input) return false;
    var found = [];
    (window.LABLIST || []).forEach(function (l) {
      var k = l.toLowerCase().replace(/[^a-z0-9]/g, '');
      var h = text.toLowerCase().replace(/[^a-z0-9]/g, '');
      if (k.length > 2 && h.indexOf(k) >= 0) found.push(l);
    });
    if (!found.length) return false;
    var cur = input.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
    found.forEach(function (f) { if (cur.indexOf(f) < 0) cur.push(f); });
    input.value = cur.join(', ');
    return true;
  }

  /* Share the parsing brain with the ambient scribe. */
  window.RxParse = { matchDrug: matchDrug, parseDuration: parseDuration,
                     chunks: chunks, words2num: words2num,
                     FREQ: FREQ, WHEN: WHEN, applyMed: applyMed,
                     applyLab: applyLab, emptyRow: emptyRow };

  var rec = new SR();
  rec.continuous = true;
  rec.interimResults = true;
  rec.lang = 'en-IN';
  var listening = false, heard = '';

  var out = document.getElementById('micOut');
  function say(msg, cls) { if (out) { out.textContent = msg; out.className = 'mic-out ' + (cls || ''); } }

  btn.onclick = function () {
    if (listening) { rec.stop(); return; }
    rec.lang = document.getElementById('micLang').value;
    heard = '';
    try { rec.start(); } catch (e) { /* already started */ }
  };

  rec.onstart = function () {
    listening = true; btn.classList.add('rec'); btn.textContent = '⏹ Stop';
    say('Listening… say “Paracetamol 650 twice daily for 3 days”', 'live');
  };
  rec.onerror = function (e) {
    say(e.error === 'not-allowed'
      ? 'Microphone blocked — allow mic access in the browser.'
      : 'Voice error: ' + e.error, 'err');
  };
  rec.onend = function () {
    listening = false; btn.classList.remove('rec'); btn.textContent = '🎤 Dictate';
    if (!heard.trim()) { say('Nothing heard.', ''); return; }
    var parts = chunks(heard), okMed = 0, okLab = 0;
    parts.forEach(function (c) {
      if (applyMed(c)) okMed++;
      else if (applyLab(c)) okLab++;
    });
    if (!okMed && !okLab) {
      var dg = document.querySelector('[name="diagnosis"]');
      if (dg && !dg.value.trim()) { dg.value = heard.trim(); okMed = -1; }
    }
    var msg = 'Heard: “' + heard.trim() + '”';
    if (okMed > 0)  msg += ' → ' + okMed + ' medicine' + (okMed > 1 ? 's' : '') + ' added';
    if (okLab > 0)  msg += ' → tests added';
    if (okMed === 0 && okLab === 0) msg += ' → no medicine matched your list';
    if (okMed === -1) msg += ' → put into Diagnosis';
    say(msg, okMed > 0 || okLab > 0 ? 'ok' : 'err');
    if (typeof preview === 'function') preview();
    if (typeof bindAuto === 'function') bindAuto();
  };
  rec.onresult = function (ev) {
    var txt = '';
    for (var i = 0; i < ev.results.length; i++) txt += ev.results[i][0].transcript + ' ';
    heard = txt;
    say('… ' + txt.trim(), 'live');
  };
})();
