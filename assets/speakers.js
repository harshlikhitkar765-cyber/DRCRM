/* ------------------------------------------------------------------
   Splitting a consultation into "what the doctor said" and
   "what the patient said".

   One microphone cannot tell two voices apart, so this does not
   pretend to. It works the way a human would reading a transcript:
   by what the sentence IS, not by who the voice sounds like.

   A question, an instruction, a drug name, a diagnosis  -> doctor.
   A complaint, a symptom, "since three days", "yes sir" -> patient.

   Every line stays editable, and each one has a Dr/Patient toggle,
   because the guess will sometimes be wrong.
   ------------------------------------------------------------------ */
(function () {

  /* ---------- sentence-level cues ---------- */

  /* Speech recognition usually returns NO punctuation, so a question has to be
     recognised by its shape, not by a question mark. */
  var QUESTION = new RegExp(
    '^(any|is there|do you|did you|are you|have you|how|what|when|where|which|why|kya|kab|kaise|kitna|kitne|koi)\\b'
    + '|\\b(hai kya|hua kya|karta hai|hoti hai kya|nahi)\\s*\\?*$', 'i');

  var DR_CUES = [
    /\?\s*$/,                                              /* questions are nearly always the doctor */
    QUESTION,
    /\b(let me (check|see|examine)|i will|we will|i am giving|i'?ll give|start(ing)? you on)\b/i,
    /\b(take|tak(e|ing) this|apply|continue|stop|avoid|reduce|increase|come back|follow up|review)\b/i,
    /\b(diagnosis|impression|this is|it looks like|seems like|nothing to worry|don'?t worry)\b/i,
    /\b(tablet|tab|cap|capsule|syrup|injection|mg|ml|twice|thrice|once daily|bd|od|tds|sos)\b/i,
    /\b(test|blood test|x-?ray|scan|ultrasound|report|cbc|sugar|urine)\b/i,
    /\b(bp is|pressure is|temperature is|pulse is|weight is|sugar is)\b/i,
    /\b(dekhta hoon|dekhne do|de raha hoon|dawai|goli|khaiye|lijiye|piye|aaram|band kar|shuru)\b/i,
    /\b(kya (hua|takleef|problem)|kab se|kaisa|kitne din|dikhaiye|batao)\b/i
  ];

  /* "yes you will be fine" is the doctor answering; "yes sir" is the patient. */
  var DR_REASSURE = /^(yes|no|nahi|haan)\b.{0,4}\b(you|it|that|aap|koi|kuch)\b/i;

  var PT_CUES = [
    /\b(i have|i am having|i feel|i felt|i get|i am getting|my \w+ (is|are|hurts|pains))\b/i,
    /\b(since|for the last|from) \w+ (day|days|week|weeks|month|months)\b/i,
    /\b(pain|ache|aching|fever|cough|cold|vomit|loose motion|weakness|giddy|dizzy|burning|swelling)\b/i,
    /\b(yes|yes sir|no sir|ok sir|thik hai|haan|nahi|ji)\b/i,
    /\b(mujhe|mera|meri|mere|ho raha|hoti hai|hota hai|dard|takleef|se hai|nahi lag)\b/i,
    /\b(not able to|cannot|can'?t) (sleep|eat|walk|breathe)\b/i,
    /\b(doctor|sir|madam)\b.*\b(i|mujhe|mera)\b/i
  ];

  /* Words that say the speaker just changed, even mid-stream. */
  var SWITCH_TO_DR = /^(so|ok|okay|right|achha|theek|dekhiye|suniye)\b/i;
  var SWITCH_TO_PT = /^(sir|doctor|madam|ji|haan|nahi)\b/i;

  var QUESTION_RE = QUESTION;

  function scoreLine(line) {
    var dr = 0, pt = 0;
    DR_CUES.forEach(function (r) { if (r.test(line)) dr++; });
    PT_CUES.forEach(function (r) { if (r.test(line)) pt++; });
    /* "any cough" is the doctor asking, even though "cough" is a symptom word.
       A question shape outweighs an incidental keyword. */
    if (QUESTION.test(line) || /\?\s*$/.test(line)) dr += 2;
    if (SWITCH_TO_DR.test(line)) dr += 0.5;
    if (SWITCH_TO_PT.test(line)) pt += 0.5;
    return { dr: dr, pt: pt };
  }

  /* Break the flow of text into sentences we can label. */
  function toSentences(text) {
    var raw = String(text)
      .replace(/\s+/g, ' ')
      /* speech recognition rarely gives punctuation, so also break on the
         explicit speaker words people naturally say */
      .replace(/\b(doctor|patient|dr|pt)\s*[:\-]\s*/gi, '|$1: ')
      /* start a new sentence before a bare question opener, so
         "...headache. any cough. yes sir..." separates correctly */
      .replace(/([.?!])?\s+(any|do you|did you|are you|have you|is there|koi|kya)\s+/gi, '. $2 ')
      /* Speech often runs the greeting straight into the patient's reply:
         "good evening what happened sir I have fever...". Break before the
         patient's opener so the two do not merge into one turn. */
      .replace(/\s+(sir|madam|doctor sahab|ji)\s+(i|my|mujhe|mera|meri|mere)\b/gi, '. $1 $2')
      .replace(/\b(what happened|kya hua|kya takleef|kya problem)[.\s]+(?=[a-z])/gi, '$1. ')
      .replace(/\.{2,}/g, '.')
      .split(/(?<=[.?!])\s+|\|/);
    return raw.map(function (x) { return x.trim(); }).filter(function (x) { return x.length > 1; });
  }

  /* Main entry: text -> [{s:'dr'|'pt', t:'...'}] */
  function splitSpeakers(text) {
    var out = [];
    var last = 'dr';              /* consultations almost always open with the doctor */
    toSentences(text).forEach(function (sent) {
      var explicit = null;
      var m = sent.match(/^(doctor|dr)\s*:\s*/i);
      if (m) { explicit = 'dr'; sent = sent.slice(m[0].length); }
      var m2 = sent.match(/^(patient|pt)\s*:\s*/i);
      if (m2) { explicit = 'pt'; sent = sent.slice(m2[0].length); }
      sent = sent.trim();
      if (!sent) return;

      var who;
      if (explicit) {
        who = explicit;
      } else {
        var sc = scoreLine(sent);
        if (DR_REASSURE.test(sent)) who = 'dr';
        else if (sc.dr > sc.pt)      who = 'dr';
        else if (sc.pt > sc.dr) who = 'pt';
        else                    who = last;   /* a tie means the same person is still talking */
      }
      /* merge consecutive lines from the same speaker for readability */
      if (out.length && out[out.length - 1].s === who) out[out.length - 1].t += ' ' + sent;
      else out.push({ s: who, t: sent });
      last = who;
    });
    return out;
  }

  /* ---------- what the conversation was ABOUT ---------- */

  /* Prefer an explicit diagnosis; otherwise name it by the complaints. */
  function guessDisease(text, symptoms, diagnosis) {
    if (diagnosis) return diagnosis;
    if (symptoms && symptoms.length) return symptoms.slice(0, 3).join(', ');
    return '';
  }

  /* ---------- the two point lists ---------- */

  /* Patient's points: what they actually reported. */
  function patientPoints(turns, draft) {
    var pts = [];
    if (draft.symptoms && draft.symptoms.length) {
      pts.push('Complaints: ' + draft.symptoms.join(', ') +
               (draft.dur ? ' (since ' + draft.dur + ')' : ''));
    }
    /* keep the patient's own sentences that carry information */
    turns.filter(function (x) { return x.s === 'pt'; }).forEach(function (x) {
      var t = x.t.trim();
      if (t.length < 6) return;
      if (/^(yes|no|ok|haan|nahi|ji|thik hai)\b[\s.]*$/i.test(t)) return;   /* drop bare acknowledgements */
      pts.push(t.charAt(0).toUpperCase() + t.slice(1));
    });
    return dedupe(pts);
  }

  /* Doctor's points: findings, diagnosis, plan, advice. */
  function doctorPoints(turns, draft) {
    var pts = [];
    if (draft.diagnosis) pts.push('Diagnosis: ' + draft.diagnosis);

    var vl = { bp:'BP', sugar:'Sugar', temp:'Temp', pulse:'Pulse', spo2:'SpO2', weight:'Weight' };
    var v = Object.keys(draft.vitals || {}).map(function (k) { return vl[k] + ' ' + draft.vitals[k]; });
    if (v.length) pts.push('Examined: ' + v.join(', '));

    if (draft.meds && draft.meds.length) {
      pts.push('Prescribed: ' + draft.meds.map(function (m) {
        return m.name + (m.freq ? ' (' + m.freq + (m.dur ? ', ' + m.dur : '') + ')' : '');
      }).join('; '));
    }
    if (draft.labs && draft.labs.length)   pts.push('Tests advised: ' + draft.labs.join(', '));
    if (draft.advice && draft.advice.length) pts.push('Advice: ' + draft.advice.join('; '));
    if (draft.followUp) pts.push('Follow-up in ' + draft.followUp.days + ' days (' + draft.followUp.iso + ')');

    /* plus anything else the doctor explained that is not already covered */
    /* Anything else the doctor explained in their own words. The structured
       points above already carry the vitals, drugs, tests, advice and follow-up,
       so a sentence that merely restates one of those is dropped — otherwise
       every point appears twice, once summarised and once verbatim. */
    var COVERED = [
      /\b(temperature|bp|blood pressure|pulse|sugar|weight|spo2)\b.*\d/i,
      /\b(we will do|let us do|i will do|test|cbc|x-?ray|scan)\b/i,
      /\b(i am giving|i will give|take|tablet|tab|cap|syrup|mg)\b.*\b(daily|times|day|days|od|bd|tds)\b/i,
      /\b(drink|plenty of fluid|take rest|avoid|diet)\b/i,
      /\b(come back|follow up|review)\b.*\b(day|days|week)\b/i,
      /\b(this is|diagnosis)\b/i
    ];
    var GREET = /^(good (morning|afternoon|evening)|hello|hi|namaste|namaskar|come in|sit|baithiye|aaiye)\b/i;
    turns.filter(function (x) { return x.s === 'dr'; }).forEach(function (x) {
      x.t.split(/(?<=[.?!])\s+/).forEach(function (t) {
        t = t.trim().replace(/[.\s]+$/, '');
        if (t.length < 14) return;
        if (GREET.test(t)) return;
        if (/\?$/.test(t) || QUESTION_RE.test(t)) return;
        /* Hindi puts the question word at the end: "khana kaisa hai",
           "kitne din se hai", "dard hai kya". */
        if (/\b(kaisa|kaisi|kaise|kya|kab|kitna|kitne|kaun|kahan)\b[\s\w]{0,12}$/i.test(t)) return;
        var covered = false;
        COVERED.forEach(function (r) { if (r.test(t)) covered = true; });
        if (covered) return;
        var key = t.toLowerCase().replace(/[^a-z0-9]/g, '');
        var hay = pts.join(' ').toLowerCase().replace(/[^a-z0-9]/g, '');
        if (hay.indexOf(key.slice(0, 26)) >= 0) return;
        pts.push(t.charAt(0).toUpperCase() + t.slice(1));
      });
    });
    return dedupe(pts);
  }

  function dedupe(arr) {
    var seen = {}, out = [];
    arr.forEach(function (x) {
      var k = x.toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 40);
      if (k && !seen[k]) { seen[k] = 1; out.push(x); }
    });
    return out;
  }

  window.Speakers = {
    split: splitSpeakers,
    disease: guessDisease,
    drPoints: doctorPoints,
    ptPoints: patientPoints
  };
})();
