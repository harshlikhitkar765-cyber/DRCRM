/* ------------------------------------------------------------------
   Ambient scribe.

   Records the whole doctor-patient conversation, keeps the transcript,
   and then pulls a draft prescription out of it. Nothing is ever
   applied to the form automatically: the doctor sees every suggestion
   with a tick box and decides what goes in.

   Uses the browser's own SpeechRecognition. No cloud service of ours,
   no API key, no cost.
   ------------------------------------------------------------------ */
(function () {
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var startBtn = document.getElementById('scribeBtn');
  if (!startBtn) return;

  /* Speech recognition only works on a secure origin. The object still
     exists over plain http://, so checking for it is not enough — Chrome
     lets you press Record and then fails with a bare "network" error that
     sounds like the internet is down when it is really the page not being
     on https. Say so up front instead of letting the doctor find out
     halfway through a consultation. */
  var secure = window.isSecureContext ||
               location.protocol === 'https:' ||
               location.hostname === 'localhost' ||
               location.hostname === '127.0.0.1' ||
               location.hostname === '[::1]';

  /* Some browsers expose SpeechRecognition but can never use it.
     Brave is the important one: Chrome sends the audio to a paid Google
     transcription service, Brave has no access to it and will not send
     patients' audio to Google anyway. The object exists, so feature
     detection passes, and every attempt fails with a bare "network"
     error that looks like the internet is down.
     Firefox ships it disabled behind a flag, with the same result. */
  var isBrave    = ('brave' in navigator) ||
                   (navigator.brave && typeof navigator.brave.isBrave === 'function');
  var isFirefox  = /firefox/i.test(navigator.userAgent);
  var noEngine   = !!(isBrave || isFirefox);
  var engineName = isBrave ? 'Brave' : (isFirefox ? 'Firefox' : 'This browser');

  var panel   = document.getElementById('scribePanel');
  var stat    = document.getElementById('scribeStat');
  var txtBox  = document.getElementById('scribeText');
  var sugBox  = document.getElementById('scribeSug');
  var makeBtn = document.getElementById('scribeMake');
  var applyBtn= document.getElementById('scribeApply');
  var clearBtn= document.getElementById('scribeClear');
  var langSel = document.getElementById('scribeLang');
  var hidden  = document.getElementById('scribeTranscript');
  var hidLang = document.getElementById('scribeLangVal');
  var hidPick = document.getElementById('scribePicked');
  var hidStart= document.getElementById('scribeStart');
  var hidTurns   = document.getElementById('scribeTurns');
  var hidDisease = document.getElementById('scribeDisease');
  var hidDr      = document.getElementById('scribeDr');
  var hidPt      = document.getElementById('scribePt');
  var turnBox    = document.getElementById('scribeTurns_ui');
  var hidNoteId  = document.getElementById('scribeNoteId');
  var hidSummary = document.getElementById('scribeSummary');
  var hidSecs    = document.getElementById('scribeSecs');
  var sumBox     = document.getElementById('scribeSummary_ui');

  if (!SR) {
    startBtn.disabled = true;
    startBtn.textContent = '🎙 Recording needs Chrome';
    if (stat) stat.textContent = 'Your browser has no speech recognition. Chrome or Edge on this desk PC, or any Android phone, will work.';
    return;
  }

  /* Tell the doctor now, not after they have spoken for ten minutes.
     The typing box stays fully usable, so the visit is not blocked. */
  /* This browser will never transcribe, whatever the doctor does. Say so
     now rather than after they have spoken through a whole consultation. */
  if (noEngine) {
    startBtn.disabled = true;
    startBtn.title = 'Not supported in ' + engineName;
    startBtn.textContent = 'Recording needs Chrome';
    if (panel) panel.style.display = '';
    if (langSel) langSel.style.display = 'none';

    var nb = document.getElementById('scribeNotice');
    if (nb) {
      nb.innerHTML =
        '<div class="note note-warn">' +
          '<div class="note-ico" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
              'stroke-linecap="round" stroke-linejoin="round">' +
              '<circle cx="12" cy="12" r="8.6"/><path d="M12 8v4.6M12 15.8h.01"/>' +
            '</svg></div>' +
          '<div class="note-body">' +
            '<b>' + engineName + ' cannot do voice recording</b>' +
            '<p>Speech recognition works by sending the audio to a Google service. ' +
            engineName + ' blocks that on purpose, so the microphone can never ' +
            'transcribe here. <em>This is not a fault with your internet or your ' +
            'microphone.</em></p>' +
            '<div class="note-do">' +
              '<span class="note-step"><b>To record</b> Open this same page in ' +
                '<b>Google Chrome</b> or <b>Microsoft Edge</b>. Your data is the same.</span>' +
              '<span class="note-step"><b>Or stay here</b> Type or paste the consultation ' +
                'below — it builds the prescription exactly the same way.</span>' +
            '</div>' +
            '<div class="note-act">' +
              '<button type="button" class="btn sm" id="nCopy">Copy page link</button>' +
              '<button type="button" class="btn ghost sm" id="nTypeB">Type it instead</button>' +
            '</div>' +
          '</div>' +
        '</div>';

      var cp = document.getElementById('nCopy');
      if (cp) cp.onclick = function () {
        if (navigator.clipboard) navigator.clipboard.writeText(location.href);
        cp.textContent = 'Copied — paste into Chrome';
        setTimeout(function () { cp.textContent = 'Copy page link'; }, 3000);
      };
      var tb = document.getElementById('nTypeB');
      if (tb) tb.onclick = function () {
        nb.innerHTML = '';
        if (txtBox) { txtBox.focus(); txtBox.scrollIntoView({behavior:'smooth', block:'center'}); }
      };
    }
    if (txtBox) {
      txtBox.placeholder = 'Type or paste the consultation here, then press ' +
        '"Make prescription from this".';
    }
    var hintE = document.querySelector('.scribe-top .ph');
    if (hintE) hintE.textContent = 'Type the consultation below and build the prescription from it';
  }

  /* Microphone off, everything else on. An early return here would also
     kill the typing box, which is the one route still open to the doctor.

     Presented as a calm notice rather than red error text: nothing has
     broken, one feature is unavailable and there is a clear way forward. */
  if (!secure) {
    startBtn.disabled = true;
    startBtn.title = 'Needs an https page';
    startBtn.textContent = 'Recording needs https';

    var notice = document.getElementById('scribeNotice');
    if (notice) {
      notice.innerHTML =
        '<div class="note note-info">' +
          '<div class="note-ico" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
              'stroke-linecap="round" stroke-linejoin="round">' +
              '<rect x="4.5" y="10.5" width="15" height="9.5" rx="2"/>' +
              '<path d="M8.2 10.5V7.6a3.8 3.8 0 0 1 7.6 0v2.9"/>' +
            '</svg>' +
          '</div>' +
          '<div class="note-body">' +
            '<b>Voice recording is switched off on this page</b>' +
            '<p>Browsers only allow the microphone on a secure <code>https://</code> ' +
            'address. This page is plain <code>http://</code>, so the microphone is ' +
            'blocked. <em>Your internet is fine — this is not a connection problem.</em></p>' +
            '<div class="note-do">' +
              '<span class="note-step"><b>Right now</b> Type or paste the conversation ' +
              'in the box below — everything else works exactly the same.</span>' +
              '<span class="note-step"><b>To fix it</b> Turn on the free SSL certificate ' +
              'in your hosting panel (Security → SSL), then open the site as ' +
              '<code>https://</code>.</span>' +
            '</div>' +
          '</div>' +
        '</div>';
    }

    /* The notice tells the doctor to type below, so the box must actually
       be on screen. Without this the panel stays collapsed and the advice
       points at something invisible. */
    if (panel) panel.style.display = '';

    /* The language picker only chooses a speech-recognition language, so
       it means nothing while the microphone is off. */
    if (langSel) langSel.style.display = 'none';

    /* The placeholder promised speech. Tell the truth instead. */
    if (txtBox) {
      txtBox.placeholder = 'Type or paste the consultation here, then press ' +
        '"Make prescription from this".';
    }
    var hint = document.querySelector('.scribe-top .ph');
    if (hint) hint.textContent = 'Type the consultation below and build the prescription from it';
  }

  /* ---------- symptom / history vocabulary ---------- */
  /* ---------- allergies ----------
     The safety checks refuse a drug the patient is allergic to, but the
     allergy only ever came from the patient-registration form. If someone
     says "I am allergic to penicillin" during a consultation it was
     ignored entirely — and the rash they describe was being logged as
     today's symptom. Both are fixed here. */
  var ALLERGY_CUE = /\b(allergic to|allergy to|allergy is|allergies?|reaction to|se allergy|allergy hai|se reaction)\b/i;

  /* A sentence that states an allergy, so it can be excluded from the
     symptom scan — "it gave me a rash" is history, not a complaint. */
  function allergySentences(t) {
    return String(t).split(/(?<=[.?!])\s+|(?=\bany allerg)/i)
             .filter(function (x) { return ALLERGY_CUE.test(x); });
  }

  function findAllergies(t) {
    var out = [];
    allergySentences(t).forEach(function (sent) {
      /* Ignore the doctor simply asking the question. */
      if (/\b(any allerg|koi allergy|allergy hai kya)\b/i.test(sent) &&
          !/\b(yes|haan|ji|allergic to|allergy to|se allergy)\b/i.test(sent)) return;
      if (/\b(no allerg|koi allergy nahi|nahi hai|none)\b/i.test(sent)) return;

      var m = sent.match(/\b(?:allergic to|allergy to|reaction to|allergy is)\s+([a-z][a-z0-9 \-]{2,40})/i)
           || sent.match(/\b([a-z][a-z0-9 \-]{2,40}?)\s+se\s+(?:allergy|reaction)\b/i);
      if (!m) return;

      var a = m[1]
        .split(/\b(it|which|that|and|aur|jo|isse|because|since|last|gave)\b/i)[0]
        /* Hindi puts the allergen before "se allergy", so the match can
           drag in the whole lead-in: "haan sir mujhe sulfa se allergy".
           Strip the conversational words from the front. */
        .replace(/^.*\b(?:mujhe|mereko|mera|meri|patient ko|isko|unko)\b\s*/i, '')
        .replace(/^\s*(?:haan|ji|yes|sir|madam|doctor)\b[\s,]*/gi, '')
        .replace(/\b(the|a|an|any|drugs?|medicines?|tablets?)\b/gi, ' ')
        .replace(/\s+/g, ' ').trim();

      if (a.length >= 3 && a.length <= 40) {
        a = a.charAt(0).toUpperCase() + a.slice(1);
        if (out.indexOf(a) < 0) out.push(a);
      }
    });
    return out;
  }

  var SYMPTOMS = [
    ['fever',        /\b(fever|bukhar|temperature|jvar)\b/i],
    ['cough',        /\b(cough|khaansi|khansi)\b/i],
    ['cold',         /\b(cold|runny nose|sardi|zukam|jukam)\b/i],
    ['sore throat',  /\b(sore throat|throat pain|gale me dard|gala kharab)\b/i],
    ['headache',     /\b(headache|sar dard|sir dard)\b/i],
    ['body ache',    /\b(body ache|body pain|badan dard|joint pain)\b/i],
    ['vomiting',     /\b(vomit\w*|ulti|ulty)\b/i],
    ['loose motion', /\b(loose motion|diarrh\w*|dast|patla)\b/i],
    ['stomach pain', /\b(stomach pain|abdominal pain|pet dard|pet me dard)\b/i],
    ['acidity',      /\b(acidity|heartburn|gas|jalan|acid)\b/i],
    ['breathlessness',/\b(breathless\w*|short of breath|saans|dam phool)\b/i],
    ['chest pain',   /\b(chest pain|seene me dard|chhati)\b/i],
    ['giddiness',    /\b(giddiness|dizzy|chakkar)\b/i],
    ['weakness',     /\b(weakness|fatigue|kamzori|thakan)\b/i],
    ['swelling',     /\b(swelling|oedema|edema|sujan|soojan)\b/i],
    ['burning urine',/\b(burning urine|urine.*burn|peshab|pesab)\b/i],
    ['rash',         /\b(rash|itching|khujli|daane)\b/i],
    ['back pain',    /\b(back pain|kamar dard|kamar me dard)\b/i]
  ];
  /* Extended symptom vocabulary. The original 18 covered the commonest
     OPD complaints; these add the ones a general physician hears daily,
     with the Hindi words patients actually use. */
  var SYMPTOMS_MORE = [
    ['chest pain',     /\b(chest pain|seene me dard|chaati me dard)\b/i],
    ['breathlessness', /\b(breathless|short of breath|saans (?:phool|lene me)|dam phool)\b/i],
    ['palpitations',   /\b(palpitation|heart racing|dhadkan tez)\b/i],
    ['dizziness',      /\b(dizzy|dizziness|giddy|chakkar)\b/i],
    ['weakness',       /\b(weakness|kamzori|thakan|fatigue|tired)\b/i],
    ['loss of appetite',/\b(no appetite|loss of appetite|bhookh nahi|bhook kam)\b/i],
    ['weight loss',    /\b(weight loss|vajan kam|wajan gir)\b/i],
    ['swelling',       /\b(swelling|sujan|sooj|oedema|edema)\b/i],
    ['joint pain',     /\b(joint pain|jodo me dard|ghutne me dard|knee pain)\b/i],
    ['back pain',      /\b(back pain|kamar (?:me )?dard|lower back)\b/i],
    ['burning urine',  /\b(burning urin|jalan peshab|peshab me jalan|dysuria)\b/i],
    ['frequent urination',/\b(frequent urin|baar baar peshab|polyuria)\b/i],
    ['constipation',   /\b(constipation|kabz|motion nahi)\b/i],
    ['loose motion',   /\b(loose motion|diarrhoea|diarrhea|dast|patla motion)\b/i],
    ['blood in stool', /\b(blood in stool|khoon.{0,8}latrine|rectal bleed)\b/i],
    ['itching',        /\b(itching|khujli|pruritus)\b/i],
    ['rash',           /\b(rash|daane|chakatte|skin eruption)\b/i],
    ['sleeplessness',  /\b(insomnia|neend nahi|sleepless|sona mushkil)\b/i],
    ['anxiety',        /\b(anxiety|ghabrahat|bechaini|panic)\b/i],
    ['numbness',       /\b(numb|jhunjhuni|sunn|tingling)\b/i],
    ['ear pain',       /\b(ear pain|kaan me dard|earache)\b/i],
    ['eye redness',    /\b(red eye|aankh laal|conjunctiv)\b/i],
    ['blurred vision', /\b(blurred vision|dhundhla|dikhna kam)\b/i],
    ['nausea',         /\b(nausea|jee michal|ulti jaisa)\b/i],
    ['bleeding gums',  /\b(bleeding gum|mashudo se khoon)\b/i],
    ['night sweats',   /\b(night sweat|raat ko paseena)\b/i],
    ['wheezing',       /\b(wheez|seeti|ghar ghar)\b/i]
  ];
  /* Third wave. Testing found ordinary complaints producing nothing at
     all — hiccups, hoarse voice, bloating, belching. These are the ones
     a general physician hears every evening. */
  var SYMPTOMS_X = [
    ['hiccups',          /\b(hiccups?|hichki)\b/i],
    ['hoarse voice',     /\b(hoarse|voice (?:is )?(?:gone|change)|awaaz (?:baith|kharab))\b/i],
    ['bloating',         /\b(bloat|gas (?:bhar|banti|hoti)|pet (?:phool|bhar)|flatulen)\b/i],
    ['belching',         /\b(belch\w*|dakar|burp\w*)\b/i],
    ['heartburn',        /\b(heartburn|seene me jalan|chest burning)\b/i],
    ['sore mouth',       /\b(mouth ulcer|muh me chhale|chhaale)\b/i],
    ['toothache',        /\b(tooth ?ache|dant me dard|daant dard)\b/i],
    ['neck pain',        /\b(neck pain|gardan me dard)\b/i],
    ['shoulder pain',    /\b(shoulder pain|kandhe me dard)\b/i],
    ['leg cramps',       /\b(cramps?|ainthan|pindli)\b/i],
    ['tremor',           /\b(tremors?|kaampna|haath kaap|shaking)\b/i],
    ['sweating',         /\b(sweating|paseena)\b/i],
    ['chills',           /\b(chills?|rigors?|thand lag|kapkapi)\b/i],
    ['dry mouth',        /\b(dry mouth|muh sookh)\b/i],
    ['excessive thirst', /\b(thirst|pyaas (?:zyada|bahut)|polydipsia)\b/i],
    ['blood in urine',   /\b(blood in urine|peshab me khoon|haematuria|hematuria)\b/i],
    ['difficulty passing urine',/\b(difficulty.{0,12}urin|peshab (?:ruk|nahi aata)|retention)\b/i],
    ['white discharge',  /\b(white discharge|safed paani|leucorrhoea)\b/i],
    ['irregular periods',/\b(irregular period|mahwari (?:aniyamit|nahi)|missed period)\b/i],
    ['painful periods',  /\b(painful period|period.{0,10}dard|dysmenorrh)\b/i],
    ['hair fall',        /\b(hair fall|baal jhad|alopecia)\b/i],
    ['snoring',          /\b(snor\w*|kharrate)\b/i],
    ['forgetfulness',    /\b(forget|yaad nahi rehta|memory (?:loss|weak))\b/i],
    ['low mood',         /\b(depress|mann nahi lagta|udaas|low mood)\b/i],
    ['burning feet',     /\b(burning feet|pair.{0,10}jalan|sole.{0,8}burn)\b/i],
    ['cold intolerance', /\b(thand (?:lagti|bardasht)|cold intoleran)\b/i],
    ['eye watering',     /\b(watering eye|aankh se paani)\b/i],
    ['nose block',       /\b(nose block|naak band|congestion)\b/i],
    ['nose bleed',       /\b(nose ?bleed|naak se khoon|epistaxis)\b/i],
    ['abdominal distension',/\b(distension|pet bada|pet tanav)\b/i]
  ];



  /* duration of the complaint: "since three days", "teen din se" */
  function complaintDuration(t0) {
    var W = (window.RxParse && window.RxParse.words2num) || function (x) { return x; };
    var t = W(String(t0));
    var m = t.match(/\b(?:since|for|from)\s+(\d+(?:\.\d+)?)\s*(day|days|week|weeks|month|months)\b/i)
         || t.match(/\b(\d+(?:\.\d+)?)\s*(din|hafte|hafta|mahine|mahina)\s*se\b/i);
    if (!m) return '';
    var n = m[1], u = m[2].toLowerCase();
    if (/week|hafte|hafta/.test(u)) return n + ' week' + (n == 1 ? '' : 's');
    if (/month|mahin/.test(u))      return n + ' month' + (n == 1 ? '' : 's');
    return n + ' day' + (n == 1 ? '' : 's');
  }

  /* vitals spoken aloud: "BP is 140 by 90", "sugar 180" */
  var VITALS = [
    ['bp',     /\b(?:bp|blood pressure|pressure)\D{0,12}(\d{2,3})\s*(?:by|\/|over|slash)\s*(\d{2,3})\b/i, function(m){return m[1]+'/'+m[2];}],
    ['sugar',  /\b(?:sugar|glucose|sugar level|fasting|fbs|pp|post prandial|ppbs|random)\D{0,12}(\d{2,3})\b/i, function(m){return m[1];}],
    ['temp',   /\b(?:temp\w*|fever of)\D{0,12}(9\d|10\d)(?:\.(\d))?\b/i,     function(m){return m[1]+(m[2]?'.'+m[2]:'');}],
    ['pulse',  /\b(?:pulse|heart rate)\D{0,12}(\d{2,3})\b/i,                 function(m){return m[1];}],
    ['spo2',   /\b(?:spo2|s p o two|oxygen|saturation)\D{0,12}(\d{2,3})\b/i, function(m){return m[1];}],
    ['weight', /\b(?:weight|wajan|vajan)\D{0,12}(\d{2,3})(?:\.(\d))?\s*(?:kg|kilo)?\b/i, function(m){return m[1]+(m[2]?'.'+m[2]:'');}]
  ];

  /* an explicit diagnosis statement by the doctor */
  /* The 93 diagnoses already in the database, matched by name. Until now
     a diagnosis was only found after a lead-in phrase ("this is …"), so
     saying "typhoid fever" plainly produced nothing at all even though
     the illness is in the list. */
  function findKnownDiagnosis(t) {
    var list = window.DXLIST || [];
    var low  = ' ' + String(t).toLowerCase().replace(/[^a-z0-9\u0900-\u097F ]/g, ' ')
                              .replace(/\s+/g, ' ') + ' ';
    var best = '', bestLen = 0;
    list.forEach(function (name) {
      var n = String(name).toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
      if (n.length < 4) return;
      /* Whole-phrase match only. A substring test would let "anaemia"
         fire on "anaemic", and "gout" on "gouty" — close enough to be
         wrong on a prescription. */
      if (low.indexOf(' ' + n + ' ') < 0) return;
      /* Prefer the longest match: "dengue fever" over "fever". */
      if (n.length > bestLen) { bestLen = n.length; best = name; }
    });
    return best;
  }

  function findDiagnosis(t) {
    /* Doctors state a diagnosis many ways. Only six phrasings were
       recognised, so "you have gastroenteritis" and "suffering from
       migraine" produced nothing at all. */
    var m = t.match(/\b(?:diagnosis is|this is|looks like|seems like|it is|impression is|lagta hai)\s+([a-z0-9 \-]{3,55})/i)
         || t.match(/\b(?:you have|patient has|he has|she has|aapko|inko|isko)\s+(?:got\s+)?([a-z0-9 \-]{3,55})/i)
         || t.match(/\b(?:suffering from|case of|diagnosed (?:as|with)|k[ei] case|ka case)\s+([a-z0-9 \-]{3,55})/i)
         || (function () {
              /* The bare "X hai" pattern is very loose — "pet mein gas
                 bharti hai" was becoming a diagnosis. Only accept it when
                 the words look like an illness name: at most three words
                 and no verb or preposition in the middle. */
              var m2 = t.match(/\b(?:yeh|ye|isko|inko|aapko)\s+([a-z][a-z0-9 \-]{3,34}?)\s+(?:hai|ho gaya|ho gayi)\b/i);
              if (!m2) return null;
              var w = m2[1].trim().split(/\s+/);
              if (w.length > 3) return null;
              if (/\b(mein|me|se|ko|ka|ki|ke|par|aur|bharti|hoti|aati|lagti|rehta)\b/i.test(m2[1])) return null;
              return m2;
            })();
    if (!m) return '';
    var d = String(m[1])
      /* "yeh gastritis", "ek viral fever" — drop the lead-in words that
         the pattern inevitably catches. */
      .replace(/^\s*(?:yeh|ye|ek|a|an|the|koi|isko|inko|aapko|sir|madam)\s+/i, '');
    /* The doctor usually keeps talking: "...viral fever nothing to worry,
       we will do CBC". Cut at the first reassurance or next instruction. */
    d = d.split(/\b(nothing to worry|don'?t worry|no need|koi tension|we will|i will|i am giving|so |and )\b/i)[0];

    /* A drug name also ends the diagnosis. Speech gives no punctuation, so
       "this is viral fever dolo 650 three times daily" was being stored
       whole as the diagnosis. Stop at the first medicine or brand word. */
    var stop = Object.keys(window.BRANDMAP || {})
      .concat(Object.keys(window.DRUGMAP || {}).map(function (n) {
        return n.toLowerCase().replace(/^(tab|cap|syp|inj|drops?)\s+/, '').split(/\s+/)[0];
      }))
      .filter(function (w) { return w && w.length > 2; });
    var low = d.toLowerCase(), cut = -1;
    stop.forEach(function (w) {
      var i = low.indexOf(' ' + w);
      if (i > 0 && (cut < 0 || i < cut)) cut = i;
    });
    if (cut > 0) d = d.slice(0, cut);

    /* And at a dosing phrase, in case the drug itself was misheard. */
    d = d.split(/\b(once|twice|thrice|three times|daily|morning|night|after food|before food|khali pet|din mein)\b/i)[0];
    return d.replace(/\b(a|an|the)\b/gi, '').replace(/\s+/g, ' ').trim();
  }

  /* advice sentences */
  /* The doctor's own advice lines, from the database. The built-in list
     below only had nine phrasings, so anything the doctor added in
     Settings was never recognised when spoken. */
  function dbAdvice(t) {
    var out = [], lines = window.ADVICELIST || [];
    lines.forEach(function (a) {
      var words = String(a).toLowerCase()
        .replace(/[^a-z0-9\u0900-\u097F ]/g, ' ')
        .split(/\s+/).filter(function (w) { return w.length > 3; });
      if (!words.length) return;
      /* Match when most of the significant words are present, so
         "avoid oily food" still finds "Avoid oily and spicy food". */
      var hit = words.filter(function (w) { return t.toLowerCase().indexOf(w) >= 0; });
      if (hit.length >= Math.max(2, Math.ceil(words.length * 0.6))) out.push(a);
    });
    return out;
  }

  /* Precautions the doctor gives in Hindi. "thanda paani mat piye" and
     "parhez rakhiye" were producing nothing at all, because the advice
     list only held English phrasings plus four Hindi ones. */
  var PRECAUTION = [
    ['Avoid cold drinks and ice',   /\b(thanda (?:paani|pani)? ?mat|cold (?:drink|water) (?:avoid|mat)|barf mat|ice avoid)/i],
    ['Avoid oily and spicy food',   /\b(tel|masala|oily|spicy|tala hua)\b[^.]{0,18}\b(mat|avoid|nahi|band)/i],
    ['Stay out of the sun',         /\b(dhoop|sun)\b[^.]{0,18}\b(mat|avoid|nahi)/i],
    ['Do not skip meals',           /\b(khana (?:mat|na) chhod|skip meal|bhookhe mat|do not skip)/i],
    ['Stop smoking',                /\b(no smoking|smoking (?:band|chhod|avoid)|dhoomrapan|beedi|cigarette (?:band|chhod))/i],
    ['Avoid alcohol',               /\b(no alcohol|alcohol (?:band|chhod|avoid)|sharab (?:mat|band|chhod)|daaru)/i],
    ['Follow the diet advised',     /\b(parhez|pathya|diet (?:follow|maintain)|khane mein dhyan)/i],
    ['Avoid outside food',          /\b(bahar ka khana|outside food|street food|hotel ka khana|bahar mat kha)/i],
    ['Avoid sweets and sugar',      /\b(mithai|sweets?|meetha)\b[\w\s]{0,10}\b(?:avoid|mat|band|kam)/i],
    ['Drink boiled or filtered water',/\b(ubla (?:hua )?paani|boiled water|filter ka paani|filtered water)/i]
  ];

  var ADVICE = [
    ['Plenty of fluids',            /\b(plenty of fluid|drink water|lots of water|hydrat|(?:paani|pani)\b[\w\s]{0,12}\b(?:piye|piyo|piyein|pijiye|lijiye))/i],
    ['Take rest',                   /\b(take rest|bed rest|aaram|rest karo)/i],
    ['Light and warm food',         /\b(light food|soft diet|garam khana|halka khana)/i],
    ['Avoid oily and spicy food',   /\b(avoid oily|no spicy|tel masala|masaledar)/i],
    ['Avoid cold drinks and ice',   /\b(avoid cold|no ice|thanda mat)/i],
    ['Low salt diet',               /\b(low salt|namak kam|reduce salt)/i],
    ['Low sugar diet, avoid sweets',/\b(avoid sweet|sugar kam|no sugar|meetha mat)/i],
    ['Daily 30 minute walk',        /\b(walk daily|thirty minute walk|30 minute walk|roz walk|sair)/i],
    ['Come back immediately if it worsens', /\b(come back|return immediately|worse|bigad|turant aana)/i]
  ];

  function findFollowUp(t0) {
    var W = (window.RxParse && window.RxParse.words2num) || function (x) { return x; };
    var t = W(String(t0));
    /* "come back tomorrow" carries no number at all. */
    if (/\b(?:come back|review|recheck|dubara|wapas)\b[^.]{0,24}\b(?:tomorrow|kal)\b/i.test(t) ||
        /\b(?:tomorrow|kal)\b[^.]{0,24}\b(?:come back|review|recheck|dubara|wapas|aana)\b/i.test(t)) {
      var d1 = new Date(); d1.setDate(d1.getDate() + 1);
      return { days: 1, iso: d1.toISOString().slice(0, 10) };
    }

    /* [^.] rather than \D: words2num() turns "fifteen" into "15", so the
       gap between the cue and the interval can itself contain digits and
       a \D-only gap silently stops matching. */
    var m = t.match(/\b(?:come back|follow up|followup|review|recheck|check up|dubara|wapas)\b[^.]{0,24}?(\d+)\s*(day|days|week|weeks|month|months|din|hafte|hafta|mahine)\b/i)
         /* Hinglish mixes the units: "thirty days baad", "2 week baad".
            The Hindi-only list missed every one of those. */
         || t.match(/\b(\d+)\s*(din|hafte|hafta|mahine|day|days|week|weeks|month|months)\s*(?:baad|bad|ke baad)\b/i)
         || t.match(/\bin\s+(\d+)\s*(day|days|week|weeks|month|months)\b[^.]{0,30}\b(?:review|follow|check|come back)\b/i)
         || t.match(/\b(?:come back|review|recheck)\b[^.]{0,40}?\bin\s+(\d+)\s*(day|days|week|weeks|month|months)\b/i);
    if (!m) return null;
    var n = parseInt(m[1], 10), u = m[2].toLowerCase();
    if (/month|mahine/.test(u)) n *= 30;
    if (/week|hafte|hafta/.test(u)) n *= 7;
    var d = new Date(); d.setDate(d.getDate() + n);
    return { days: n, iso: d.toISOString().slice(0, 10) };
  }

  /* ---------- recording ---------- */

  /* Mobile browsers handle speech recognition quite differently from the
     desktop, and the app was configured only for the desktop:

       - Android Chrome ignores continuous mode. It ends the session after
         every utterance, so a consultation arrived as one short phrase and
         then silence.
       - iOS Safari refuses to start at all when continuous is true, and
         also requires the microphone to be started from a real tap, not
         from code.
       - Mobile sessions end far more often, so the 300ms restart delay
         tuned for the desktop was too slow to feel continuous. */
  var isIOS     = /iP(hone|ad|od)/i.test(navigator.userAgent) ||
                  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isAndroid = /Android/i.test(navigator.userAgent);
  var isMobile  = isIOS || isAndroid ||
                  (navigator.maxTouchPoints > 1 && window.innerWidth < 900);

  var rec = new SR();
  /* On a phone, continuous is either ignored or fatal — leave it off and
     lean on the auto-restart loop instead, which already exists. */
  rec.continuous = !isMobile;
  rec.interimResults = !isIOS;      /* iOS delivers no usable interim text */
  rec.lang = 'en-IN';

  var running = false, wantOn = false;
  var heardAudio = false;   /* did the microphone deliver any sound at all? */
  var heardSpeech = false;  /* did the engine decide it was speech? */
  var lastErr = '';         /* why recognition stopped, if it did */
  var restarts = 0;         /* consecutive auto-restarts, to catch a dead mic */
  var finalText = '', startedAt = '';

  function show(msg, cls) { if (stat) { stat.textContent = msg; stat.className = 'mic-out ' + (cls || ''); } }
  /* Never rewrite the box while the doctor is typing in it: doing so throws
     the caret to the end and silently destroys the correction they were
     making. Only the recogniser's own updates redraw it. */
  function render(fromSpeech) {
    if (!txtBox) return;
    var editing = (document.activeElement === txtBox);
    if (!editing || fromSpeech !== true) {
      if (!editing) {
        txtBox.value = finalText.trim();
        txtBox.scrollTop = txtBox.scrollHeight;
      }
    }
    var val = txtBox.value.trim();
    if (hidden) hidden.value = val;
    if (makeBtn) makeBtn.disabled = val.length < 8;
  }

  startBtn.onclick = function () {
    if (!secure || noEngine) return;                 /* button is disabled anyway */
    if (running) { wantOn = false; rec.stop(); return; }
    wantOn = true;
    heardAudio = false; heardSpeech = false; lastErr = '';
    /* "hinglish" is our own label, not a language tag the browser knows.
       Chrome has no mixed-language model, so we pick the one that loses
       least: en-IN keeps English drug names and strengths intact while
       still transcribing Hindi words spoken in an Indian accent. Sending
       "hinglish" as a tag would throw. */
    var picked = langSel ? langSel.value : 'en-IN';
    hinglish = (picked === 'hinglish');
    rec.lang = hinglish ? 'en-IN' : picked;
    if (hidLang) hidLang.value = picked;
    if (!startedAt) {
      startedAt = new Date().toISOString().slice(0, 19).replace('T', ' ');
      if (hidStart) hidStart.value = startedAt;
    }
    if (panel) panel.style.display = '';
    try { rec.start(); } catch (e) {}
  };

  rec.onstart = function () {
    running = true;
    if (!startedAt) {
      startedAt = localStamp();
      if (hidStart) hidStart.value = startedAt;
    }
    startBtn.classList.add('rec');
    startBtn.textContent = '⏹ Stop recording';
    show('Recording… it is saving to the database and filling the prescription as you talk.', 'live');
    if (window.LiveDock) window.LiveDock.start();
    startMeter();
    if (panel) panel.style.display = '';
    if (!liveTimer) {
      liveTimer = setInterval(function () {
        liveAnalyse();     /* update the form from what has been said so far */
        saveLive(false);   /* and push it to the database */
      }, 4000);
    }
  };

  rec.onaudiostart  = function () { heardAudio = true; };
  rec.onspeechstart = function () { heardSpeech = true; };

  rec.onresult = function (ev) {
    var interim = '';
    for (var i = ev.resultIndex; i < ev.results.length; i++) {
      var r = ev.results[i];
      if (r.isFinal) finalText += r[0].transcript + ' ';
      else interim += r[0].transcript;
    }
    restarts = 0;                       /* speech is flowing; mic is healthy */
    if (document.activeElement === txtBox) return;   /* doctor is editing */

    /* Show the interim guess so the doctor can see it is listening, but only
       ever SAVE the confirmed final text. Interim words change as the engine
       reconsiders, so storing them puts half-heard phrases in the record. */
    if (txtBox) {
      txtBox.value = (finalText + interim).trim();
      txtBox.scrollTop = txtBox.scrollHeight;
    }
    var confirmed = finalText.trim();
    if (hidden) hidden.value = confirmed;
    if (makeBtn) makeBtn.disabled = confirmed.length < 8;
  };

  rec.onerror = function (e) {
    lastErr = e.error || '';
    if (e.error === 'network' && !secure) {
      wantOn = false;
      show(insecureMsg(), 'err');
      startBtn.classList.remove('rec');
      startBtn.textContent = '🎙 Record conversation';
      if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
      return;
    }
    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
      wantOn = false;
      show('Microphone blocked. Click the padlock in the address bar, set Microphone to Allow, ' +
           'then press Record again.', 'err');
    } else if (e.error === 'audio-capture') {
      wantOn = false;
      show('No microphone found. Plug one in (or check Windows sound settings) and try again.', 'err');
    } else if (e.error === 'network') {
      wantOn = false;
      startBtn.classList.remove('rec');
      startBtn.textContent = '🎙 Record conversation';
      if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
      show(noEngine
        ? engineName + ' blocks the transcription service, so recording cannot work here. ' +
          'Open the page in Chrome or Edge, or type the consultation below.'
        : 'Could not reach the transcription service. If you are using Brave or Firefox ' +
          'this will never work — open the page in Chrome or Edge. Otherwise check the ' +
          'internet connection, or type the consultation below.', 'err');
    } else if (e.error === 'no-speech') {
      show('No speech heard yet — still listening.', 'live');
    } else if (e.error !== 'aborted') {
      show('Recognition hiccup (' + e.error + ') — restarting.', 'err');
    }
  };

  /* Chrome stops after a silence; a long consultation needs it to keep going. */
  rec.onend = function () {
    running = false;
    if (wantOn) {
      /* Chrome ends the session on every pause in speech. Restart it, but
         leave a beat first: calling start() in the same tick throws
         InvalidStateError, the error is swallowed, and the microphone dies
         without ever telling anyone. A backoff also stops a broken mic from
         spinning in a tight restart loop. */
      restarts++;
      if (restarts > (isMobile ? 400 : 60)) {
        wantOn = false;
        startBtn.classList.remove('rec');
        startBtn.textContent = '🎙 Record conversation';
        if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
        show('Recording kept dropping out, so it has been stopped. ' +
             'The text so far is safe below. Press Record to carry on.', 'err');
        return;
      }
      setTimeout(function () {
        if (!wantOn || running) return;
        try { rec.start(); }
        catch (e) {
          show('Could not restart the microphone (' + (e.name || e) +
               '). Press Record to try again — your text is safe.', 'err');
          wantOn = false;
          startBtn.classList.remove('rec');
          startBtn.textContent = '🎙 Record conversation';
        }
      }, isMobile ? 120 : 300);
      return;
    }
    restarts = 0;
    stopMeter();
    if (window.LiveDock) window.LiveDock.stop();
    startBtn.classList.remove('rec');
    startBtn.textContent = '🎙 Record conversation';
    if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
    render();
    if ((txtBox ? txtBox.value : finalText).trim().length > 8) {
      liveAnalyse();
      buildSummary();      /* gather every main point into one record */
      saveLive(true);      /* final write, marks the recording finished */
    }
    var typed = (txtBox ? txtBox.value : '').trim();
    if (finalText.trim()) {
      show('Recording stopped. ' + finalText.trim().split(/\s+/).length +
           ' words captured — now press “Make prescription from this”.', 'ok');
    } else if (typed) {
      /* Speech gave nothing but there IS text in the box, so the doctor typed
         or corrected it. That is perfectly usable — do not call it a failure. */
      show('No speech was recognised, but there is text in the box. ' +
           'Press “Make prescription from this” to use it.', 'ok');
    } else {
      showNothingPanel();
    }
  };

  /* ---------- microphone level meter ----------
     Independent of speech recognition, so it can tell a dead microphone
     apart from a working one that simply is not being understood. */
  var micOK = false, micDevice = '', audioOn = false;
  var hinglish = false;   /* doctor selected the mixed-language mode */

  function meterEl() {
    var m = document.getElementById('micMeter');
    if (m) return m;
    var host = document.getElementById('scribeNotice');
    if (!host || !host.parentNode) return null;
    m = document.createElement('div');
    m.id = 'micMeter';
    m.className = 'micm';
    m.innerHTML =
      '<span class="micm-ico" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
          'stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M12 3.8a2.9 2.9 0 0 1 2.9 2.9v5.2a2.9 2.9 0 1 1-5.8 0V6.7A2.9 2.9 0 0 1 12 3.8Z"/>' +
          '<path d="M5.9 11.5v.4a6.1 6.1 0 0 0 12.2 0v-.4"/><path d="M12 18.4V21"/>' +
        '</svg></span>' +
      '<span class="micm-bar"><i id="micMeterFill"></i></span>' +
      '<span class="micm-txt" id="micMeterTxt">starting…</span>';
    host.parentNode.insertBefore(m, host.nextSibling);
    return m;
  }

  function startMeter() {
    if (!window.MicCheck || !MicCheck.supported()) return;
    var m = meterEl(); if (!m) return;
    m.classList.add('on');
    var fill = document.getElementById('micMeterFill');
    var txt  = document.getElementById('micMeterTxt');
    micOK = false;

    MicCheck.start(function (level, info) {
      if (fill) fill.style.width = Math.round(level * 100) + '%';
      if (!micOK && info.peak > 0.06) {
        micOK = true;
        m.classList.add('good');
      }
      if (txt) {
        if (!info.live) {
          txt.textContent = 'microphone disconnected';
          m.classList.add('bad');
        } else if (!micOK) {
          txt.textContent = 'no sound yet — say something';
        } else if (info.quietMs > 6000) {
          txt.textContent = 'quiet for a few seconds';
        } else {
          txt.textContent = 'hearing you';
        }
      }
    }).then(function (name) {
      micDevice = name || '';
      if (micDevice && txt) txt.title = 'Using: ' + micDevice;
      /* Capture the audio too, on the same stream. If transcription
         fails, the consultation is still on record. */
      if (window.AudioRec && AudioRec.supported()) {
        AudioRec.reset();
        audioOn = AudioRec.start(MicCheck.stream());
        if (audioOn) m.classList.add('taping');
      }
    }).catch(function (err) {
      if (txt) txt.textContent = (err && err.name === 'NotAllowedError')
        ? 'microphone blocked' : 'microphone unavailable';
      m.classList.add('bad');
    });
  }

  function stopMeter() {
    /* Finalise the audio BEFORE the stream is torn down, or the last
       few seconds are lost. */
    if (audioOn && window.AudioRec) {
      AudioRec.stop().then(function (b) {
        if (b && b.size > 2000) offerAudio(b);
      });
    }
    if (window.MicCheck) MicCheck.stop();
    var m = document.getElementById('micMeter');
    if (m) { m.classList.remove('on', 'good', 'bad', 'taping'); }
  }

  /* The transcription may have produced nothing, but the audio is there.
     Give the doctor a player and a download so the visit is not lost. */
  function offerAudio(b) {
    var host = document.getElementById('scribeNotice');
    if (!host) return;
    var url  = URL.createObjectURL(b);
    var secs = AudioRec.seconds();
    var mins = Math.floor(secs / 60), rem = secs % 60;
    var len  = mins ? mins + ' min ' + rem + ' sec' : secs + ' sec';

    var wrap = document.createElement('div');
    wrap.className = 'note note-info audio-keep';
    wrap.innerHTML =
      '<div class="note-ico" aria-hidden="true">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
          'stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M9 18V5l11-2v13"/><circle cx="6.5" cy="18" r="2.5"/>' +
          '<circle cx="17.5" cy="16" r="2.5"/></svg></div>' +
      '<div class="note-body">' +
        '<b>The audio was recorded — nothing is lost</b>' +
        '<p>' + len + ' · ' + AudioRec.sizeKB() + ' KB. Play it back and type what was said, ' +
        'or keep the file with the visit.</p>' +
        '<audio controls preload="metadata" src="' + url + '" class="audio-play"></audio>' +
        '<div class="note-act">' +
          '<a class="btn sm" download="consultation.webm" href="' + url + '">Download audio</a>' +
          '<button type="button" class="btn ghost sm" id="audType">Type what I hear</button>' +
        '</div>' +
      '</div>';
    host.appendChild(wrap);

    var t = document.getElementById('audType');
    if (t) t.onclick = function () {
      if (panel) panel.style.display = '';
      if (txtBox) { txtBox.focus(); txtBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    };
  }

  /* A red line and an empty box leaves the doctor stuck mid-consultation.
     Show the reason AND the two things that actually get them moving again:
     switch the language, or type it. */
  function showNothingPanel() {
    /* If the meter proved the microphone was working, the problem is the
       language or the service — not the hardware. Say the right thing. */
    var micWasLive = !!(window.MicCheck && MicCheck.supported() && MicCheck.heardAnything());
    var notice = document.getElementById('scribeNotice');
    if (!notice) { show(whyNothing(), 'err'); return; }

    var other    = (rec.lang === 'hi-IN') ? 'en-IN' : 'hi-IN';
    var otherTxt = (other === 'hi-IN') ? 'हिन्दी' : 'English';
    var nowTxt   = (rec.lang === 'hi-IN') ? 'हिन्दी' : 'English';

    notice.innerHTML =
      '<div class="note note-warn">' +
        '<div class="note-ico" aria-hidden="true">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" ' +
            'stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M12 3.8a2.9 2.9 0 0 1 2.9 2.9v5.2a2.9 2.9 0 1 1-5.8 0V6.7A2.9 2.9 0 0 1 12 3.8Z"/>' +
            '<path d="M5.9 11.5v.4a6.1 6.1 0 0 0 12.2 0v-.4"/><path d="m3.4 3.4 17.2 17.2"/>' +
          '</svg>' +
        '</div>' +
        '<div class="note-body">' +
          '<b>Nothing was recorded</b>' +
          '<p>' + esc(whyShort()) + '</p>' +
          '<div class="note-do">' +
            (micWasLive
              ? '<span class="note-step"><b>Most likely</b> The microphone was working — ' +
                'your voice registered on the meter. The language was set to <b>' + nowTxt +
                '</b>; if the consultation was in ' + otherTxt + ', switch it and record again.</span>'
              : '<span class="note-step"><b>Check the microphone</b> No sound reached the ' +
                'browser at all. Check it is not muted, that the right device is selected in ' +
                'Windows sound settings, and that no other program is using it.</span>') +
            '<span class="note-step"><b>Or just type</b> Write the consultation in the box below — ' +
              'it builds the prescription exactly the same way.</span>' +
          '</div>' +
          '<div class="note-act">' +
            '<button type="button" class="btn sm" id="nRetry">Switch to ' + otherTxt + ' and record</button>' +
            '<button type="button" class="btn ghost sm" id="nType">Type it instead</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    var r = document.getElementById('nRetry');
    if (r) r.onclick = function () {
      if (langSel) { langSel.value = other; }
      rec.lang = other;
      if (hidLang) hidLang.value = other;
      notice.innerHTML = '';
      startBtn.click();
    };
    var t = document.getElementById('nType');
    if (t) t.onclick = function () {
      notice.innerHTML = '';
      if (panel) panel.style.display = '';
      if (txtBox) { txtBox.focus(); txtBox.scrollIntoView({behavior:'smooth', block:'center'}); }
    };
    if (panel) panel.style.display = '';
    show('', '');
  }

  /* One short sentence for the panel, without the instructions. */
  function whyShort() {
    if (!secure) return 'The page is not on https, so the browser blocked the microphone.';
    if (noEngine) return engineName + ' blocks the transcription service, so recording cannot work here.';
    /* The level meter measured the microphone directly, so it is more
       reliable than the speech engine's own events. */
    if (window.MicCheck && MicCheck.supported()) {
      if (!MicCheck.live() && MicCheck.peak() === 0)
        return 'The microphone never started — it may be blocked, switched off, or in use by another program.';
      if (!MicCheck.heardAnything())
        return 'The microphone was on but no sound reached it at all.';
      return 'Your voice was picked up clearly, but the words were not recognised.';
    }
    if (lastErr === 'not-allowed' || lastErr === 'service-not-allowed')
      return 'The microphone is blocked for this site.';
    if (lastErr === 'audio-capture') return 'No working microphone was found.';
    if (lastErr === 'network')       return noEngine
        ? engineName + ' blocks the transcription service, so recording cannot work here.'
        : 'Could not reach the transcription service.';
    if (!heardAudio)                 return 'No sound reached the browser — the microphone may be muted or in use by another program.';
    if (!heardSpeech)                return 'Sound was heard, but no words were recognised.';
    return 'Speech was heard but nothing could be transcribed.';
  }

  /* "Nothing was captured" on its own is useless. Work out the likely reason
     and say what to do about it. */
  function insecureMsg() {
    return 'Recording needs a secure (https) page. This page is plain http, ' +
           'so the browser blocks the microphone — it is not an internet problem. ' +
           'Open the site with https://, or type the conversation in the box below.';
  }

  function whyNothing() {
    if (!secure) return insecureMsg();
    if (lastErr === 'not-allowed' || lastErr === 'service-not-allowed') {
      return 'Nothing captured — the microphone is blocked. Click the padlock in the ' +
             'address bar, set Microphone to Allow, then press Record again.';
    }
    if (lastErr === 'audio-capture') {
      return 'Nothing captured — no working microphone was found. Check it is plugged in ' +
             'and selected in the system sound settings.';
    }
    if (lastErr === 'network') {
      return 'Nothing captured — speech recognition could not reach Google\'s service. ' +
             'Check the internet connection, or type the conversation in the box below.';
    }
    if (!heardAudio) {
      return 'Nothing captured — no sound reached the browser. The microphone may be muted, ' +
             'switched off, or another program may be using it. You can also type in the box below.';
    }
    if (!heardSpeech) {
      return 'Nothing captured — sound was heard but no speech was recognised. ' +
             'Move the microphone closer, speak a little louder, and check the language ' +
             'selector matches the language you are speaking.';
    }
    return 'Nothing captured — speech was heard but nothing could be transcribed. ' +
           'Try again, or type the conversation in the box below.';
  }

  /* Exposed so the live loop can be driven directly — used by the test suite,
     and handy for debugging a consultation that is not filling correctly. */
  window.ScribeLive = {
    tick:    function () { liveAnalyse(); saveLive(false); },
    flush:   function () { liveAnalyse(); buildSummary(); saveLive(true); },
    summary: function () { return summary; },
    /* The live dock reads the complaints and duration from here, so it can
       show the problem the patient came with — not just the diagnosis. */
    draft:   function () { return draft; },
    disease: function () { return disease; },
    noteId:  function () { return noteId; },
    running: function () { return running; }
  };

  /* If the tab is closed while still recording, flush what we have. */
  window.addEventListener('beforeunload', function () {
    var text = (txtBox ? txtBox.value : finalText).trim();
    if (!text || text === lastSaved || !navigator.sendBeacon) return;
    var b = new URLSearchParams();
    b.set('csrf', cfg('scribeCsrf'));        b.set('id', String(noteId));
    b.set('patient_id', cfg('scribePid'));   b.set('appt_id', cfg('scribeAppt'));
    b.set('transcript', text);               b.set('turns', JSON.stringify(turns));
    b.set('disease', disease);               b.set('dr_points', JSON.stringify(drPts));
    b.set('pt_points', JSON.stringify(ptPts)); b.set('lang', rec.lang || 'en-IN');
    b.set('done', '1');
    navigator.sendBeacon('api/livenote.php',
      new Blob([b.toString()], { type: 'application/x-www-form-urlencoded' }));
  });

  /* When the doctor edits the box, their version becomes the truth. Adopting
     it into finalText means the next speech chunk appends to the corrected
     text instead of resurrecting the misheard original. */
  if (txtBox) txtBox.addEventListener('input', function () {
    finalText = txtBox.value + ' ';
    if (hidden) hidden.value = txtBox.value;
    if (makeBtn) makeBtn.disabled = txtBox.value.trim().length < 8;
  });

  /* Once they click away, let the recogniser drive the box again. */
  if (txtBox) txtBox.addEventListener('blur', function () {
    finalText = txtBox.value + ' ';
  });

  if (clearBtn) clearBtn.onclick = function () {
    finalText = ''; startedAt = '';
    if (hidStart) hidStart.value = '';
    turns = []; disease = ''; drPts = []; ptPts = [];
    summary = null; startedAt = ''; noteId = 0;
    if (hidSummary) hidSummary.value = ''; if (hidSecs) hidSecs.value = '0';
    if (hidNoteId)  hidNoteId.value  = '0';
    if (sumBox) sumBox.innerHTML = '';
    if (hidTurns) hidTurns.value = ''; if (hidDisease) hidDisease.value = '';
    if (hidDr) hidDr.value = ''; if (hidPt) hidPt.value = '';
    if (turnBox) turnBox.innerHTML = '';
    render(); if (sugBox) sugBox.innerHTML = '';
    if (applyBtn) applyBtn.style.display = 'none';
    show('Cleared.', '');
  };

  /* ---------- turning the conversation into a draft ---------- */
  var draft = null, turns = [], disease = '', drPts = [], ptPts = [];

  /* ---------- live mode ----------
     While recording we do two things every few seconds:
       1. push the conversation to the server so it is never lost
       2. re-read it and fill the prescription form as we go
     Both are safe to repeat; the server updates one row and the form
     only fills fields the doctor has not typed into. */
  var noteId    = 0;       /* database row for this consultation */
  var startedAt = '';      /* ISO time the recording began */
  var summary   = null;    /* the end-of-visit main-points record */
  var srvStart  = '';      /* start time as the server recorded it */
  var srvSecs   = 0;       /* length of the visit, measured server-side */
  var srvNow    = '';      /* server's current time HH:MM:SS */
  var liveTimer = null;
  var lastSaved = '';
  var saving    = false;
  var liveFill  = true;    /* fill the form as we talk */

  /* The clinic runs on Asia/Kolkata and every other timestamp in the app is
     local. toISOString() would send UTC and make a consultation look 5.5
     hours long, so build a local stamp instead. */
  function localStamp(d) {
    d = d || new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' +
           p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function cfg(k) {
    var el = document.getElementById(k);
    return el ? el.value : '';
  }

  function saveLive(done) {
    var text = (txtBox ? txtBox.value : finalText).trim();
    if (saving) return;
    if (!done && (text === lastSaved || text.length < 12)) return;
    saving = true;

    var body = new URLSearchParams();
    body.set('csrf',       cfg('scribeCsrf'));
    body.set('id',         String(noteId));
    body.set('patient_id', cfg('scribePid'));
    body.set('appt_id',    cfg('scribeAppt'));
    body.set('transcript', text);
    body.set('turns',      JSON.stringify(turns));
    body.set('disease',    disease);
    body.set('dr_points',  JSON.stringify(drPts));
    body.set('pt_points',  JSON.stringify(ptPts));
    body.set('lang',       rec.lang || 'en-IN');
    body.set('done',       done ? '1' : '0');
    body.set('summary',    summary ? JSON.stringify(summary) : '');
    body.set('secs',       summary ? String(summary.secs || 0) : '0');
    body.set('started_at', startedAt || '');

    fetch('api/livenote.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    })
    .then(function (r) { return r.json().catch(function () { return { error: 'bad reply' }; }); })
    .then(function (j) {
      saving = false;
      if (j && j.ok) {
        noteId    = j.id;
        lastSaved = text;
        /* The server timed the visit; trust it over the local clock. */
        if (j.started && !srvStart) srvStart = j.started;
        if (typeof j.secs === 'number') srvSecs = j.secs;
        if (j.at) srvNow = j.at;          /* server clock, for the visit header */
        if (hidNoteId) hidNoteId.value = String(noteId);
        setSaveMark('Saved to database ' + j.at + ' · note #' + j.id, 'ok');
      } else {
        setSaveMark('Not saved: ' + ((j && (j.error || j.detail)) || 'unknown') +
                    ' — the text on screen is still safe', 'err');
      }
    })
    .catch(function () {
      saving = false;
      setSaveMark('Cannot reach the server — still recording, will retry', 'err');
    });
  }

  /* Gather everything the conversation produced into one record and show it. */
  function buildSummary() {
    var S = window.RxSummary;
    if (!S || !draft) return;
    /* A typed or pasted transcript never fired rec.onstart, so stamp the
       start now rather than leaving the visit with no time on it. */
    if (!startedAt) {
      startedAt = localStamp();
      if (hidStart) hidStart.value = startedAt;
    }
    summary = S.build({
      draft:     draft,
      disease:   disease,
      drPoints:  drPts,
      ptPoints:  ptPts,
      turns:     turns,
      text:      (txtBox ? txtBox.value : finalText),
      startedAt: srvStart || startedAt,
      forceSecs: srvSecs,
      endedAt:   srvNow,
      lang:      rec.lang || 'en-IN'
    });
    if (hidSummary) hidSummary.value = JSON.stringify(summary);
    if (hidSecs)    hidSecs.value    = String(summary.secs || 0);
    if (sumBox) {
      sumBox.innerHTML = S.html(summary) +
        '<div class="sum-act">' +
          '<button type="button" class="btn sm" id="sumCopy">Copy as text</button>' +
          '<span class="ph" style="margin-left:9px">Read it, then Save prescription.</span>' +
        '</div>';
      var cp = document.getElementById('sumCopy');
      if (cp) cp.onclick = function () {
        var t = S.text(summary);
        if (navigator.clipboard) navigator.clipboard.writeText(t);
        cp.textContent = 'Copied';
        setTimeout(function () { cp.textContent = 'Copy as text'; }, 1600);
      };
      sumBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  function setSaveMark(msg, cls) {
    var el = document.getElementById('scribeSave');
    if (el) { el.textContent = msg; el.className = 'mic-out ' + (cls || ''); }
  }

  /* Re-analyse what has been said so far and fill the form live. */
  function liveAnalyse() {
    var text = (txtBox ? txtBox.value : finalText).trim();
    if (text.length < 12) return;
    draft = analyse(text);
    var SP = window.Speakers;
    turns   = SP ? SP.split(text) : [];
    disease = SP ? SP.disease(text, draft.symptoms, draft.diagnosis) : '';
    drPts   = SP ? SP.drPoints(turns, draft) : [];
    ptPts   = SP ? SP.ptPoints(turns, draft) : [];
    if (hidTurns)   hidTurns.value   = JSON.stringify(turns);
    if (hidDisease) hidDisease.value = disease;
    if (hidDr)      hidDr.value      = JSON.stringify(drPts);
    if (hidPt)      hidPt.value      = JSON.stringify(ptPts);
    renderTurns();
    if (liveFill) fillForm(true);
    if (window.LiveDock) window.LiveDock.paint();
  }


  function analyse(text) {
    var t = ' ' + text.replace(/\s+/g, ' ') + ' ';
    var P = window.RxParse || {};
    var d = { symptoms: [], vitals: {}, diagnosis: '', meds: [], labs: [], advice: [], followUp: null, dur: '' };

    /* Strip the allergy sentences before looking for symptoms, or the
       reaction described ("it gave me a rash") is recorded as a complaint
       the patient has today. */
    d.allergies = findAllergies(t);
    var tSym = t;
    allergySentences(t).forEach(function (sent) { tSym = tSym.replace(sent, ' '); });
    SYMPTOMS.concat(SYMPTOMS_MORE).concat(SYMPTOMS_X).forEach(function (s) {
      if (s[1].test(tSym) && d.symptoms.indexOf(s[0]) < 0) d.symptoms.push(s[0]);
    });
    d.dur = complaintDuration(t);

    VITALS.forEach(function (v) {
      var m = t.match(v[1]);
      if (m) d.vitals[v[0]] = v[2](m);
    });

    d.diagnosis = findDiagnosis(t);
    /* Fall back to a plainly-spoken illness name. */
    if (!d.diagnosis) d.diagnosis = findKnownDiagnosis(t);

    /* medicines: reuse the dictation parser, chunk by chunk */
    if (P.chunks && P.matchDrug) {
      var seen = {};
      P.chunks(t).forEach(function (c) {
        var name = P.matchDrug(c);
        if (!name || seen[name]) return;
        seen[name] = 1;
        var conv = P.words2num(c);
        var info = (window.DRUGMAP || {})[name] || {};
        var row = { name: name, dose: info.dose || '', unit: info.unit || 'tab',
                    when: info.when || 'After Food', freq: info.freq || 'OD',
                    dur: info.duration || '', notes: info.notes || '' };
        var dose = conv.match(/\b(\d+(?:\.\d+)?)\s*(tab|tablet|cap|capsule|ml|puff|sachet|drop|unit)s?\b/i);
        if (dose) { row.dose = dose[1];
                    row.unit = dose[2].toLowerCase().replace('tablet','tab').replace('capsule','cap'); }
        (P.FREQ || []).forEach(function (f) { if (f[0].test(conv) || f[0].test(c)) row.freq = f[1]; });
        (P.WHEN || []).forEach(function (w) { if (w[0].test(conv) || w[0].test(c)) row.when = w[1]; });
        var du = P.parseDuration ? P.parseDuration(conv) : '';
        if (du) row.dur = du;
        d.meds.push(row);
      });
    }

    (window.LABLIST || []).forEach(function (l) {
      var k = l.toLowerCase().replace(/[^a-z0-9]/g, '');
      var h = t.toLowerCase().replace(/[^a-z0-9]/g, '');
      if (k.length > 2 && h.indexOf(k) >= 0) d.labs.push(l);
    });

    ADVICE.forEach(function (a) { if (a[1].test(t)) d.advice.push(a[0]); });
    /* Then anything the doctor added to their own advice list. */
    PRECAUTION.forEach(function (a) {
      if (a[1].test(t) && d.advice.indexOf(a[0]) < 0) d.advice.push(a[0]);
    });
    dbAdvice(t).forEach(function (a) { if (d.advice.indexOf(a) < 0) d.advice.push(a); });

    /* The built-in list and the doctor's own list word the same
       instruction differently — "Daily 30 minute walk" vs "Walk 30
       minutes daily" — and both were being offered. Compare the set of
       significant word stems instead of the exact text: an anagram key
       fails here because "minute" and "minutes" differ by a letter. */
    d.advice = d.advice.filter(function (a, i, arr) {
      var stems = function (x) {
        return String(x).toLowerCase()
          .replace(/[^a-z0-9\s]/g, ' ')
          .split(/\s+/)
          .filter(function (w) { return w.length > 2; })
          /* Strip -ing, then a single trailing -s. Taking "es" off in one
             step turned "minutes" into "minut" while "minute" stayed, so
             the two never matched. */
          .map(function (w) { return w.replace(/ing$/, '').replace(/s$/, ''); })
          .sort().join(' ');
      };
      var mine = stems(a);
      return arr.findIndex(function (b) { return stems(b) === mine; }) === i;
    });
    d.followUp = findFollowUp(t);
    return d;
  }

  /* Show the conversation split by speaker. Each line can be flipped if the
     guess was wrong, because it sometimes will be. */
  function renderTurns() {
    if (!turnBox) return;
    if (!turns.length) { turnBox.innerHTML = ''; return; }
    var h = '<div class="sug-t" style="margin:14px 0 6px">Who said what '
          + '<span style="font-weight:400;text-transform:none;letter-spacing:0">'
          + '— tap Dr / Patient to correct a line</span></div>';
    turns.forEach(function (t, i) {
      h += '<div class="turn ' + (t.s === 'dr' ? 'turn-dr' : 'turn-pt') + '">'
        +  '<button type="button" class="turn-b" data-i="' + i + '">'
        +  (t.s === 'dr' ? 'Dr' : 'Patient') + '</button>'
        +  '<span>' + esc(t.t) + '</span></div>';
    });
    h += '<div class="ph" style="margin-top:6px">Disease / topic: <b>'
      +  esc(disease || 'not identified') + '</b></div>';
    turnBox.innerHTML = h;
    turnBox.querySelectorAll('.turn-b').forEach(function (b) {
      b.onclick = function () {
        var i = +b.dataset.i;
        turns[i].s = turns[i].s === 'dr' ? 'pt' : 'dr';
        /* rebuild the point lists from the corrected attribution */
        var SP = window.Speakers;
        drPts = SP.drPoints(turns, draft);
        ptPts = SP.ptPoints(turns, draft);
        if (hidTurns) hidTurns.value = JSON.stringify(turns);
        if (hidDr)    hidDr.value    = JSON.stringify(drPts);
        if (hidPt)    hidPt.value    = JSON.stringify(ptPts);
        renderTurns();
      };
    });
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) {
    return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' })[c]; }); }

  function line(id, label, detail) {
    return '<label class="sug"><input type="checkbox" checked data-k="' + id + '">' +
           '<span><b>' + esc(label) + '</b>' +
           (detail ? ' <span class="sug-d">' + esc(detail) + '</span>' : '') + '</span></label>';
  }

  if (makeBtn) makeBtn.onclick = function () {
    var text = (txtBox ? txtBox.value : finalText).trim();
    if (text.length < 8) return;
    draft = analyse(text);

    /* Separate who said what, then build the two point lists. */
    var SP = window.Speakers;
    turns   = SP ? SP.split(text) : [];
    disease = SP ? SP.disease(text, draft.symptoms, draft.diagnosis) : '';
    drPts   = SP ? SP.drPoints(turns, draft) : [];
    ptPts   = SP ? SP.ptPoints(turns, draft) : [];
    if (hidTurns)   hidTurns.value   = JSON.stringify(turns);
    if (hidDisease) hidDisease.value = disease;
    if (hidDr)      hidDr.value      = JSON.stringify(drPts);
    if (hidPt)      hidPt.value      = JSON.stringify(ptPts);
    renderTurns();

    buildSummary();
    /* Persist straight away. Without this a typed or pasted transcript never
       gets a note row, and the prescription saves with nothing linked to it. */
    saveLive(false);

    var h = '', any = false;

    if (draft.symptoms.length) {
      any = true;
      h += '<div class="sug-g"><div class="sug-t">Complaints heard</div>' +
           line('cc', draft.symptoms.join(', ') + (draft.dur ? ' — ' + draft.dur : ''),
                'goes into Diagnosis if you have not typed one') + '</div>';
    }
    if (Object.keys(draft.vitals).length) {
      any = true;
      h += '<div class="sug-g"><div class="sug-t">Vitals mentioned</div>';
      var vl = { bp:'BP', sugar:'Sugar', temp:'Temp', pulse:'Pulse', spo2:'SpO₂', weight:'Weight' };
      Object.keys(draft.vitals).forEach(function (k) {
        h += line('v:' + k, vl[k] + ' ' + draft.vitals[k], 'fills the vitals box');
      });
      h += '</div>';
    }
    if (draft.allergies && draft.allergies.length) {
      any = true;
      h += '<div class="sug-g sug-alert"><div class="sug-t">⚠ Allergy heard</div>';
      draft.allergies.forEach(function (a, i) {
        h += line('al:' + i, a, 'adds to the patient record and the safety checks');
      });
      h += '</div>';
    }
    if (draft.diagnosis) {
      any = true;
      h += '<div class="sug-g"><div class="sug-t">Diagnosis</div>' +
           line('dx', draft.diagnosis, '') + '</div>';
    }
    if (draft.meds.length) {
      any = true;
      h += '<div class="sug-g"><div class="sug-t">Medicines</div>';
      draft.meds.forEach(function (m, i) {
        h += line('m:' + i, m.name,
                  m.dose + ' ' + m.unit + ' · ' + m.when + ' · ' + m.freq + (m.dur ? ' · ' + m.dur : ''));
      });
      h += '</div>';
    }
    if (draft.labs.length) {
      any = true;
      h += '<div class="sug-g"><div class="sug-t">Tests</div>';
      draft.labs.forEach(function (l, i) { h += line('l:' + i, l, ''); });
      h += '</div>';
    }
    if (draft.advice.length) {
      any = true;
      h += '<div class="sug-g"><div class="sug-t">Advice</div>';
      draft.advice.forEach(function (a, i) { h += line('a:' + i, a, ''); });
      h += '</div>';
    }
    if (draft.followUp) {
      any = true;
      h += '<div class="sug-g"><div class="sug-t">Follow-up</div>' +
           line('fu', 'In ' + draft.followUp.days + ' days', draft.followUp.iso) + '</div>';
    }

    if (!any) {
      sugBox.innerHTML = '<div class="mic-out err">Nothing recognisable found. ' +
        'The transcript is still saved with the visit — you can type the prescription yourself.</div>';
      applyBtn.style.display = 'none';
      return;
    }
    h = '<div class="sug-h">Untick anything you do not want, then press Fill the form. ' +
        'Nothing is saved until you press Save prescription.</div>' + h;
    sugBox.innerHTML = h;
    applyBtn.style.display = '';
    show('Draft ready — check it.', 'ok');
  };

  /* ------------------------------------------------------------------
     Put the draft into the prescription form.

     live = true   called every few seconds while still recording.
                   Only fills fields the doctor has NOT touched, never
                   removes anything, and does not re-add a medicine that
                   is already on the form.
     live = false  the doctor pressed "Fill the form"; honours the tick
                   boxes exactly.
     ------------------------------------------------------------------ */
  var liveTouched = {};

  function markTouched(sel) {
    var el = document.querySelector(sel);
    if (el && !el.dataset.lw) {
      el.dataset.lw = '1';
      el.addEventListener('input', function () { liveTouched[sel] = true; });
    }
  }
  ['[name="diagnosis"]', '[name="advice"]', '[name="follow_up"]',
   '#labsInput', '[name="v_bp"]', '[name="v_temp"]', '[name="v_pulse"]',
   '[name="v_sugar"]', '[name="v_spo2"]', '[name="v_weight"]'].forEach(markTouched);

  function fillForm(live) {
    if (!draft) return 0;
    var picked = {};
    if (!live && sugBox) {
      sugBox.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
        picked[cb.dataset.k] = cb.checked;
      });
    }
    function want(key) { return live ? true : !!picked[key]; }

    var P = window.RxParse || {};
    var n = 0;

    /* vitals */
    Object.keys(draft.vitals).forEach(function (k) {
      if (!want('v:' + k)) return;
      var sel = '[name="v_' + k + '"]';
      if (live && liveTouched[sel]) return;          /* doctor typed it themselves */
      var el = document.querySelector(sel);
      if (el && el.value !== draft.vitals[k]) { el.value = draft.vitals[k]; n++; }
    });

    /* diagnosis */
    var dgSel = '[name="diagnosis"]';
    var dg = document.querySelector(dgSel);
    if (dg && !(live && liveTouched[dgSel])) {
      var newDx = '';
      if (want('dx') && draft.diagnosis) newDx = draft.diagnosis;
      else if (want('cc') && draft.symptoms.length && (!dg.value.trim() || live)) {
        newDx = draft.symptoms.join(', ') + (draft.dur ? ' — ' + draft.dur : '');
      }
      if (newDx && dg.value !== newDx) { dg.value = newDx; n++; }
    }

    /* medicines — never add one that is already on the form */
    if (P.emptyRow) {
      var onForm = {};
      document.querySelectorAll('[name="med_name[]"]').forEach(function (el) {
        if (el.value.trim()) onForm[el.value.trim().toLowerCase()] = true;
      });
      draft.meds.forEach(function (m, i) {
        if (!want('m:' + i)) return;
        if (onForm[m.name.toLowerCase()]) return;
        var row = P.emptyRow();
        function set(sel, v) { var el = row.querySelector(sel); if (el && v) el.value = v; }
        set('[name="med_name[]"]', m.name); set('[name="med_dose[]"]', m.dose);
        set('[name="med_unit[]"]', m.unit); set('[name="med_when[]"]', m.when);
        set('[name="med_freq[]"]', m.freq); set('[name="med_dur[]"]',  m.dur);
        set('[name="med_notes[]"]', m.notes);
        onForm[m.name.toLowerCase()] = true;
        n++;
      });
    }

    /* tests — additive only */
    var li = document.getElementById('labsInput');
    if (li && !(live && liveTouched['#labsInput'])) {
      var cur = li.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
      draft.labs.forEach(function (l, i) {
        if (want('l:' + i) && cur.indexOf(l) < 0) { cur.push(l); n++; }
      });
      li.value = cur.join(', ');
    }

    /* advice — additive only */
    var adSel = '[name="advice"]';
    var ad = document.querySelector(adSel);
    if (ad && !(live && liveTouched[adSel])) {
      var lines = ad.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
      draft.advice.forEach(function (a, i) {
        if (want('a:' + i) && lines.indexOf(a) < 0) { lines.push(a); n++; }
      });
      ad.value = lines.join('\n');
    }

    /* follow-up */
    var fuSel = '[name="follow_up"]';
    if (want('fu') && draft.followUp && !(live && liveTouched[fuSel])) {
      var f = document.querySelector(fuSel);
      if (f && f.value !== draft.followUp.iso) { f.value = draft.followUp.iso; n++; }
    }

    /* Allergies go to the patient record, not the prescription, so they
       travel in their own field. */
    if (draft.allergies && draft.allergies.length) {
      var keep = draft.allergies.filter(function (a, i) { return want('al:' + i); });
      var ha = document.getElementById('scribeAllergy');
      if (ha) ha.value = keep.join(', ');
    }

    if (!live && hidPick) hidPick.value = JSON.stringify(picked);
    if (typeof bindAuto === 'function') bindAuto();
    if (typeof preview === 'function') preview();
    return n;
  }

  if (applyBtn) applyBtn.onclick = function () {
    var n = fillForm(false);
    show(n + ' item' + (n === 1 ? '' : 's') + ' filled into the form. Check every line, then Save prescription.', 'ok');
    applyBtn.style.display = 'none';
  };

})();
