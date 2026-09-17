/* ------------------------------------------------------------------
   Microphone level meter.

   "Nothing was recorded" has several very different causes, and until
   now the app had to guess between them from the speech engine's own
   events. Those only say whether Google's service heard words — not
   whether the microphone is working at all.

   This reads the microphone directly with getUserMedia and an
   AnalyserNode, which is independent of speech recognition. That
   separates the two cases that need completely different fixes:

     bar stays flat   -> the microphone is dead, muted, or the wrong
                         device is selected. Nothing to do with speech.
     bar moves, no
     words appear     -> the microphone is fine; the language is wrong,
                         or the speech service is unreachable.

   It also lets the doctor confirm the microphone is live BEFORE the
   patient starts talking, rather than discovering it afterwards.
   ------------------------------------------------------------------ */
(function () {
  var ctx = null, analyser = null, stream = null, raf = null, data = null;
  var peak = 0, quietMs = 0, lastTick = 0, running = false;
  var onLevel = null;

  function supported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia &&
              (window.AudioContext || window.webkitAudioContext));
  }

  /* Start listening to the microphone. Resolves with the device label so
     the doctor can see which one is actually in use. */
  function start(cb) {
    onLevel = cb || null;
    if (!supported()) return Promise.reject(new Error('unsupported'));
    if (running) return Promise.resolve(deviceName());

    return navigator.mediaDevices.getUserMedia({
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      }
    }).then(function (s) {
      stream = s;
      var AC = window.AudioContext || window.webkitAudioContext;
      ctx = new AC();
      /* Some browsers start the context suspended until a gesture. */
      if (ctx.state === 'suspended' && ctx.resume) ctx.resume();

      var src = ctx.createMediaStreamSource(stream);
      analyser = ctx.createAnalyser();
      analyser.fftSize = 512;
      analyser.smoothingTimeConstant = 0.72;
      src.connect(analyser);
      data = new Uint8Array(analyser.frequencyBinCount);

      running = true; peak = 0; quietMs = 0; lastTick = Date.now();
      loop();
      return deviceName();
    });
  }

  function deviceName() {
    if (!stream) return '';
    var t = stream.getAudioTracks()[0];
    return t ? (t.label || 'Microphone') : '';
  }

  /* Is the track still live? A USB mic unplugged mid-consultation ends
     the track without any speech-recognition error at all. */
  function trackLive() {
    if (!stream) return false;
    var t = stream.getAudioTracks()[0];
    return !!(t && t.readyState === 'live' && t.enabled && !t.muted);
  }

  function loop() {
    if (!running) return;
    analyser.getByteFrequencyData(data);

    /* Average energy across the speech band, roughly 300 Hz - 3.4 kHz.
       Using the whole spectrum would let fan noise and mains hum look
       like speech. */
    var lo = 4, hi = Math.min(data.length - 1, 48), sum = 0, n = 0;
    for (var i = lo; i <= hi; i++) { sum += data[i]; n++; }
    var avg = n ? sum / n : 0;
    var level = Math.min(1, avg / 90);      /* 0..1 */

    if (level > peak) peak = level;

    var now = Date.now();
    var dt = now - lastTick; lastTick = now;
    if (level < 0.04) quietMs += dt; else quietMs = 0;

    if (onLevel) onLevel(level, { peak: peak, quietMs: quietMs, live: trackLive() });
    raf = requestAnimationFrame(loop);
  }

  function stop() {
    running = false;
    if (raf) { cancelAnimationFrame(raf); raf = null; }
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    if (ctx && ctx.close) { try { ctx.close(); } catch (e) {} }
    ctx = null; analyser = null;
  }

  /* Did the microphone deliver any real sound during the session?
     This is the honest answer to "was anything actually recorded". */
  function heardAnything() { return peak > 0.06; }

  window.MicCheck = {
    supported: supported,
    /* The audio recorder reuses this rather than calling getUserMedia
       again — a second open can fail outright on some machines. */
    stream: function () { return stream; },
    start: start,
    stop: stop,
    peak: function () { return peak; },
    quietMs: function () { return quietMs; },
    heardAnything: heardAnything,
    device: deviceName,
    live: trackLive
  };
})();
