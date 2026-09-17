# Roadmap

## ✅ Shipped

| Feature | Where |
|---|---|
| Prescription safety checks (+ Indian brand names) | `inc/safety.php` |
| Follow-up recalls | `recall.php` |
| **Smart Pad Link (QR → phone → desk)** | `padlink.php`, `pad.php`, `inc/pad.php` |
| Billing & day book | `billing.php` |
| Patient replies + Meta webhook | `inbox.php`, `webhook.php` |
| Lab report storage | `documents.php` |
| Vitals trend charts | `inc/trend.php` |
| Repeat prescription | queue → ⟲ Repeat |
| Hashed logins, audit trail, consent, backup | `inc/auth.php`, `backup.php` |
| **Your own drug/test/favourites database** | `drugs.php` |
| **Voice dictation (Hindi + English, free)** | `assets/voice.js` |
| **Ambient scribe — record the consultation, draft the Rx from it** | `assets/scribe.js`, `consult_notes` |
| **MySQL / MariaDB only — no SQLite anywhere (host-ready)** | `inc/db.php`, `DATABASE.md` |
| **Speaker separation — doctor's points vs patient's points, + disease** | `assets/speakers.js`, `consult_notes` |
| **Live: saves to DB and fills the Rx while they are still talking** | `api/livenote.php`, `assets/scribe.js` |
| **End-of-visit consultation record — all main points, timed and stored** | `assets/summary.js`, `consult_notes.summary` |
| **Main points printed on the prescription** | `print.php` |
| **Typed prescriptions also stored as a record; real mic diagnostics** | `consult.php`, `assets/scribe.js` |
| **Recording reliability: edit-safe box, restart backoff, final-only saves** | `assets/scribe.js` |
| **Phone layout (tables become cards, bottom nav) + professional polish** | `assets/app.css`, `assets/responsive.js` |
| **Professional sidebar: SVG icons, workflow groups, signed-in identity** | `inc/layout.php`, `assets/app.css` |
| **Everything in the database + Settings page (21 tables, nothing hardcoded)** | `settings.php`, `inc/refdata.php` |
| **Exported SQL schema + column reference; consult_notes FKs fixed** | `schema.sql`, `TABLES.md` |
| **https-required notice as a proper UI component; typed route signposted** | `assets/scribe.js`, `assets/app.css` |
| **Web-app routing + rebuilt sign-in screen (design system retained)** | `index.php`, `login.php`, `assets/design.css` |
| **One-click demo access; fixed CSRF accepting an empty token** | `login.php`, `inc/config.php` |
| **Failed recording offers recovery: switch language, or type it** | `assets/scribe.js` |
| **Responsive pass: 287 touch targets fixed, grid blowout, clipped tables** | `assets/app.css`, `backup.php` |
| **Cache-busting on all CSS/JS so uploads take effect immediately** | `inc/layout.php`, `CACHE.md` |
| **Auto-sync: open pages detect updates, .htaccess cache rules, file health check** | `assets/sync.js`, `api/build.php`, `.htaccess`, `inc/sync.php` |
| **Live dock — watch the transcript and the prescription build together** | `assets/livedock.js` |
| **Detect Brave/Firefox, which block speech recognition entirely** | `assets/scribe.js` |
| **Live mic level meter — measured diagnosis instead of guesswork** | `assets/miccheck.js` |
| **Audio always recorded, so a failed transcription loses nothing** | `assets/audiorec.js` |
| **Hamburger drawer on small screens; shared boot.php cuts repetition** | `inc/boot.php`, `inc/icons.php`, `assets/responsive.js` |
| **Spoken brand names now match; dose timing read from the right phrase** | `consult.php`, `assets/voice.js` |
| **Accuracy harness: 10 scored consultations, 86% → 100%, 4 bugs fixed** | `tests/accuracy.py`, `tests/cases.json` |
| **Problem + illness shown live and on the record, no replay needed** | `assets/livedock.js`, `patient.php` |
| **Allergies heard in the consultation reach the record and safety checks** | `assets/scribe.js`, `consult.php` |
| **Self-learning defaults + daily automation + view helpers** | `inc/auto.php`, `api/learn.php`, `inc/boot.php` |
| **New UI theme "Indigo" — retokenised palette, shape and type** | `assets/app.css` |
| **More illness phrasings, Hindi precautions, no stale pre-filled diagnosis** | `assets/scribe.js`, `consult.php` |
| **Full formulary: 136 drugs, 93 diagnoses, 40 tests, 45 symptoms** | `tools/seed_clinical.php` |
| **Diagnoses recognised by name; symptoms 45 → 75; plural-match bug fixed** | `assets/scribe.js`, `consult.php` |
| **Printed prescription rebuilt for A4 — print units, clear Rx table, footer** | `print.php` |
| **Mobile recording fixed; eraser spares the letterhead; vitals table** | `assets/scribe.js`, `write.php`, `inc/auto.php` |
| **Hinglish mode — mixed-language recognition, 123/123 accuracy** | `consult.php`, `assets/scribe.js`, `tests/cases.json` |

## Next

1. **HTTPS + a real domain.** Blocks nothing today on a LAN, blocks everything
   the moment you go online. Also required before the Meta webhook will work —
   Meta only calls HTTPS endpoints.
2. **Daily summary WhatsApp at 9:30 PM** — seen, collected, tomorrow's bookings,
   overdue recalls. Small cron job.
3. **Multi-doctor** — the app assumes one physician. Add a `doctor_id` to
   appointments if a partner joins.
4. **ABDM / ABHA integration** — real linkage rather than a stored number.
5. **Inventory** — if the clinic dispenses medicines.
6. **Handwriting OCR** — possible via Google Vision (~₹0.12/page), but accuracy on
   doctors' handwriting is poor and a wrong drug name is dangerous. Not recommended.
