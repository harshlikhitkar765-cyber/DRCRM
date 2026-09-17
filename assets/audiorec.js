/* ------------------------------------------------------------------
   Audio recording.

   Speech recognition can fail for reasons outside the clinic's control:
   the wrong language is selected, Google's service is unreachable, the
   accent is not understood. Until now, when that happened the whole
   consultation was simply gone.

   This records the audio itself with MediaRecorder, on the same
   microphone stream the level meter already opened. It runs whether or
   not transcription works, so the visit is always captured — the
   recording can be played back, typed up, and kept as the note.

   Important: this is the patient's voice. It is stored against the
   visit like any other clinical record, and the doctor can delete it.
   ------------------------------------------------------------------ */
(function () {
  var rec = null, chunks = [], mime = '', startedAt = 0, stopped = null;

  function supported() {
    return typeof MediaRecorder !== 'undefined';
  }

  /* Pick a container the browser can actually produce. Chrome gives
     webm/opus, Safari gives mp4 — asking for the wrong one throws. */
  function pickMime() {
    var want = [
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/mp4',
      'audio/ogg;codecs=opus'
    ];
    for (var i = 0; i < want.length; i++) {
      if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(want[i])) return want[i];
    }
    return '';
  }

  /* Start recording from an existing MediaStream. Reusing the stream the
     meter already has avoids opening the microphone twice, which some
     machines refuse outright. */
  function start(stream) {
    if (!supported() || !stream) return false;
    if (rec && rec.state === 'recording') return true;

    chunks = [];
    mime = pickMime();
    try {
      rec = mime ? new MediaRecorder(stream, { mimeType: mime, audioBitsPerSecond: 32000 })
                 : new MediaRecorder(stream);
    } catch (e) {
      try { rec = new MediaRecorder(stream); } catch (e2) { return false; }
    }

    rec.ondataavailable = function (e) {
      if (e.data && e.data.size > 0) chunks.push(e.data);
    };
    stopped = null;
    startedAt = Date.now();
    /* A timeslice means data arrives continuously, so a crash or a closed
       tab does not lose everything recorded so far. */
    rec.start(4000);
    return true;
  }

  function stop() {
    return new Promise(function (resolve) {
      if (!rec || rec.state === 'inactive') { resolve(blob()); return; }
      rec.onstop = function () { resolve(blob()); };
      try { rec.stop(); } catch (e) { resolve(blob()); }
    });
  }

  function blob() {
    if (stopped) return stopped;
    if (!chunks.length) return null;
    stopped = new Blob(chunks, { type: mime || 'audio/webm' });
    return stopped;
  }

  function seconds() {
    return startedAt ? Math.round((Date.now() - startedAt) / 1000) : 0;
  }

  function sizeKB() {
    var t = 0;
    chunks.forEach(function (c) { t += c.size; });
    return Math.round(t / 1024);
  }

  function reset() { rec = null; chunks = []; stopped = null; startedAt = 0; }

  window.AudioRec = {
    supported: supported,
    start: start,
    stop: stop,
    blob: blob,
    seconds: seconds,
    sizeKB: sizeKB,
    reset: reset,
    state: function () { return rec ? rec.state : 'inactive'; }
  };
})();
