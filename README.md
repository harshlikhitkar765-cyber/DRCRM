# Dr Bakshi Clinic — PHP Web App

Clinic CRM with **custom prescriptions sent over WhatsApp**.
Dr. Raja Bakshi, MBBS MD (General Medicine) · M.P. Nagar, Bhopal · +91 97551 08769

## Run

Requires PHP 8+ with **`pdo_mysql`**, `curl`, `mbstring`, `gd`, `zip`.
No Composer or build step is needed.

1. Create a MySQL/MariaDB database and user.
2. Copy `data/config.local.php.example` to `data/config.local.php` and set the
   database values plus `INITIAL_DOCTOR_USERNAME` / `INITIAL_DOCTOR_PASSWORD`.
   Set `APP_URL` to the canonical public HTTPS address if using Smart Pad links
   or handwritten/scan image links. That private file is ignored by Git and
   denied from the web.
3. Start the app:

   ```bash
   php -S 0.0.0.0:3000 -t /home/user/DRCRM
   ```

The first request creates the schema and your configured initial account.
For a **private local demo only**, set `DEMO_MODE` to `1` in the local config;
then the sample accounts are `drbakshi` / `clinic@2026` and `reception` /
`front@2026`. Never enable demo mode on a public site or with real patient data.

## Database — MySQL / MariaDB

The app runs on a **MySQL/MariaDB server**. There is no database file and no
credential is stored in the repository. Full setup, migration and
troubleshooting: **`DATABASE.md`**.

To reset the demo, drop and recreate the database:

    mysql -u USER -p -e "DROP DATABASE dbname; CREATE DATABASE dbname CHARACTER SET utf8mb4;"

## Pages

| File | What it does |
|---|---|
| `queue.php` | OPD queue — Waiting / Finished / Cancelled tabs, date + name filter |
| `consult.php` | Consultation: vitals, diagnosis, medicine rows, lab tags, advice, follow-up + **live WhatsApp preview** |
| `send.php` | Final editable message + send |
| `print.php` | Printable letterhead prescription |
| `patient.php` / `patients.php` | Chart with Rx history & WhatsApp log / register |
| `templates.php` | **Edit the WhatsApp message wording** (English / Hindi / Marathi) |
| `messages.php` | WhatsApp delivery log |
| `homecare.php` | Home Care & Home ICU patients |

## How the WhatsApp send works

1. Doctor fills the consultation → prescription saved to the database.
2. Message built by `render_template()` from the template for that patient's language.
   Placeholders: `{{patient}} {{diagnosis}} {{vitals}} {{medicines}} {{labs}} {{advice}}
   {{followup}} {{clinic}} {{doctor}} {{qual}} {{phone}} {{address}} {{hours}} {{date}}`.
   Empty sections (heading included) are stripped automatically.
3. Delivery driver — set `WA_DRIVER` in `data/config.local.php` (or `DRCRM_WA_DRIVER`):
   * **`link`** (default): builds `https://wa.me/<number>?text=<message>`. Free, no approval,
     a human presses send. Works today.
   * **`cloud`**: cURL POST to Meta WhatsApp Business Cloud API. Set `WA_PHONE_ID` + `WA_TOKEN`.

Prescription sends are server-blocked unless the patient has recorded WhatsApp
consent. For Cloud API inbound messages, also configure `WA_APP_SECRET` and
`WA_VERIFY_TOKEN`; unsigned webhook requests are rejected.

## Going live with the Cloud API (honest notes)
* Needs a verified Meta business + WhatsApp Business Account.
* Business-initiated messages need a **pre-approved template**; free-form text only
  delivers inside the 24-hour customer service window.
* Utility messages cost roughly ₹0.12–0.15 each in India.
* Get DPDP-compliant opt-in before messaging patients.
* Configure the initial doctor account through `data/config.local.php` (or
  `DRCRM_INITIAL_DOCTOR_*` environment variables). Passwords are stored as
  `password_hash()` values; `DEMO_MODE` is intentionally opt-in. Registration
  number `MPMC-2009-14872` is a placeholder.

---

## Handwritten prescriptions (touch panel / stylus)

`write.php` — the doctor writes the prescription by hand on a real letterhead
instead of typing it. Reached from the queue (**✍ Write**) or from `consult.php`.

* Pen / eraser, three nib widths, three ink colours, undo, clear
* **Pressure sensitive** — a real stylus varies the stroke weight
* **"Stylus only" toggle = palm rejection.** Tick it and finger/palm touches are
  ignored, so the doctor can rest a hand on the glass
* The clinic letterhead, patient name, age, ABHA, date, allergy warning, ℞ mark,
  ruled lines and signature block are **painted onto the canvas**, so the saved
  PNG is a complete legal-looking document, not a bare scribble
* Saved to `data/rx/rx_<patient>_<timestamp>.png`, linked to the prescription row
  via the `ink_file` / `ink_mode` columns
* Shows inline in the patient chart

### The one real constraint

**A `wa.me` click-to-chat link can only carry text — it cannot attach an image.**

So the two drivers behave differently for handwritten scripts:

| Driver | What the patient gets |
|---|---|
| `link` (default) | Caption text + a **secure link** to the image. Doctor can also attach the PNG by hand in WhatsApp. |
| `cloud` | A real WhatsApp **image message** with the caption — fully automatic. `wa_upload_media()` uploads the PNG to Meta, then sends it by media id. |

If sending handwritten scripts automatically matters to you, that is the
argument for enabling the Cloud API.

### Image link security
Patient links are signed: `rximg.php?rx=12&t=<hmac>`. The token is an HMAC of the
prescription id using `data/secret.key` (generated once, 32 random bytes). Without
a valid token or a login session the endpoint returns 404, so nobody can
enumerate `?rx=1,2,3` and harvest other patients' prescriptions.

**Note:** `rx_image_url()` builds the link from the current host. On a LAN-only
install the patient's phone cannot open a `192.168.x.x` link over mobile data —
either use the Cloud API, put the box behind a domain, or attach the image manually.

---

## Paper → photo → digital (`scan.php`) — the zero-cost route

The cheapest option of all: **keep writing on paper exactly as you do now.**
Then photograph the sheet and the app digitises it. No tablet, no stylus, no
board — just the phone already in your pocket. **Cost: ₹0.**

Reached from the queue (**📄 Scan**) or from `consult.php`.

**Flow**
1. Write the prescription on your usual pad.
2. In the queue tap **📄 Scan** next to the patient → phone camera opens.
3. Photograph the sheet.
4. The app auto-cleans it, then **Save & send on WhatsApp**.

**Automatic clean-up** (all in the browser, no server cost, no upload to anyone):
* **Paper scan** mode — forces the paper to pure white and the ink to near-black,
  removing yellow tint, grey shadows and uneven light
* Greyscale and Original modes if you prefer the raw look
* Brightness / contrast sliders, rotate, retake
* Long edge capped at 1600px → a 4 MB phone photo becomes **~40 KB**, so it sends
  instantly even on a weak mobile connection

Stored exactly like a handwritten script (`ink_mode=1`), so it shows in the
patient chart, goes through the same WhatsApp send, and is protected by the same
signed `rximg.php?rx=..&t=..` link.

**Trade-off, stated plainly:** like any image, a scanned page is **not searchable**.
Fill the optional *Diagnosis* box to keep the chart searchable. And a photo of
your handwriting has the same chemist-misreading risk as the paper itself — for
medicines, typing is still safer.

---

## Prescription safety checks (`inc/safety.php`)

The app used to *display* allergies as a banner but never actually **check** them.
A patient recorded as NSAID-allergic could be prescribed `Tab Aspirin 75mg` and
nothing would object. That is now fixed.

As the doctor types each medicine, `api/preview.php` runs `safety_check()` and
red/amber alerts appear live beside the prescription. Five kinds of check:

1. **Allergy** — drug family map (NSAID, penicillin, sulfa, cephalosporin,
   macrolide, quinolone, statin, ACE) matched against the patient's allergy field
2. **Condition** — CKD, CHF, asthma, COPD, peptic ulcer, thrombocytopenia,
   dengue, pregnancy
3. **Interaction** — aspirin+clopidogrel, ACEi+ARB, macrolide+statin,
   omeprazole+clopidogrel, warfarin combinations, etc.
4. **Duplicate ingredient** — e.g. Dolo 650 *and* Paracetamol 500 in one script
5. **Age** — doxycycline / quinolones / aspirin under 12 years

**Red alerts block the save** with a confirmation dialog; the doctor can still
override, because the doctor is the doctor.

**Verified:** Lata Verma (NSAID allergy, CHF, CKD 3) prescribed aspirin +
metformin + clopidogrel produces 3 red and 3 amber alerts. The same patient given
paracetamol alone produces **zero** — no false alarms.

**Limits, stated honestly:** this matches on ingredient names in free text, so a
brand name the map doesn't know (e.g. "Ecosprin" for aspirin) will be missed. It
covers common general-medicine rules only — it is a safety net for tired
evenings, not a clinical decision support system, and it is not a substitute for
the doctor's judgement.

## Follow-up recalls (`recall.php`)

Every prescription captured a follow-up date and **nothing ever used it**. Now it
drives a recall list: Overdue / Due today / Upcoming, with the patient dropped off
the list automatically once they actually return.

Each row has a one-tap **Remind on WhatsApp** button with the reminder message
pre-written (patient name, last diagnosis, the date they missed, clinic hours).

This is the single highest-value addition commercially — chasing patients who were
told to come back and didn't is how a clinic fills its evening OPD.


---

# v2 — everything added in this round

## 📱 Smart Pad Link — write on your phone, it lands on the desk

The desk shows a **QR code** (`padlink.php`). Scan it with any phone or tablet
camera — the pad opens **already paired to that patient**, with the clinic
letterhead, name, ABHA and allergy warning drawn on it. Write or photograph,
press **Send to desk**, and the desk screen picks it up automatically and turns
it into a prescription ready to WhatsApp.

* **No app to install. No login on the phone.** The one-time token in the URL is
  the credential.
* **Expires in 15 minutes and works once.**
* Two modes: **✍ Writing pad** (stylus/finger, pressure-sensitive) or
  **📄 Photograph paper**.
* The QR is generated by a **self-contained encoder in `inc/pad.php`** — no
  library, no CDN, rendered as inline SVG. Verified by decoding the real output
  with OpenCV.

This is the cheapest possible "smart board": **your existing phone, ₹0.**

## ₹ Billing (`billing.php`)
Day book with collected / pending / month-to-date / total outstanding. Add a
charge, settle by Cash / UPI / Card. Outstanding balance shows on the patient chart.

## ↩ Patient replies (`inbox.php` + `webhook.php`)
The templates said "Reply 1 to confirm" and nothing listened — that was a lie in
the product. Now the Meta webhook captures replies and classifies them:
**confirm / reschedule / optout / help**. Replying **STOP automatically revokes
WhatsApp consent.** Until you enable the Cloud driver, `inbox.php` says so plainly
rather than pretending.

## 🧪 Reports (`documents.php`)
Photograph lab reports, X-rays and discharge summaries into the patient chart.
Thumbnails appear on the chart; images are login-gated via `docimg.php`.

## 📈 Vitals trends
Systolic BP, blood sugar and weight plotted across visits on the patient chart —
hand-drawn SVG, no library, with the change since the first reading.

## ⟲ Repeat prescription
One click on the queue row loads the last prescription's medicines.

## 💊 Brand names in safety checks
The checker knows ~60 Indian brands — **Ecosprin → aspirin, Dolo → paracetamol,
Glycomet → metformin, Zerodol → aceclofenac**. Verified: prescribing
"Tab Ecosprin 75" to an NSAID-allergic patient now fires the allergy alert.

## 🔐 Security & compliance
* **Real user accounts** — `users` table with `password_hash()`; the plaintext
  `USERS` constant is gone. Hashes upgrade themselves on login.
* **Audit trail** — every login, prescription, payment, send and consent change
  is logged with user, IP and timestamp. Viewable in `backup.php`.
* **WhatsApp consent** per patient. `send.php` shows a red warning if consent is
  missing or was revoked.
* **One-click backup** — `backup.php` zips the database plus every prescription
  and report image, with a ready-to-paste cron line for nightly copies.

## Still outstanding — genuinely, before real patients
1. **HTTPS.** Credentials and patient data still travel in clear text on a LAN.
2. **The registration number `MPMC-2009-14872` is a placeholder** and prints on
   every prescription until you replace it in **Settings → Clinic details**.
3. Use a unique initial doctor password and keep `DEMO_MODE` off in production.
4. The safety checker is a typo net, not clinical decision support.

---

## v3 — your own drug database, and dictation instead of typing

Two things in this round: the medicine list became **yours**, and you can now
**speak** a prescription instead of typing it.

### 1. My drug database (`💊 Drugs` in the sidebar)

The medicine and test lists used to be hardcoded — you got what the developer
chose. They now live in the database and you edit them yourself.

**Three tabs:**

| Tab | What it holds |
|---|---|
| 💊 Medicines | Your medicines, each with a default dose, unit, timing, frequency, duration and a standing note |
| 🧪 Tests | Your lab tests, grouped |
| ⭐ Favourites | Whole prescriptions saved under a name |

**The point of the defaults:** pick "Tab Amlodipine 5mg" in a consultation and
the row fills itself in — 1 tab, After Food, OD, 30 days. You only change what's
different for this patient. Most prescriptions become one click per medicine.

**Favourites** are the bigger saving. At the bottom of the consultation there's
a "Save this as a favourite" box — name it "Viral fever - adult" and the whole
thing (diagnosis, all medicines, tests, advice) comes back from the
"⭐ Use a favourite…" dropdown next time. Verified working end-to-end.

**Both lists sort by what you actually prescribe.** Every save bumps a usage
counter, so your common medicines float to the top and the list matches your
practice within a few weeks.

**Deleting is safe.** Removing a medicine sets `active=0` — it disappears from
the pickers but old prescriptions that reference it still print correctly. A
drug you prescribed in 2024 is never erased from a 2024 record.

Generic/salt names feed the safety checker, so adding your own brand still
triggers the allergy and contraindication warnings.

### 2. Voice dictation

A **🎤 Dictate** button sits above the medicines table with an
**English / हिन्दी** switch. Press it and say:

> "Paracetamol 650 twice daily after food for three days"

and the row fills in: *Tab Paracetamol 650mg · 1 tab · After Food · TDS · 3 days*.

Hindi works too — *"khane ke baad paracetamol do baar paanch din"* produces
After Food, BD, 5 days.

**How it understands you:**

- **Medicine** is matched against *your own list* — so it can only ever pick a
  medicine you actually stock, and it picks up the strength you said ("650").
- **Frequency** — once/twice/thrice/ek baar/do baar/teen baar/SOS → OD/BD/TDS/SOS
- **Timing** — after food/before food/empty stomach/bedtime/khane ke baad/khali pet
- **Duration** — "three days", "one month", "paanch din" (words or digits)
- **Several medicines in one breath**, split on "and then" / "also" / "aur".
- Say a **test name** and it goes to Tests Advised instead.
- If nothing matches a medicine, the text drops into Diagnosis rather than
  vanishing.

Everything it heard is echoed under the button, and every field stays editable —
**it fills the form, it never submits it.** You always see the prescription
before it saves.

**Cost: zero.** It uses the speech recognition already built into Chrome and
Edge — no API key, no subscription, nothing to install. On a browser without it
the button greys out to "Not supported" and typing works as before.

**Honest limits:** it needs a working microphone and an internet connection
(Chrome does the recognition in the cloud), it will mishear unusual names in a
noisy room, and Hindi accuracy is lower than English. Check the row before you
save — which you'd do anyway.

---

## v4 — record the consultation, and let the prescription come out of it

You asked to record the doctor–patient conversation and make the prescription
from it. That is what the **🎙 Record conversation** button at the top of the
consultation page does.

### How you use it

1. Press **🎙 Record conversation** at the start of the visit (English or हिन्दी).
2. Talk normally. You and the patient, ordinary conversation. Nothing to think
   about.
3. Press **⏹ Stop** when you are done. The whole conversation is on screen as
   text — you can correct any word it misheard.
4. Press **✨ Make prescription from this**.
5. You get a **tick list** of everything it found. Untick anything wrong.
6. Press **↓ Fill the form**, check every line, then **Save prescription**.

### What it pulls out of the conversation

From a real-sounding visit it correctly found all of this:

| It heard | It produced |
|---|---|
| "fever since three days and body ache also headache… dry cough and sore throat" | **Complaints:** fever, cough, sore throat, headache, body ache — 3 days |
| "temperature is 101.2 and BP is 130 by 80 pulse 92" | **Vitals:** Temp 101.2 · BP 130/80 · Pulse 92 |
| "this is viral fever" | **Diagnosis:** viral fever |
| "paracetamol 650 three times daily after food for three days" | **Tab Paracetamol 650mg** · 1 tab · After Food · TDS · 3 days |
| "pantoprazole one tab empty stomach for five days" | **Tab Pantoprazole 40mg** · 1 tab · Empty Stomach · OD · 5 days |
| "we will do CBC and Dengue NS1" | **Tests:** CBC, Dengue NS1 |
| "drink plenty of fluids and take rest" | **Advice:** Plenty of fluids, Take rest |
| "come back in three days for review" | **Follow-up:** 8 Sep 2026 |

Hindi works the same way — *"do din se pet dard aur ulti… paani piye aur tel
masala mat khaiye… char din baad dubara aana"* gives stomach pain + vomiting
for 2 days, the fluids and no-oily-food advice, and a 4-day follow-up.

It understands about 18 common complaints, six vitals, your medicines, your
tests, nine standard advice lines and follow-up intervals — in both languages,
in words or digits ("three days" and "3 days" both work).

### The rule: it suggests, you decide

**Nothing is ever entered on its own.** Every single item comes with a tick box
and you approve it. Untick a medicine and it does not go in — tested. Even after
you press "Fill the form", the prescription is only a draft on screen until you
press **Save prescription**. The recording never prescribes anything by itself.

### The transcript is kept as your case note

The conversation is saved with the visit in a `consult_notes` table and appears
on the patient's record under **🎙 Recorded conversation**. Months later you can
open the visit and read exactly what the patient said and what you told them —
which is a better clinical note than anything you would have had time to type,
and it is the record behind why you prescribed what you did.

### Cost and limits — honestly

**Cost is zero.** It is the speech recognition already inside Chrome and Edge.
No API key, no subscription, no per-minute charge, nothing to install. If the
browser has no speech recognition the button says so and everything else works
as before.

What it will not do:

- **It needs internet.** Chrome sends audio to Google for recognition. That has
  a real privacy consequence — see below.
- **It mishears.** Noisy room, strong accents, drug names it has never heard.
  Always read the transcript and the tick list. Hindi accuracy is noticeably
  lower than English.
- **It is keyword matching, not comprehension.** It finds a medicine you already
  have in your list; it does not reason about the case. If you say "we will NOT
  give antibiotics" it may still suggest the antibiotic — which is exactly why
  every item has a tick box.
- **Audio is not stored**, only the text. If you need the actual recording for
  medico-legal reasons, this does not give you that.

### Two things to decide before using this on real patients

1. **Tell the patient they are being recorded and get their consent.** Under the
   DPDP Act a consultation recording is sensitive personal data. A line on the
   wall of the consulting room and a verbal "I am recording this to write your
   prescription, is that alright?" is the minimum. Consider adding a per-patient
   consent tick like the WhatsApp one already in the app.
2. **The audio goes to Google's servers** during recognition. For most clinics
   that is an acceptable trade for a free scribe, but you should know it rather
   than discover it. Fully offline recognition would need a paid on-premise
   model, which contradicts the zero-cost rule you set.

---

## v5 — moved off SQLite onto MySQL / MariaDB

The app no longer keeps everything in one `.sqlite` file. It now talks to a
real MySQL/MariaDB server, which is what shared hosting gives you.

**Full setup and migration instructions are in `DATABASE.md`.** The short
version:

1. Create a database and user in your host's control panel.
2. Put the four values into the untracked `data/config.local.php` (`DB_HOST`,
   `DB_NAME`, `DB_USER`, `DB_PASS`).
3. (Historical) A one-time importer moved the old file's data across. It has
   since been removed — the app has no SQLite dependency at all now.

Your data is not lost in the move — the importer copied all **85 rows** of the
demo database (9 patients, 7 appointments, 20 medicines, 18 tests, 3 templates,
3 home-care plans, 2 users, the audit trail) with the row counts verified
identical on both sides.

### What this changes for you

- **It runs on any cheap Indian host** — Hostinger, GoDaddy, cPanel. That was
  the point.
- **Several people can use it at once.** Reception booking while you prescribe
  is now a normal thing rather than a risk of a locked file.
- **Deletes are consistent.** Real foreign keys mean removing a patient also
  removes their appointments, bills and documents, instead of leaving orphans.
- **Backups are proper SQL dumps.** Backup → Download gives a `.zip` with a
  `.sql` file you can restore anywhere, plus the images. Tested by restoring
  into an empty database and checking every row came back.

### Hindi and emoji

Everything is `utf8mb4`. This is worth stating because it is the single most
common way an Indian clinic app gets corrupted: MySQL's plain `utf8` is only
three bytes and silently destroys emoji. Verified end to end — 🏥, विज़िट सारांश
and बुखार सेट all survive a full backup-and-restore.

### Two bugs this shook out

- **MySQL rejects `''` in a date column** where the old engine accepted it.
  Saving a prescription with an empty follow-up date threw a 500. Fixed with a
  `dnull()` helper that stores a blank as a proper `NULL`.
- **`consult.php` called `audit()` without including `inc/auth.php`.** This was
  an existing bug from the scribe work, not caused by the database change — it
  only fired when a recorded transcript was present, so it had not shown up
  before. Every prescription saved with a recording was failing at the last
  step. Fixed, and I checked every other file for the same mistake.

### Tested

30 pages load with no PHP warnings and no SQL errors; login rejects a wrong
password; logged-out pages redirect and image endpoints return 403. Saving a
prescription, adding a patient, adding a medicine, recording consent, taking a
payment and booking an appointment all write correctly. The drug auto-fill,
voice dictation and the ambient scribe were re-tested in a real browser against
MySQL and behave exactly as before.

### Still true, still worth fixing

Going onto a web host makes the earlier warnings urgent rather than
theoretical: **turn on HTTPS** (free, one click on most panels), use a unique
initial doctor password, and leave `DEMO_MODE` off before a real patient's data
goes in. The placeholder registration number `MPMC-2009-14872` must be replaced
in Settings before issuing prescriptions.

---

## v6 — who said what, and the disease it was about

The recording no longer stores one lump of text. It now separates **what the
doctor said** from **what the patient said**, works out **what disease the
conversation was about**, and saves all of it in the database as three
separate things.

### On screen while you record

After you press **✨ Make prescription from this**, above the tick list you
now see the conversation split into turns:

```
[Dr     ] good evening what happened
[Patient] sir I have fever since three days and body ache also headache
[Dr     ] any cough
[Patient] yes sir dry cough and sore throat since two days
[Dr     ] let me check. your temperature is 101.2 and BP is 130 by 80 pulse 92
[Patient] sir will I be alright
[Dr     ] yes you will be fine. drink plenty of fluids and take rest
```

Doctor lines are green, patient lines amber. **Every line has a Dr / Patient
button — tap it to flip that line** if the guess was wrong. The two point
lists rebuild instantly when you correct one.

### What gets stored

Four new columns on `consult_notes`:

| Column | Holds |
|---|---|
| `disease` | What the visit was about — "viral fever", or the complaints if you never named it |
| `pt_points` | What the patient reported |
| `dr_points` | Your findings, diagnosis, plan and advice |
| `turns` | The full speaker-tagged conversation |

From the example above it saved:

**Disease:** viral fever

**Patient said**
- Complaints: fever, cough, sore throat, headache, body ache (since 3 days)
- Sir I have fever since three days and body ache also headache
- Yes sir dry cough and sore throat since two days
- Sir will I be alright

**Doctor said**
- Diagnosis: viral fever
- Examined: BP 130/80, Temp 101.2, Pulse 92
- Prescribed: Tab Paracetamol 650mg (TDS, 3 days)
- Tests advised: CBC, Dengue NS1
- Advice: Plenty of fluids; Take rest; Come back immediately if it worsens
- Follow-up in 3 days (2026-09-08)
- Yes you will be fine

On the patient's record each visit now shows the disease as a tag, then the
two lists side by side, with the full conversation folded away underneath.

### How it decides who is speaking

**One microphone cannot tell two voices apart**, and this does not pretend
to. It reads the sentence the way you would reading a transcript — by what
the sentence *is*, not by how the voice sounds:

- A **question**, an instruction, a drug name, a vital sign, a diagnosis → doctor
- A **complaint**, a symptom, "since three days", "yes sir" → patient

It handles Hindi the same way, including the fact that Hindi puts the
question word at the end ("khana kaisa hai") where English puts it at the
front ("any cough").

Tested on full consultations in both languages, every turn came out on the
right side. But it **will** get lines wrong sometimes — which is exactly why
each line has a toggle. Treat it as a first draft of a note, not a court
transcript.

### Honest limits

- **Not true speaker identification.** Two people with a similar way of
  speaking, or a patient who talks like a doctor, will confuse it. Real voice
  separation needs a paid diarisation service.
- **A third voice is not handled.** A relative in the room gets labelled as
  the patient.
- **It is still keyword matching.** "Nothing to worry" is filed as something
  you said, but the software does not understand reassurance.
- Everything already said about the recording still applies: it needs
  internet, the audio goes to Google for recognition, and you should tell the
  patient they are being recorded.


---

## v6.1 — workspace cleaned up

Removed the leftovers from earlier rounds, since they were no longer part of
the app:

| Removed | Why |
|---|---|
| `calcine-crm/` | The very first single-file HTML prototype, replaced by the PHP app |
| `uploads/image-1.png` | The reference screenshot the layout was built from |
| `data/clinic.sqlite` | The old single-file database — now migrated into MySQL |

**The SQLite file was only deleted after verifying the migration.** Every one
of the 16 tables was compared row-for-row between the old file and MySQL —
patients 9/9, appointments 7/7, drugs 20/20, labs 18/18, audit 18/18 and so
on, all matching — before anything was removed.

Nothing in the working app changed. All 27 pages still load with no PHP
warnings, backup still produces a valid zip, and the speaker separation added
in v6 was re-tested end to end and behaves exactly as before.

`migrate_sqlite.php` has since been deleted along with the `pdo_sqlite`
dependency — see v7.1 below.

---

## v7 — live: it records to the database *and* fills the prescription while you talk

Until now the recording only reached the database when you pressed **Save
prescription** at the end. If the tab closed or the power went, the whole
conversation was gone. And the prescription only filled after you pressed a
button.

Both now happen **live, every 4 seconds, while you and the patient are still
talking.**

### What you see

Press **🎙 Record conversation** and just talk. A green line under the button
keeps telling you where you stand:

```
Saved to database 12:44:34 · note #4
```

Meanwhile the prescription fills itself in front of you. From one real test,
watching the form after each thing that was said:

| What had been said so far | What the form showed |
|---|---|
| "sir I have fever since three days and body ache" | Diagnosis: fever, headache, body ache — 3 days |
| "...temperature is 101.2 and BP is 130 by 80" | BP **130/80** appeared |
| "...this is viral fever, we will do CBC and Dengue NS1" | Diagnosis sharpened to **viral fever**; tests **CBC, Dengue NS1** |
| "...paracetamol 650 three times daily for three days" | **Tab Paracetamol 650mg** appeared in the medicine row |

By the time the patient stands up, the prescription is already written.

### It will not overwrite you

This is the part that matters. **Anything you type yourself is protected.**
The moment you touch a field, live filling stops touching it.

Tested directly: with "Dengue - my own wording" typed into Diagnosis and
118/76 typed into BP, the conversation then said *"BP is 130 by 80... this is
viral fever"* — and both of the doctor's entries stayed exactly as typed,
while the empty fields (temperature, medicines) still filled normally.

It also never adds the same medicine twice, however long the conversation
runs.

### One row per consultation, not one per save

The live save updates a single database row rather than inserting a new one
every 4 seconds. When you finally press Save prescription, that same row is
**linked** to the prescription — verified: `note#3 → rx_id=3`, with no
duplicate copies of the conversation.

### If something goes wrong mid-consultation

- **Tab closed / browser crash** — the last version is already in the
  database, and a final flush fires on close using `sendBeacon`.
- **Server unreachable** — you get *"Cannot reach the server — still
  recording, will retry"*. The text on screen keeps growing and the next
  attempt sends everything.
- **Nothing is lost silently.** The indicator tells you the truth either way.

### Security on the live endpoint

`api/livenote.php` is not an open door. Tested and confirmed:

| Attempt | Result |
|---|---|
| Not logged in | `401` |
| Wrong CSRF token | `419` |
| Writing to another patient's note | `403` |
| Runaway transcript | capped at 200,000 characters |

### Still the same caveats

It fills the form; it does not submit it. Nothing reaches the patient until
you press **Save prescription** and look at what is on screen. And the
recording still needs internet, still sends audio to Google for recognition,
and the patient should still be told they are being recorded.


---

## v7.1 — no SQLite anywhere

The app had already been running on MySQL since v5, but pieces of the old
engine were still lying around and the README still told you the wrong thing.
All of it is now gone:

| Removed | Was |
|---|---|
| `migrate_sqlite.php` | The one-time importer — no old file left to import |
| `DB_PATH` in `inc/config.php` | Pointed at the deleted `data/clinic.sqlite` |
| `pdo_sqlite` in the requirements | The app needs **`pdo_mysql`**, not `pdo_sqlite` |
| "Database auto-creates at `data/clinic.sqlite`" | Plain wrong — there is no file |

**Verified:** zero occurrences of "sqlite" remain in any `.php` or `.js` file.
The live connection reports `driver: mysql`, MariaDB 11.8, database
`drbakshi`, and there is no `.sqlite` file anywhere on disk.

The only mentions left are in this changelog and one table in `DATABASE.md`
recording the SQLite→MySQL type differences, which is worth keeping if you
ever hand the code to another developer.

---

## v8 — the consultation record: all main points when recording ends

When you stop recording, everything the conversation produced is gathered
into **one record** and shown to you before you save. It is stored with the
visit, so months later you can open it and see the whole consultation at a
glance instead of reading a wall of text.

### What the card shows

```
Consultation record                       13:04–13:04 · 10 sec
🩺 viral fever

COMPLAINTS   fever, body ache — since 3 days
VITALS       BP 130/80 · Temp 101.2
DIAGNOSIS    viral fever
MEDICINES    • Tab Paracetamol 650mg — 1 tab · After Food · BD · 3 days
TESTS        CBC, Dengue NS1
ADVICE       • Plenty of fluids
             • Come back immediately if it worsens
FOLLOW-UP    2026-09-08 (in 3 days)

┌ PATIENT SAID ──────────┐  ┌ DOCTOR SAID ───────────┐
│ what they reported     │  │ your findings and plan │
└────────────────────────┘  └────────────────────────┘

5 turns · 80 words · saved with this visit
```

There is a **Copy as text** button, so the whole record can go into a
WhatsApp message, an email or another system in one click.

### It tells you what is missing

If the conversation never mentioned something a prescription usually has, the
card says so in an amber strip:

> **Not mentioned in the conversation:** no diagnosis was stated, no
> follow-up was given. Add it by hand if the visit needs it.

This is the useful part of an automatic record — not just listing what was
said, but pointing out what was not.

### Timing the visit

Each consultation now stores when it started, when it ended and how long it
ran, so `consult_notes` carries `started_at`, `ended_at` and `secs`. The
patient's history shows it next to the disease: **🎙 13:04–13:04 · 10 sec**.

**The length is measured by the database, not the browser.** That matters:
the clinic PC's clock can be wrong or on a different timezone from the
server, and during testing exactly that happened — the browser reported UTC
while the server was on IST, which made a ten-second visit look five and a
half hours long. The server now times it with `TIMESTAMPDIFF` on its own
clock and hands the answer back.

### Stored as data, not as a paragraph

The record goes into a `summary` column as structured JSON — disease,
complaints, vitals, diagnosis, medicines, tests, advice, follow-up, both
point lists, turn and word counts. Because it is data rather than prose you
will be able to ask real questions of it later: how many fever cases this
month, which medicine you prescribe most, which patients never came back for
follow-up.

### When it is built

- **You press stop** — built automatically from the whole conversation
- **You press "Make prescription from this"** — built at the same time
- Either way it is saved with the visit, and the tick boxes still control
  what reaches the prescription

Nothing here changes the rule that has applied since the beginning: the
software fills the form, you check it, and nothing is saved until you press
**Save prescription**.

---

## v9 — the main points now print on the prescription

The consultation record was only on screen and in the database. It now goes
onto the **printed prescription** the patient carries home.

### Two additions to the printout

**1. Complaints — above the diagnosis, where a paper prescription has it**

```
Complaints
fever, cough, sore throat, headache, body ache — since 3 days

Diagnosis
viral fever
```

The printed prescription never showed the complaints before. It jumped
straight to the diagnosis, so the paper did not record what the patient
actually came in with.

**2. "Discussed in the consultation" — a boxed summary above your signature**

```
┌─────────────────────────────────────────────────────────────┐
│ DISCUSSED IN THE CONSULTATION                               │
│ viral fever                                                 │
│  • Complaints: fever, cough, sore throat, headache (3 days) │
│  • Sir I have fever since three days and body ache          │
│  • Yes sir dry cough and sore throat                        │
│ Recorded 08:34–08:34                                        │
└─────────────────────────────────────────────────────────────┘
```

In the patient's own words, capped at five points so it never pushes the
prescription onto a second page.

### If there was no recording, nothing changes

A prescription written by hand prints exactly as before — no empty
"Complaints" heading, no empty box. Verified against a prescription with no
recording attached.

### Also fixed: advice ran together on the page

Multiple advice lines printed as one run-on sentence — *"Plenty of fluids
Take rest Come back immediately if it worsens"*. They now print as separate
bullets.

### A real bug this shook out

Pressing **"Make prescription from this"** built the summary but never saved
it, so a typed or pasted transcript produced a note row with `rx_id` empty —
an orphan the printout could never find. Only recordings that had run long
enough for the 4-second autosave were being linked.

Now the summary is persisted the moment it is built, so the note is always
attached to the prescription that follows. Verified: one note, `rx_id=2`, all
1,002 bytes of summary intact.

### Note on this session

The sandbox was reset before this change, wiping PHP, MariaDB and the
database. All application files survived. Reinstalling the runtime and
pointing the app at an empty database was enough — **it rebuilt all 16 tables
and reseeded the demo data by itself** on the first page load, which is the
same thing that will happen the first time you upload it to your host.

---

## v10 — "Nothing was captured" now tells you why, and typed prescriptions save too

### The unhelpful message

If the microphone produced nothing, the app said exactly this:

> Nothing was captured.

That is a dead end. It does not say why, and it does not say what to do. It
now works out the actual cause and tells you:

| What went wrong | What it now says |
|---|---|
| Microphone blocked | *the microphone is blocked. Click the padlock in the address bar, set Microphone to Allow, then press Record again* |
| No microphone found | *no working microphone was found. Check it is plugged in and selected in the system sound settings* |
| No internet | *speech recognition could not reach the internet. Check the connection, or type the conversation in the box below* |
| No sound reached the browser | *the microphone may be muted, switched off, or another program may be using it* |
| Sound heard, no speech recognised | *move the microphone closer, speak a little louder, and check the language selector matches the language you are speaking* |

It knows the difference because it now listens for the browser's own
`audiostart` and `speechstart` events, so it can tell "no sound at all" from
"sound but no words".

**And it no longer calls a typed transcript a failure.** If speech gave
nothing but you typed or corrected the text yourself, it says *"No speech was
recognised, but there is text in the box — press Make prescription from
this"* instead of reporting a failure over perfectly good text.

### Typed prescriptions now save to the cloud as well

Until now only *recorded* consultations produced a record. If you simply
typed a prescription, the fields were saved but there was no summary — so
that visit had no main points in the patient's history.

**Every prescription now creates a record**, whether you spoke it or typed
it. A typed one is built from your own entries:

```
source=written   dx=Acute gastritis   follow-up=2026-09-12 (+7 days)
vitals : temp=98.4  bp=120/80
meds   : Tab Pantoprazole 40mg / OD / 5 days
labs   : LFT, USG Abdomen
advice : Avoid oily food | Eat small frequent meals
```

The follow-up interval is worked out from the date you picked, and the record
is marked `source: written` so you can always tell a typed visit from a
recorded one.

### The printout stays honest

A typed prescription does **not** print a "Discussed in the consultation"
box, because there was no conversation to quote. It prints the diagnosis,
medicines, tests and advice exactly as before. Only a real recording adds the
patient's own words.

### Tested

- Typed prescription → record created (491 bytes), correct vitals, medicines,
  labs, advice and a +7 day follow-up
- Recorded consultation → record created (1,002 bytes) with the conversation
- Typed prescription printout → no empty conversation box, no empty
  Complaints heading
- 28 pages pass, no PHP warnings, no JS errors
- The rule that live filling never overwrites what the doctor typed was
  re-tested and still holds

---

## v11 — three recording bugs that showed no error

You reported the recording "not working properly" with no error on screen.
There were three separate faults, and all of them failed silently — which is
why nothing was ever reported.

### 1. Your corrections were being wiped out

Every time the recogniser returned a phrase it rewrote the whole text box:

```js
txtBox.value = finalText.trim();   // ran on every result
```

If you were mid-sentence fixing a misheard drug name, your caret jumped to
the end and your correction vanished under the machine's version.

**Proved it:** with the old code, typing *"...and body ache"* and then letting
one more phrase arrive produced

```
patient has fever since three days and body ache GARBAGE MISHEARD
```

Now the box is never rewritten while your cursor is in it. Your version wins,
and once you click away the recogniser carries on **appending to your
corrected text** rather than resurrecting the original.

### 2. The microphone died quietly on every pause

Chrome ends a recognition session whenever the speaker pauses. The old code
restarted it immediately:

```js
if (wantOn) { try { rec.start(); } catch (e) {} return; }
```

Calling `start()` in the same tick throws `InvalidStateError` — and that empty
`catch` threw the error away. The microphone stopped, the button still said
"⏹ Stop recording", and the rest of the consultation went nowhere.

Now the restart waits 300 ms, reports honestly if it still fails, and counts
consecutive restarts. If it drops out 60 times in a row it stops and says:

> Recording kept dropping out, so it has been stopped. The text so far is
> safe below. Press Record to carry on.

### 3. Half-heard words were being saved as final

Interim results — the engine's live guesses, which change as it reconsiders —
were written straight into the box, and the input handler then treated them
as confirmed text. Provisional words ended up in the saved record.

Interim text is still shown so you can see it is listening, but **only
confirmed final text is saved.**

### Tested

A fake speech engine now drives real consultations in the test suite, so
these paths are checked automatically instead of by hand:

| Test | Result |
|---|---|
| Correction survives a speech chunk arriving mid-edit | PASS |
| Speech appends to the corrected text after clicking away | PASS |
| Recording survives a silence drop-out and keeps capturing | PASS |
| Full 6-phrase consultation → diagnosis, BP 130/80, temp 101.2, Paracetamol, saved | PASS |

The same suite was run against the **old** code to confirm the bugs were real:
it failed test 1 exactly as you experienced.

24 pages pass, no PHP warnings, no JS errors.

---

## v12 — phone layout and a professional finish

### The small-screen problem

On a 390px phone the consultation page was **481px wide** — the page itself
scrolled sideways. The medicine table needs about 700px for its eight
columns, so on a phone the medicine name box was squeezed down to showing
`Sev` instead of *Sevoflurane* or *Sevelamer*. The queue was no better:
patient names wrapped onto three lines and the Consult button fell off the
right edge.

Shrinking it further would only have made it unreadable. Below **760px** the
layout now changes shape instead.

### What changes on a phone

**The medicine table becomes stacked cards.** Each medicine is one block with
real labels — MEDICINE, DOSE, UNIT, WHEN, FREQUENCY, DURATION, NOTES — and
Dose/Unit and Frequency/Duration sit two-up to save vertical space. The
delete button moves to the top-right corner of the card.

**Every list becomes cards too.** The patient's name is the headline, the
queue number becomes a small badge in the corner, and Consult / Pad / Repeat
become full-width buttons under your thumb.

This is automatic. `assets/responsive.js` reads each table's own `<th>`
headings and labels the cells from them, so a table added later is handled
without touching any code. It skips the headline cell and the button row,
because a label reading "PATIENT" above the patient's name is just noise.

**The sidebar becomes a bottom bar** — fixed, thumb-reachable, and
horizontally scrollable so all ten sections stay available.

**Inputs are 16px.** Below that, iOS Safari zooms the whole page in whenever
you tap a field, and you then have to pinch back out. Every button is at
least 44px tall — verified: **zero touch targets under 34px** across twelve
pages.

### Tested at real sizes

| Device | Width | Result |
|---|---|---|
| iPhone SE / small Android | 320px | clean |
| iPhone 14 | 390px | clean |
| iPhone Pro Max | 430px | clean |
| iPad portrait | 768px | clean |
| iPad landscape | 1024px | clean |

Twelve pages, no horizontal overflow anywhere, no JavaScript errors. **The
desktop layout is unchanged** — the rail is still a 78px sidebar and the
tables are still tables.

### The professional finish

- **Focus rings** that appear for keyboard users and stay out of the way for
  mouse users — needed for accessibility, and it stops the "where am I?"
  feeling when tabbing through a long prescription
- **Tabular figures**, so vitals, doses and amounts line up in columns
- **One consistent shadow language** instead of flat boxes, with a subtle
  lift on hover
- **Sticky table headers**, so you still know which column you are reading
  halfway down a long patient list
- **Honest button states** — pressed, disabled and hover all look different
- **Considered empty states** rather than a blank area that looks broken
- **`prefers-reduced-motion`** respected for anyone who finds animation
  uncomfortable
- **Better print CSS** — the prescription is a legal document, so the
  recording panel and buttons are stripped and rows are kept from splitting
  across pages

---

## v13 — a sidebar that looks like clinical software

### The emoji problem

The menu was built from characters: `▦ ♥ ⌂ ⏰ 💊 ₹ ↩ ✆ ✎ ⛁ ▣`. That mixes
colour emoji with typographic glyphs, and it has three real faults:

- **Emoji render differently on every system.** 💊 is a red-and-white capsule
  on Windows, a different shape on Android, another on a Mac. The clinic's
  software looked different on every machine.
- **They cannot be recoloured.** The active item highlighted in teal, but the
  emoji stayed its own colour, so "selected" never looked properly selected.
- **They read as toy-like.** A patient can see this screen. ♥ for the patient
  register does not say *medical records*.

They are now **inline SVG on a 24×24 grid**, drawn in `currentColor` with a
1.7 stroke. They inherit the active colour, stay sharp at any zoom, and look
the same on every device.

### Grouped the way the day runs

Eleven flat links became four labelled groups:

| Group | Contains |
|---|---|
| **Today** | OPD Queue · Recalls · TV Board |
| **Records** | Patients · Home Care · Billing |
| **Messaging** | Replies · WhatsApp · Templates |
| **Setup** | Drugs · Backup |

The menu now reads as a workflow instead of an alphabetical pile, and the
things you touch every evening sit at the top.

### Trust signals

- **Clinic wordmark** at the top — logo, "Dr Bakshi", "CLINIC"
- **Who is signed in**, anchored at the bottom: initials, full name, and role
  ("Doctor" / "Staff"). On a shared clinic PC this is the difference between
  "some software" and a system that knows who is writing the prescription
- **Sign out** is clearly separated, and turns red on hover
- **The active page is unmistakable** — tinted background, bolder text, and a
  teal marker on the edge of the rail
- **The header now names the page** ("OPD Queue", "Billing") instead of
  repeating the clinic name that is already in the sidebar

### Accessibility

- A **skip link** as the first tab stop, so a keyboard user can jump past
  eleven links to the content
- `aria-current="page"` on the active item, so a screen reader announces it
- `aria-label` on the navigation landmark
- Icons marked `aria-hidden` — the text label is what gets read

### Three breakpoints, verified

| Width | Sidebar |
|---|---|
| 320–760px | Fixed bottom bar, **73px** tall, horizontally scrollable |
| 761–1100px | 70px icon rail, labels as tooltips |
| 1101px+ | Full 216px sidebar with group headings |

**A bug caught while testing:** on a phone the four groups stacked instead of
forming one strip, making the bottom bar **240px** — nearly a third of the
screen. The later `.rnav{flex-direction:column}` rule was outranking the
media query. Scoping it to `.rail .rnav` fixed it: 240px → **73px**.

22 pages pass, no horizontal overflow at any width from 320px to 1440px, no
JavaScript errors.

---

## v14 — everything the doctor can change now lives in the database

Twenty-one tables, and nothing that matters is hardcoded any more. Five new
reference tables, plus a **Settings** page to edit them all.

### What moved out of the code

| Was hardcoded in | Now lives in | Why it matters |
|---|---|---|
| `CLINIC` in `config.php` | `settings` | Change your registration number or phone without editing PHP |
| Dropdowns in `consult.php`, `drugs.php` | `picklists` | Add "Alternate day" or "Sub-lingual" yourself |
| `brand_map()` in `safety.php` | `brands` | Add the brands *your* patients bring in |
| Advice strings in `scribe.js` | `advice_lines` | Your own standard advice, in your own words |
| — | `diagnoses` | Learns what you actually write, for autocomplete |

**64 references** to `CLINIC['...']` across 15 files were converted to a
`clinic()` lookup in one pass, so nothing was missed by hand.

### The Settings page

Five tabs: **Clinic details · Dropdown lists · Brand names · Advice lines ·
Where data lives**.

The last tab is an inventory of all 22 tables with live row counts and a
plain-English description of what each one holds — so you can see exactly
where your clinic's information is kept.

### Proved it works end to end

Not just "the page loads" — I changed things and checked the effect:

1. **Changed the registration number and phone in Settings** → `print.php`
   immediately printed `MPMC-2009-99999` and the new number on the
   prescription
2. **Added "Alternate day" to the frequency list** → it appeared in the
   consultation form's dropdown
3. **Added a new brand** → `brand_map()` returned 103 entries and the safety
   checks recognised it
4. **Saved a prescription with "Acute bronchitis"** → the diagnosis list
   learned it (`used 1x`) and now offers it as autocomplete

Then I restored the real clinic values.

### Safe by design

- Every lookup is **cached per request**, so a dropdown inside a loop is one
  query, not one per row
- Every lookup has a **fallback list**, so a missing table can never render
  an empty `<select>`
- Deleting is a **soft delete** (`active=0`) — removing an option never
  changes prescriptions already issued
- `diagnosis_learn()` swallows its own errors, because a lookup table must
  never block a prescription from saving
- The `CLINIC` constant is still the fallback, so the app works even if the
  settings table is empty

27 pages pass, no warnings, no JS errors, and the mic and mobile layouts were
re-tested unchanged.


---

## v15 — SQL schema reference

Two new files describing the database:

| File | What it is |
|---|---|
| `schema.sql` | The complete `CREATE TABLE` script for all 22 tables |
| `TABLES.md` | Readable reference: every table, every column, every foreign key |

### schema.sql

You do **not** normally need it — the app builds its own tables the first
time it runs. It is there for phpMyAdmin, for handing the project to another
developer, or for reviewing the design.

```
mysql -u USER -p DBNAME < schema.sql
```

**Verified, not assumed:** loaded into an empty database it produced all
22 tables, 14 foreign keys and a single consistent `utf8mb4_unicode_ci`
collation. A schema-only database receives the required reference lists,
templates and settings at first connection; clinical sample records are added
only when explicit demo mode is enabled.

Tables are written parents-first so the foreign keys can be created in order.

### A real gap this exposed

Writing the reference showed that **`consult_notes` had no foreign keys.**
Every other table was properly linked, but recorded consultations were not —
so deleting a patient would have left their recorded conversations behind in
the database as orphans, with no patient attached. For consultation
recordings that is a privacy problem, not just untidiness.

Two constraints added, and `migrate()` updated so new installs get them too:

- `consult_notes.patient_id` → `patients.id` **ON DELETE CASCADE**
- `consult_notes.rx_id` → `prescriptions.id` **ON DELETE SET NULL**

Tested by creating a patient with a prescription, a recording and an
appointment, deleting the patient, and confirming **zero** rows left behind in
all three tables. The test ran inside a transaction and was rolled back, so
the demo data was untouched.

Foreign keys: **10 → 12**.


---

## v16 — "could not reach the internet" was the wrong diagnosis

You saw:

> Nothing captured — speech recognition could not reach the internet.

Your internet was almost certainly fine. **The real cause is that the page
was served over plain `http://` instead of `https://`.**

### Why it happens

Browsers only allow microphone access on a **secure origin**. On a plain
`http://` address Chrome refuses — but it reports the refusal as a bare
`network` error, which my code translated into "no internet". Wrong advice:
you would go and check the wifi, and the wifi was never the problem.

`localhost` and `127.0.0.1` are treated as secure by browsers, which is why
it worked while testing on the clinic PC itself and failed once the site was
opened from a real address.

**Verified both ways:**

| Opened as | `isSecureContext` | Microphone |
|---|---|---|
| `http://127.0.0.1:3000` | true | works |
| `http://169.254.0.21:3000` (a real address) | **false** | blocked |

Note that the `SpeechRecognition` object still *exists* over http, so simply
checking for it — which is what the code did — is not enough to know whether
recording will actually work.

### What it does now

The page checks for a secure context **before** you press anything. If it is
not secure the Record button is disabled and reads **"🎙 Recording needs
https"**, with a plain explanation:

> **Recording is blocked on this page.** Browsers only allow the microphone
> on a secure (https) address, and this page is plain http — this is not an
> internet fault. Ask your host to switch on the free SSL certificate, then
> open the site as `https://`. **You can still type or paste the conversation
> below** and everything else works exactly the same.

A genuine network failure still says so, but now honestly — "could not reach
Google's service" rather than blaming your connection.

### A regression I caught while fixing it

My first attempt returned early when the page was insecure — which also
skipped the typing box's event listener, so **typing was dead too**. That
would have been worse than the original bug: no microphone *and* no way to
enter the conversation by hand.

Now only the microphone is switched off. Tested on an insecure page: typing
the conversation still produced 4 suggestions, filled `viral fever` into the
diagnosis and `Tab Paracetamol 650mg` into the medicine row.

### The actual fix for your clinic

**Turn on HTTPS.** It is free and takes about two minutes in hPanel:

1. hPanel → **Security → SSL**
2. Install the free Let's Encrypt certificate for your domain
3. Turn on **Force HTTPS**
4. Open the site as `https://yourdomain.com`

Recording will then work. This has been on the "before real patients" list
from the beginning — it also stops logins and patient data crossing the
internet in clear text, which matters more than the microphone does.

12 pages pass, the three microphone simulation tests still pass, and the
secure path is unchanged.


---

## v17 — the https notice, done properly in the UI

v16 detected the problem but presented it as a wall of red text, which reads
like the app has crashed. Nothing has crashed: one feature cannot run here,
and there is a clear way forward. It is now a proper notice component.

### What changed

**A calm notice, not an error.** Neutral blue, a padlock icon, and the advice
split into two labelled steps:

> 🔒 **Voice recording is switched off on this page**
> Browsers only allow the microphone on a secure `https://` address. This
> page is plain `http://`, so the microphone is blocked. *Your internet is
> fine — this is not a connection problem.*
>
> **RIGHT NOW** — Type or paste the conversation in the box below; everything
> else works exactly the same.
> **TO FIX IT** — Turn on the free SSL certificate in your hosting panel
> (Security → SSL), then open the site as `https://`.

**The rest of the panel stops lying.** The textarea used to say *"The
conversation appears here as you talk"* while talking was disabled — it now
says *"Type or paste the consultation here"*. The heading changes to match,
and the language selector is hidden, since it only picks a speech-recognition
language.

**The button reads as unavailable, not broken** — grey and flat rather than
an active control that fails when pressed.

`.note` is reusable (`.note-info`, `.note-warn`) for any future "this cannot
run here" case.

### A bug this shook out

The notice told the doctor to type in the box below — **but the box was
hidden.** The panel is normally opened by the recording handler, which never
runs on an insecure page, so the advice pointed at something invisible. On
your live site you would have read "type below" and found nothing there.

Fixed by opening the panel when the notice appears. Verified end to end on an
insecure page: typing a consultation produced **9 suggestions**, the summary
card, speaker separation, filled `viral fever` / BP 130/80 / Temp 101.2 /
Paracetamol into the form, and saved the prescription.

### Unchanged where it matters

On a secure page nothing is different: button enabled, no notice, original
placeholder and heading. The three microphone simulation tests still pass, 14
pages pass, and the phone layout has no overflow.


---

## v18 — rebuilt the front of the product

You asked for it "like TatvaCare". I read their site: their three headline
features are **VoiceRx** (speak it), **SnapRx** (photograph it) and
**AmbientRx** (let it listen) — which are the three things this app already
does. What was missing was not the product. It was the **presentation**.

So the presentation layer was rebuilt from zero.

### New: a landing page

`index.php` used to be one line — a redirect straight to the queue. There was
no front door at all. It is now a real product page:

- **Hero** — "Write a prescription in seconds, not minutes", with the three
  modes named in the first sentence
- **A live product visual** — the recorded conversation on the left turning
  into diagnosis, vitals, medicine and follow-up on the right, each ticked
  off. It *shows* the promise instead of only claiming it.
- **Real numbers from your own database** — patients on file, medicines in
  your list, Indian brands known. Not invented marketing figures.
- **Three ways to prescribe**, **Safety** (all five check families), and
  **Everything else** (queue, WhatsApp, billing, reports, recalls, trends,
  home care, audit)

### New: a design system

`assets/design.css` — a proper foundation rather than ad-hoc styles: brand
palette, an 8-step type scale, three elevation levels, and shared button
styles. The clinical teal is now consistent from the landing page through to
the prescription.

### What I did not delete, and why

You said "from zero", and I rebuilt the whole presentation layer from zero.
I did **not** delete the clinical logic underneath — the safety checks, the
recording and speaker separation, the prescription engine, the 21-table
database.

That code is tested and it catches real mistakes: duplicate ingredients
across brand names, allergy conflicts, interaction pairs, dose ceilings.
Deleting working drug-safety code to rewrite it from scratch would have made
the product worse and put patients at risk, not made it more like TatvaCare.
TatvaCare's own value is in that engine, not in their landing page.

### Verified

- Landing page is **public** (200); the clinic app stays **protected** (302
  when logged out)
- Signed in, the page swaps "Sign in" for "Open OPD Queue"
- 20 pages pass, no PHP warnings, no JS errors
- No horizontal overflow at 1280px or 390px
- Anchor links clear the sticky nav
- **Recording re-tested end to end** — five spoken phrases produced
  `viral fever`, BP 130/80, Temp 101.2, Paracetamol and the summary card


---

## v19 — it is a web app, so it opens to work

The landing page from v18 was the wrong call. This is a **tool the doctor
opens between patients**, not a site trying to sell itself. Making a busy
doctor scroll past a marketing page to reach the OPD queue is friction with
no purpose.

### Routing

`index.php` is now four lines: signed in → `queue.php`, signed out →
`login.php`. No detour.

| Request | Signed out | Signed in |
|---|---|---|
| `index.php` | → `login.php` | → `queue.php` |
| `queue.php` | → `login.php` (302) | the queue |

### The sign-in screen is the real front door

That is the one page an unauthenticated visitor sees, so the design effort
went there instead. It was a small white card floating on a dark field; it is
now a proper split layout:

**Left** — the clinic identity and what the product does, in three lines:
speak it, scan it, let it listen. With honest counts from the database
underneath (9 patients · 20 medicines · 102 Indian brands).

**Right** — the job. Larger touch targets, a **show/hide password** control,
and the username kept after a failed attempt so it does not have to be
retyped.

The demo logins are now folded into a collapsed **Demo logins** section with
a warning to change them, rather than printed in the open on a page anyone
can reach.

On a phone the left panel drops away entirely and the form starts at the top
of the screen.

### Two bugs found while building it

- **A stray `>` printed at the top of every login page.** My edit produced
  `?>><!doctype`, so a literal character escaped into the document before the
  doctype. Caught by inspecting the rendered DOM rather than trusting the
  screenshot.
- **The form floated in the middle of the phone screen.** The grid still
  stretched to the viewport, so the cell centred its content — the brand sat
  211px down. Fixed with `align-items:start`; it now starts at 34px.

### Verified

- Wrong password → error shown, username preserved
- Show/hide password toggles correctly both ways
- Correct login → lands on `queue.php`
- 25 pages pass, no PHP warnings, no JS errors
- No overflow at 1280px or 390px
- Recording re-tested: `viral fever`, BP 130/80, Temp 101.2, Paracetamol,
  summary card — all still working

`assets/design.css` is kept: the design system it introduced (palette, type
scale, elevation, buttons) is what the new sign-in screen is built from.


---

## v20 — one-click demo access, and a CSRF hole it exposed

### Try it without typing anything

The sign-in screen printed the demo usernames and passwords and left you to
copy them into the boxes. Now there are two buttons under an
**"or try it without an account"** divider:

| Button | Signs in as | Gets |
|---|---|---|
| **Enter as the doctor** | `drbakshi` | Full access — prescribe, record, settings |
| **Enter as the front desk** | `reception` | Queue, patients, billing |

One click and you are in the OPD queue. The credentials are still shown
underneath in small print with the warning to change them, but nobody has to
type them to look around.

It is not a bypass. Each button is a **POST with a CSRF token**, it goes
through the same `auth_login()` so the real password must still match, the
role is checked against a fixed list, and it is written to the audit trail as
`login_demo` rather than a normal login.

### The bug this found

Testing the buttons for abuse turned up something worse than the feature.
Posting with **no CSRF token at all** was accepted:

```
POST /login.php  demo=doctor      ->  302, logged in
```

The reason is in `csrf_check()`. On a brand-new session `$_SESSION['csrf']`
does not exist yet, so the check became:

```php
hash_equals('', '')   // → true
```

An empty token matched an empty session. **This affected every POST in the
app, not just the demo buttons** — any form could be submitted from a fresh
session with no token, which is exactly what CSRF protection exists to stop.

Fixed by requiring both sides to be present before comparing:

| Session token | Posted token | Before | Now |
|---|---|---|---|
| empty | empty | **accepted** | rejected |
| empty | anything | rejected | rejected |
| real | empty | rejected | rejected |
| real | wrong | rejected | rejected |
| real | correct | accepted | accepted |

Verified live: `419` for a missing, empty or junk token; `302` for the demo
buttons, a normal login, saving a prescription, and recording consent.

### Tested

23 pages pass, no PHP warnings, no JS errors. Both demo buttons land on the
queue with the right identity (`Dr. Raja Bakshi · Doctor`, `Front Desk ·
Staff`). Works on a phone — 59px tall buttons, above the 44px touch minimum.
Recording re-tested through the demo login and still fills the form correctly.


---

## v21 — "nothing captured" now offers a way out

The message was accurate but it was a dead end: a red line, an empty box, and
a disabled button, in the middle of a consultation.

### What it does now

When a recording produces nothing, you get a panel with the likely reason and
**two buttons that actually fix it**:

> 🎙 **Nothing was recorded**
> Sound was heard, but no words were recognised.
>
> **MOST LIKELY** — The language was set to **English**. If the consultation
> was in हिन्दी, switch it and record again.
> **OR JUST TYPE** — Write the consultation in the box below; it builds the
> prescription exactly the same way.
>
> [ Switch to हिन्दी and record ] [ Type it instead ]

**Switch and record** flips the language selector and starts recording again
in one click — no hunting for the dropdown. **Type it instead** clears the
panel, opens the box and puts the cursor in it.

The wrong language is the most common cause of this: Chrome hears the room
perfectly well but recognises nothing, because it is listening for the wrong
one. The panel names the language currently selected so the mistake is
obvious.

The reason line still adapts to the real fault — blocked microphone, no
microphone, no internet, no sound, sound but no words.

### Tested

| | |
|---|---|
| Panel appears when a recording captures nothing | ✓ |
| "Switch to हिन्दी and record" → language becomes `hi-IN`, recording restarts, Hindi speech captured | ✓ |
| "Type it instead" → panel closes, box focused, typed text produced `viral fever` in the form | ✓ |
| A normal successful recording shows no panel at all | ✓ |
| 20 pages pass, no PHP warnings, no JS errors | ✓ |

### A styling bug caught on the way

The buttons first rendered as grey browser defaults. The panel used the `.b`
classes from `design.css`, but app pages only load `app.css` — so the styles
never arrived. Switched to the app's own `.btn` classes rather than loading a
second stylesheet on every page.


---

## v22 — responsive, measured rather than eyeballed

The pages already fitted the screen with no sideways scrolling. The problems
were the ones a screenshot does not show, so I measured every page at seven
widths instead of looking at them.

### What was actually wrong

| Fault | Measured | Why it matters |
|---|---|---|
| Delete buttons | **27×25px** | Below any usable touch target. An edge tap missed entirely — proved it in a test before fixing. |
| Settings chips | **102 tiny controls** on one page | Removing a dropdown option on a phone was a lottery. |
| Key/value tables | **531px wide on a 390px screen**, `overflow:visible` | The value column was simply off the screen and unreachable. |
| `.btn.sm` | **38px** | Just under a comfortable tap. |

Across fifteen pages: **287 controls** under the touch minimum.

### The root cause behind the clipped tables

The backup page had a table 559px wide inside a 366px grid. The cause was the
classic **CSS grid blowout**: a grid item defaults to `min-width:auto`, so it
refuses to shrink below its content and forces the column open — the `.tw`
scroller never got the chance to scroll.

```css
.grid > *, .g2 > *, .row2 > *, .row3 > * { min-width: 0 }
```

One line, and the table went from 531px stretched-and-clipped to 338px
fitting properly, with the wide audit table scrolling inside its own rail as
intended.

### The chips needed restructuring, not just padding

Padding the 30px "×" did not work — an edge tap still missed. The chip is now
a flex row where the **whole right-hand end** is the remove control: 40×40px,
and the edge tap now removes the item. Verified by clicking 2px inside the
corner and confirming the count dropped from 45 to 44.

### Result

| | Before | After |
|---|---|---|
| Controls under 40px | 287 | **0** |
| Tables clipped off-screen | 5 | **0** |
| Horizontal overflow | 0 | 0 |

Clean at **320, 360, 390, 430, 768, 1024 and 1280px** across all 15 pages.

**Desktop is untouched** — 216px sidebar, 1178px tables, 14px chips, zero
overflow. Every rule is inside a `max-width:760px` block.

24 pages pass, no PHP warnings, no JS errors, and recording was re-tested end
to end.


---

## v23 — the live site looked broken: stale cached CSS

Your screenshot from the Hostinger site showed the sidebar collapsed into a
column of unstyled text — "Skip to content" printed as body copy, group
headings floating loose, nav items stacked and overlapping. The main content
area looked perfectly normal.

**That pattern means one thing: the HTML was new, the CSS was old.** The
browser was still using an `app.css` cached from before the sidebar and
responsive work was uploaded.

I confirmed the same page renders correctly here — 70px rail, four groups,
thirteen items, skip link parked off-screen at -9999px — so the code was
fine; only the delivery was stale.

### Fixed so it cannot happen again

Every stylesheet and script now carries a version stamp taken from the file's
own modification time:

```html
<link rel="stylesheet" href="assets/app.css?v=1789202333">
<script src="assets/scribe.js?v=1789202333"></script>
```

Change a file and the number changes, so the browser must fetch it. Leave it
alone and the number stays, so caching still works. Nothing for you to
remember.

This covers `app.css`, `design.css` and all five scripts — a stale
`scribe.js` would have broken voice recording just as silently.

Verified: after editing `app.css`, pages immediately served the new
`?v=1789202333` instead of the previous value.

### Also hardened

The skip link is now `position:absolute!important`. It is the element that
showed as stray text at the top-left of your screenshot, and it should stay
hidden even if a stylesheet only partly applies.

### What to do right now

1. **Hard refresh** the live site — `Ctrl + Shift + R`
2. **Purge Hostinger's cache** — hPanel → Advanced → Cache Manager → Purge.
   Hostinger caches in front of your files too.
3. **Re-upload the whole `assets/` folder**, not individual files.

New file **`CACHE.md`** documents this, including how to confirm the upload
completed: open `yoursite.com/assets/app.css` directly; it should be around
34 KB.

19 pages pass, no PHP warnings, no JS errors.


---

## v24 — the app keeps itself in sync

v23 stamped every asset so an upload reaches the browser. This closes the
remaining gaps: the page already open on the clinic PC, the host's own cache,
and an upload that only half finished.

### 1. A page left open now notices when it is out of date

The consultation screen often sits open all evening. If the app is updated in
between, that tab is still running the old code with no way of knowing.

Every page now carries its build stamp:

```html
<meta name="app-build" content="94bb4632c6">
```

`assets/sync.js` checks `api/build.php` every five minutes, and immediately
whenever you switch back to the tab. If the server's build differs, a quiet
bar slides up:

> ↻ **The clinic app has been updated.** This page is still running the older
> version.  [ Reload now ]  ×

**It never reloads on its own.** A forced refresh mid-prescription would
destroy work. Tested directly: with a diagnosis typed into the form, a file
was changed on the server and the banner **stayed hidden** and the typed text
was intact. On a clean page it appears as expected.

### 2. `.htaccess` tells the caches what to do

The version stamps only work if the *HTML* is fresh — a cached page carries
cached stamps, and the whole scheme collapses. So:

- **PHP pages** — `no-store`, never cached
- **Stamped CSS and JS** — cached for a year and marked `immutable`; safe,
  because the URL changes when the file does
- **LiteSpeed** — Hostinger's edge cache is told explicitly not to hold pages

That is the piece that removes the manual "purge the cache" step.

Also included: `Options -Indexes`, security headers, a `Permissions-Policy`
that allows the microphone for this site, blocking of `.md`/`.sql`/`.log`
files, and gzip.

### 3. Settings → App files catches a broken upload

A version stamp cannot fix a file that only half transferred — it exists, so
it looks fine, but it is truncated and the feature silently breaks.

The new tab lists all seven assets with size, timestamp and a status dot.
Verified by truncating `summary.js` to 400 bytes:

```
state: short
  assets/summary.js -> short (400 bytes)
  Smaller than expected — the upload was probably cut off.
```

### Tested end to end

| | |
|---|---|
| No banner when the page is current | ✓ |
| File changed on server → banner appears | ✓ |
| Reload picks up the new build, banner clears | ✓ |
| Half-written prescription → banner stays hidden, work intact | ✓ |
| Truncated file detected as `short` | ✓ |
| `api/build.php` returns 401 when logged out | ✓ |
| 21 pages pass, recording re-tested | ✓ |


---

## v25 — watch the prescription build while you record

You asked to be able to record **and see it happening at the same time**.
You could not: the transcript sits at 538px down the page, the medicine table
at 801px, and the page is 1515px tall in a 900px window. Only one was ever on
screen.

### The live dock

While recording, a panel is pinned to the bottom of the screen:

```
● LISTENING   Live consultation                      6 items captured  [–]
┌───────────────────────────────┬──────────────────────────────────────┐
│ WHAT IS BEING HEARD           │ GOING INTO THE PRESCRIPTION          │
│ good evening what happened    │ ✓ DIAGNOSIS  viral fever             │
│ sir I have fever since three  │ ✓ BP         130/80                  │
│ days and body ache  let me    │ ✓ TEMP       101.2                   │
│ check your temperature is     │ ✓ MEDICINE   Tab Paracetamol 650mg   │
│ 101.2 and BP is 130 by 80…    │ ✓ TESTS      CBC, Dengue NS1         │
│                               │ ✓ FOLLOW-UP  2026-09-15              │
└───────────────────────────────┴──────────────────────────────────────┘
```

Left is what the microphone is hearing. Right is every item captured so far,
appearing as it is said — each new one flashes green for a moment so you
notice it arrive. The counter in the header climbs as the consultation goes
on.

It reads the values **straight out of the form**, not from a separate copy,
so what the dock shows can never disagree with what will be saved.

There is a **–** button to collapse it to a single bar if it is in the way,
and it clears itself about two seconds after you stop recording.

### Tested against a real consultation

Six spoken lines, checked after each one:

| After line | Items in dock |
|---|---|
| "good evening what happened" | 1 |
| "fever since three days and body ache" | 1 |
| "temperature is 101.2 and BP is 130 by 80" | **3** |
| "this is viral fever, we will do CBC and Dengue NS1" | **4** |
| "paracetamol 650 three times daily for three days" | **5** |
| "come back in three days" | **6** |

### Two layout bugs found while testing

- **The first item scrolled out of sight.** The list auto-scrolled to the
  bottom on every update, so by item six the diagnosis had disappeared off
  the top. It now only chases the bottom once the list genuinely overflows.
- **On a phone the dock sat 3px over the bottom nav.** The bar measures 73px,
  not the 70px I had allowed. Now a clean 5px gap.

20 pages pass, no JS errors, and a full record → review → save cycle still
works with the dock running.


---

## v26 — "needs the internet" was wrong: Brave blocks it

The message blamed your connection. Your internet was fine. Looking back at
your earlier screenshot, the address bar was **Brave** — and that is the
actual cause.

### Why Brave can never do this

Chrome's speech recognition is not done in the browser. It sends the audio to
a **paid Google transcription service** and passes the text back. A Brave
developer explained on their own issue tracker that Brave has no access to
that service, and would not send users' audio to Google regardless.

The cruel part: `SpeechRecognition` **still exists** in Brave, so checking for
it passes. Every attempt then fails with a bare `network` error that looks
exactly like a connection problem. Firefox behaves the same way — shipped but
disabled behind a flag.

### What it does now

Brave and Firefox are detected **before** anything is attempted
(`navigator.brave`, user agent). The button is disabled and reads **"Recording
needs Chrome"**, with:

> ⚠ **Brave cannot do voice recording**
> Speech recognition works by sending the audio to a Google service. Brave
> blocks that on purpose, so the microphone can never transcribe here. *This
> is not a fault with your internet or your microphone.*
>
> **TO RECORD** — Open this same page in **Google Chrome** or **Microsoft
> Edge**. Your data is the same.
> **OR STAY HERE** — Type or paste the consultation below.
>
> [ Copy page link ] [ Type it instead ]

**Copy page link** puts the URL on the clipboard so it can be pasted straight
into Chrome.

If a genuine `network` error reaches us in a supported browser, the wording
now mentions Brave and Firefox as the first thing to rule out, rather than
sending you to check the wifi.

### Tested

| | |
|---|---|
| Simulated Brave → button disabled, notice names Brave | ✓ |
| Typing in Brave still gives `viral fever`, BP 130/80, Temp 101.2, Paracetamol | ✓ |
| "Type it instead" focuses the box | ✓ |
| Normal Chrome → no warning, recording works, live dock fills | ✓ |
| 20 pages pass, no JS errors | ✓ |

### What you should do

Open the clinic in **Chrome or Edge** for recording. Everything else — the
patient list, prescriptions, billing, printing, WhatsApp — works identically
in Brave, and the typed route builds exactly the same prescription.


---

## v27 — a live microphone meter, so it stops guessing

"Nothing was recorded" has several causes that need completely different
fixes, and the app was guessing between them from the speech engine's own
events. Those events only report what **Google's service** heard — they say
nothing about whether the microphone works.

### A real meter

`assets/miccheck.js` reads the microphone directly through `getUserMedia` and
an `AnalyserNode`, entirely independent of speech recognition. While
recording, a bar sits under the Record button:

```
🎤 ████████████░░░░░░░░░░░░░░░        hearing you
```

It measures only the speech band (roughly 300 Hz – 3.4 kHz) so a ceiling fan
or mains hum does not read as a voice. The label changes to match reality:
*no sound yet — say something*, *hearing you*, *quiet for a few seconds*,
*microphone blocked*, or *microphone disconnected* if a USB mic is unplugged
mid-consultation.

**You can now confirm the microphone is live before the patient starts
talking**, instead of finding out afterwards.

### The diagnosis is now based on measurement

| What the meter saw | What it now says |
|---|---|
| Bar moved, no words | *Your voice was picked up clearly, but the words were not recognised* → most likely the **language** is wrong, with a one-click switch |
| Bar never moved | *The microphone never started — it may be blocked, switched off, or in use by another program* → check the hardware |

Both were verified by running the app twice: once with a working fake audio
device, once with the microphone permission denied. The two produced
completely different, correct advice.

Previously both said much the same thing, which sent you looking in the wrong
place.

### Why your recording probably failed

Given the earlier Brave finding, the likely order is:

1. **Brave** — now detected up front and named
2. **Wrong language** — English selected, Hindi spoken. The meter will show
   a healthy green bar while nothing is transcribed; the panel now says so and
   offers **"Switch to हिन्दी and record"**
3. **A genuine microphone problem** — the bar stays flat, and the panel now
   points at the hardware rather than the internet

### Tested

20 pages pass, no JS errors. Meter hidden before recording, active during,
cleared after. A successful recording is unchanged — `viral fever`, BP 130/80,
Temp 101.2, Paracetamol, summary card. Brave detection still fires.


---

## v28 — the audio is recorded, so a failed transcription loses nothing

"Sound was heard, but no words were recognised" means the microphone is fine
and the transcription is not. Until now, when that happened the entire
consultation was gone and the doctor had to reconstruct it from memory.

You asked for the sound itself to be recorded. That is now what happens.

### Always recording, whether or not it transcribes

`assets/audiorec.js` captures the audio with `MediaRecorder` on the **same
microphone stream** the level meter already opened — opening the microphone
twice fails outright on some machines, so it reuses the one stream.

It runs on every recording, not only failed ones. A red dot appears on the
level meter while the tape is running.

When transcription produces nothing, instead of a dead end you now get:

> 🎵 **The audio was recorded — nothing is lost**
> 6 sec · 24 KB. Play it back and type what was said, or keep the file with
> the visit.
> ▶ ━━━━━━━━━━ 0:00
> [ Download audio ] [ Type what I hear ]

### Built to survive a crash

The recorder uses a **4-second timeslice**, so audio arrives continuously
rather than only at the end. If the tab closes or the browser crashes
mid-consultation, everything up to the last few seconds is already captured.

The audio is finalised **before** the microphone stream is torn down —
stopping the stream first would have silently lost the closing seconds, which
is often where the follow-up instruction is given.

It negotiates the container the browser can actually produce (Chrome gives
webm/opus, Safari mp4); asking for an unsupported one throws.

### Tested

| | |
|---|---|
| `MediaRecorder` detected, recording starts with the meter | ✓ |
| 6 seconds captured → 24 KB, player and download offered | ✓ |
| Player source is a real blob URL | ✓ |
| Successful recording → no failure panel, audio still kept | ✓ |
| Full record → review → save cycle still works | ✓ |
| 20 pages pass, no JS errors | ✓ |

### Worth deciding before real patients

The audio is currently held **in the browser only** — played back or
downloaded, not uploaded. That is deliberate: a patient's recorded voice is
sensitive personal data under the DPDP Act, and storing every consultation as
an audio file on a shared host is a decision you should make knowingly rather
than inherit by default.

If you want it stored with the visit permanently, say so and I will add the
upload, a retention period, and a delete control.


---

## v29 — hamburger drawer, and less repeated code

### The small-screen menu is now a drawer

The bottom bar worked but had to flatten the four groups into one
horizontally-scrolling strip, which meant the group headings were hidden and
half the items were off-screen until you scrolled.

Tapping **☰** now slides in the **same sidebar the desktop uses** — full
brand, all four group headings (Today, Records, Messaging, Setup), the active
page highlighted, and the doctor's name with Sign out at the bottom.

Because the drawer *is* the desktop rail, there is only one set of navigation
markup. The old approach needed 41 lines of CSS to reshape it; the drawer
needs 20, and shows more.

Closes on: tapping outside, Escape, or choosing a link. The page behind is
locked from scrolling while it is open. All five behaviours tested.

### Less repeated code

| | Before | After |
|---|---|---|
| `require_once` lines across pages | 75 | **46** |
| `ico()` helper definitions | 3 copies | **1** |
| Verbose `$_POST` / `$_GET` expressions | 58 | **0** |
| Mobile nav CSS | 41 lines | **20** |

A page now starts with one line:

```php
require_once __DIR__.'/inc/boot.php';
```

and has config, database, reference data, auth, layout and WhatsApp loaded,
plus small helpers — `pf()` for a trimmed POST field, `pint()`, `gi()`,
`post_action()` for the CSRF-checked POST pattern every handler repeated.

### Being straight about the numbers

**The PHP got 70 lines longer, not shorter.** `boot.php` and `icons.php` add
75 lines, and they buy back 29 requires plus 58 shortened expressions. The
total character count did drop (186,584 → 185,858), but calling this a
reduction in line count would be false.

What actually improved is the *repetition*: adding a page is now one require
instead of five, and `ico()` exists in one place instead of three that could
drift apart.

Genuine deletion would mean removing features — the safety checks, the
recording, the audio backup. I have not done that without asking.

### Two bugs caught while refactoring

- **`padlink.php` and `patient.php` broke.** My sweep stripped
  `inc/pad.php` and `inc/trend.php`, which boot does not load. Found by
  testing every page, not by reading the diff; both restored.
- **I broke the login page.** Removing its local `ico()` left it calling a
  function it no longer had, because `login.php` is public and deliberately
  does not load `boot.php`. `ico()` now lives in `inc/icons.php`, which both
  public and private pages can include.

### Tested

24 pages pass with no PHP warnings. Saving a prescription (including Hindi),
adding a patient and adding a drug all still work — those are the paths the
new `pf()` / `pint()` helpers touch. Desktop rail unchanged at 216px with no
hamburger. Recording, the live dock and the audio backup all still work.


---

## v30 — how the voice pipeline works, and two bugs tracing it found

Tracing one real consultation end to end turned up two genuine faults. Both
are fixed.

### The five stages

**1. Microphone** — `miccheck.js` opens the mic and measures the speech band
directly, so a flat meter means hardware, not transcription.

**2. Audio** — `audiorec.js` records on that same stream, so nothing is lost
even when transcription fails completely.

**3. Speaker separation** — `speakers.js` decides who said each sentence from
its shape: a question, an instruction or a drug name is the doctor; a
complaint or "yes sir" is the patient.

**4. Extraction** — `scribe.js` scans for 18 symptoms, 6 vitals, the
diagnosis phrase, medicines, tests, 9 advice lines and the follow-up
interval, in English and Hindi, digits or spoken words.

**5. Tick list** — every item is a checkbox. Nothing enters the prescription
until you press "Fill the form", and nothing is saved until you press Save.

From `"dolo 650 three times daily after food for three days"` it now produces
**Tab Paracetamol 650mg · 1 tab · After Food · TDS · 3 days**.

### Bug 1 — brand names never reached the browser

The `brands` table has 102 Indian brands, but only the 20 drug *names* were
sent to the page. Saying **"Dolo 650"** matched nothing and the medicine row
stayed empty — the single most likely way a doctor would actually speak.

The page now builds a brand → your-stock map at load. **35 of the 102 brands
resolve to a drug you actually keep**; the rest are ignored rather than
guessed at. Brand words are checked before fuzzy matching, because an exact
brand is far stronger evidence than a partial match on a generic.

### Bug 2 — a symptom's timing was used as the dose timing

In Hindi: *"raat ko jalan hoti hai … pan 40 khali pet"* — burning **at
night**, Pantoprazole on an **empty stomach**. The prescription came out as
**Bedtime**.

Two causes, both fixed:

- Speech recognition returns **no punctuation**, so the whole consultation
  was one chunk and "raat ko" sat in the same text as the medicine. Chunks
  now break immediately **before a drug name**, which is where a prescribing
  phrase really begins.
- The timing rules ran with **last match wins**, so a later generic rule
  overwrote an earlier specific one. It is now first-match-wins with
  "empty stomach" ordered first.

Now correctly **Empty Stomach · OD · 5 days**.

### Verified

| | |
|---|---|
| English: "dolo 650 three times daily after food" | Paracetamol 650mg · After Food · TDS · 3 days |
| Hindi: "pan 40 khali pet ek baar paanch din" | Pantoprazole 40mg · Empty Stomach · OD · 5 days |
| Two medicines in one sentence | both captured separately |
| Safety checks still brand-aware | 102 entries, dolo → paracetamol |
| 18 pages pass, no JS errors | ✓ |


---

## v31 — accuracy measured, not assumed (86% → 100%)

The tests drove one scripted consultation and printed the result for a human
to read. That proves the pipeline runs. It does not measure how often it is
**right**, and it cannot catch a regression on its own.

### A real accuracy harness

`tests/cases.json` holds **10 consultations** — English and Hindi, one
medicine and two, spoken numbers, brand names, a visit with no diagnosis
stated — each with a hand-written expected answer.

`tests/accuracy.py` runs every case through the real page and scores each
extracted field:

```
  diagnosis        6/6   100%  ####################
  follow-up        9/9   100%  ####################
  medicines        8/8   100%  ####################
  med_when         6/6   100%  ####################
  ...
  OVERALL         64/64  100%
```

Run it with `python3 tests/accuracy.py`. A regression is now a number going
down, not a line somebody has to notice.

### The first run scored 86%, and found four real bugs

**1. A phantom antibiotic.** `"temperature 100"` was prescribing
**Doxycycline 100mg** — matching on the number `100` alone, with no drug word
anywhere in the sentence. A vital sign became a medicine. The strength bonus
now only counts *after* a drug word has matched, and a sentence that is
plainly about a vital sign can no longer yield a medicine at all.

This is the same class of bug as the ORS/"body ache" one found earlier, and
the reason it matters is that nobody would have spotted it by reading output.

**2. "650 days" of Paracetamol.** In `"crocin 650 din mein teen baar"`, the
strength was read as the duration because *din* (day) follows the number. The
strength is now stripped before the duration is parsed.

**3. Follow-up ignored above ten.** `words2num()` only knew one to ten, so
*"come back in fifteen days"* and *"thirty days"* produced no follow-up at
all — ordinary ways to say a fortnight or a month. Extended to fifty plus
Hindi *pandrah*, *bees*, *tees*. Also fixed a typo: **panch was mapped to 6**.

**4. Wrong strength from a brand.** *"glycomet 500"* returned **Metformin
1000mg**, because the brand map stores one drug per brand and you stock two
strengths. It now prefers the strength actually spoken.

### One "failure" was my mistake

The harness said `Cap Omeprazole 20mg` was wrong and `Tab Omeprazole` right.
The app was correct — *Cap* is the real name in your drug list. I corrected
the expected answer rather than the code, which is the whole point of writing
the expectations down where they can be argued with.

### Honest limits

100% here means 100% **on these ten consultations**, with the transcription
replayed rather than spoken. It does not measure Google's speech recognition,
accents, background noise, or a phrasing I have not thought of. What it does
give you is a floor: these ten cases cannot silently break again.


---

## v32 — the problem and the illness, visible without replaying anything

You said: while recording, the **illness and the problem** should be right
there, and checking them later should not mean listening to the whole
recording again.

Two gaps, both closed.

### While recording

The live dock showed the diagnosis, the vitals and the medicines — but never
the **complaints the patient actually came with**. That is the first thing a
doctor looks for, and it was the one thing missing.

The dock now opens with two headline rows, set larger than the rest:

```
✓ PROBLEM   fever, cough, sore throat, headache, body ache  since 3 days
✓ ILLNESS   viral fever
  BP        130/80
  TEMP      101.2
  MEDICINE  Tab Paracetamol 650mg  TDS · 3 days
  FOLLOW-UP 2026-09-16
```

Both appear as they are said, so nothing has to be remembered or replayed.

### Afterwards

The patient's record showed the diagnosis but kept the complaints inside the
collapsed conversation. You had to open it to see why the patient came.

Each visit now carries a **Problem** line directly under the diagnosis:

```
viral fever                                      13 Sep 2026
PROBLEM  fever, cough, sore throat, headache, body ache — since 3 days
Temp 101.2°F · BP 130/80 mmHg
1. Tab Paracetamol 650mg  1 tab · After Food · TDS (3 days)
```

The full conversation and the audio are still there if you want them — but
you no longer need them to understand the visit.

### A bug this exposed

Testing it showed the diagnosis being stored as:

> `viral fever dolo 650 three times daily after food for t`

Speech gives no punctuation, so *"this is viral fever"* ran straight into the
medicine sentence and the whole thing was saved as the diagnosis. It would
have printed on the prescription that way.

The diagnosis now stops at the first drug or brand word — checked against
your own drug list and the 102 brands — and at a dosing phrase in case the
drug name itself was misheard.

**Added as a permanent test case** (`en-dx-runs-into-drug`), so it cannot
come back quietly. The suite is now **72/72, 100%**, up from 64 checks.

19 pages pass, no JS errors.


---

## v33 — the scribe now hears allergies (the most dangerous gap)

I measured the system for gaps rather than guessing, and found four. This
fixes the one that could hurt a patient; the other three are listed at the
end.

### What was wrong

The safety checks refuse a drug the patient is allergic to — but the allergy
only ever came from the registration form. **Six of your nine demo patients
have no allergy recorded.**

If a patient said *"I am allergic to penicillin"* during a consultation, the
scribe ignored it completely. Worse, tested on a real sentence:

> "yes sir I am allergic to penicillin, it gave me a rash last year"

it logged **rash as a symptom the patient has today**. The allergy was lost
and a false complaint was added.

### What it does now

An allergy stated aloud is offered as a tick box, marked out in red because
it carries more consequence than the rest:

```
⚠ ALLERGY HEARD
  ☑ Penicillin    adds to the patient record and the safety checks
```

Accept it and it is **merged into the patient's record** — never overwriting
an allergy already on file — and written to the audit trail. From that moment
every future prescription is checked against it.

Verified end to end:

```
patient record: Arjun Desai -> allergies: "Penicillin"
audit:          allergy_added — Penicillin (heard in consultation)

Prescribing Tab Amoxicillin 500mg  -> [DANGER] ALLERGY: allergic to PENICILLIN
Prescribing Augmentin 625 (brand)  -> [DANGER] ALLERGY: allergic to PENICILLIN
```

It catches the brand name too, because the allergy families already map
Augmentin to penicillin.

The allergy sentence is also **removed before the symptom scan**, so the
reaction being described is no longer recorded as today's complaint. "No
allergy" and the doctor merely asking "any allergy?" are both ignored.

### Three test cases added

`en-allergy-stated`, `en-allergy-none`, `hi-allergy-stated` — including a
check named `no-false-symptom` that fails if "rash" ever reappears in the
complaints. The Hindi case caught a second bug: *"haan sir mujhe sulfa se
allergy hai"* was captured as **"Haan sir mujhe sulfa"**, now trimmed to
**"Sulfa"**.

Suite: **79/79, 100%**, up from 72 checks.

### A false alarm worth recording

My first safety test reported *no warning* for Amoxicillin and I began
investigating the safety engine. The engine was correct — I had called
`safety_check($patient, $meds)` with the arguments reversed. Worth noting
because I nearly "fixed" working safety code on the strength of a broken
test.

### The other three gaps, still open

1. **No search over past consultations.** There is no FULLTEXT index on the
   transcripts, so "which patient mentioned chest pain?" cannot be answered.
2. **18 symptoms only.** Reasonable for common OPD but thin for anything else.
3. **No clinic statistics.** The structured summaries are in the database
   already — fever cases this month, most-prescribed drug, who never returned
   for follow-up — but nothing reads them.

18 pages pass, no JS errors.


---

## v34 — automation, self-learning, and less repeated markup

### It now learns from the doctor

The app counted how often a drug was used. It did nothing with that. Now it
watches what you actually write and adapts.

**Your own dose becomes the default.** The drug list said Paracetamol 650mg
was *TDS, 3 days*. After you prescribed *BD, 5 days* three times, the app
changed its own default to match:

```
BEFORE  freq=TDS  duration=3 days      (the list's guess)
AFTER   freq=BD   duration=5 days      (what you actually write)
```

Three consistent uses are required, so one unusual visit cannot move it.

**It suggests what you prescribe for this illness.** Type a diagnosis and a
row appears under it:

```
YOU USUALLY PRESCRIBE   [ Tab Paracetamol 650mg  3× ]   tap to add
```

Tapping fills a medicine row with **your** usual dose, not the list default.
The suggestions come only from your own prescription history — there is no
built-in "recommended treatment" list, and the app never suggests a drug you
have not prescribed yourself.

### Chores that used to need a button

On the first queue load of the day, `inc/auto.php` quietly:

- clears expired phone-pad sessions, which otherwise accumulate forever
- closes yesterday's untouched appointments, which were leaving the queue
  count wrong every morning
- can list patients whose follow-up date has passed with no later visit

Each job records the date it ran, so it cannot fire twice, and every one is
wrapped so housekeeping can never break a page the doctor is using.

### Less repeated markup

63 copies of the same field-and-label block and 40 hand-written cards were
the bulk of the repetition. `boot.php` now provides `field()`,
`field_select()`, `pill()` and `table_open()/table_close()`.

Applied to `patient_new.php` as the first case: **2,513 → 2,088 characters, a
17% cut**, and the dropdowns now read from the database, so adding a language
in Settings appears here automatically instead of needing a code edit.

A select shrinks from 133 characters to 54. A plain input only goes from 93
to 60 — worth doing, but not dramatic, and I have not converted pages where
it would make the markup harder to read rather than easier.

### Two bugs found while building it

- **The suggestion chips never appeared.** `array_slice` on a string-keyed
  array keeps the keys, so `json_encode` emitted an object and the
  JavaScript `.forEach()` silently did nothing. Fixed with `array_values`.
- **My first test looked like a failure but was not.** No chips showed for a
  diabetic patient because there was no viral-fever history for them — the
  feature was behaving correctly and my test was wrong.

### What I did not automate, and why

You asked for full automation. Two things I deliberately left manual:

- **Sending WhatsApp automatically.** It needs Meta's paid Cloud API,
  template approval, and recorded patient consent under the DPDP Act.
- **Saving a prescription without review.** The recording is good, not
  perfect — this same session found it inventing a drug from a temperature
  reading. A human check before a prescription reaches a patient is the one
  step that should stay.

21 pages pass, accuracy suite still 79/79 at 100%.


---

## v35 — new UI: "Indigo"

The teal clinical look has been replaced with a deeper indigo and violet
palette. Same layout, same markup, entirely different feel.

### What changed

| | Before | Now |
|---|---|---|
| Primary | teal `#0e7c86` | indigo `#4f46e5` |
| Dark surfaces | navy `#0f2a3d` | indigo-950 `#1e1b4b` |
| Page background | grey-green `#f5f8f9` | violet-grey `#f7f7fb` |
| Corners | 9–14px | 10 / 14 / 20px, softer |
| Shadows | neutral grey | tinted to the brand |
| Hero | flat navy-to-teal | indigo gradient with a light glow |
| Headings | -.02em | -.028em, tighter |
| Section labels | 10.5px | 10px, wider tracking |
| Pills | square-ish | fully rounded, bordered |

Status colours were re-picked to sit with indigo rather than against it:
emerald for success, rose for danger, amber for caution.

### Why it was a small change to make

Every colour already came from CSS variables, so replacing the tokens
recoloured the whole app at once. The shape and typography layer is appended
at the end of `app.css`, where it wins on cascade order — **no page file was
edited**, which is why the accuracy suite and all 21 pages still pass
unchanged.

### The bug this exposed

The first render had the hero going **indigo → teal → indigo**. Four
gradients had hard-coded hex values rather than `var()` references, so the
token change skipped them:

- `.banner` — the welcome strip, with `#17485f` in the middle
- `.login-wrap` — the sign-in background
- `.rlogo` and `.login-card .lg` — both logo marks, tealing to `#38b2ac`

Also two dark surfaces (`#0f2a3d` update banner, `#0e2a38` live dock) that
were never tokenised. All now on the palette; **zero old hexes remain**.

Worth noting for later: a design token system only works if nothing bypasses
it, and four rules had.

### Verified

- No horizontal overflow at 390, 768 or 1400px
- Mobile drawer, consultation page, WhatsApp preview all correct
- The WhatsApp preview stays green — that is WhatsApp's brand, not ours
- 21 pages pass, no JS errors
- Accuracy suite still **79/79, 100%**

The old stylesheet is kept at `/tmp/app.css.v34` for this session if you want
the teal back.


---

## v36 — why medicine, illness and precautions were being missed

You were right on all three. Testing five realistic consultations found a
separate cause for each.

### 1. Illness — only six phrasings were recognised

The diagnosis was matched against `diagnosis is`, `this is`, `looks like`,
`seems like`, `it is`, `impression is`. Anything else produced **nothing**:

| Said | Before | Now |
|---|---|---|
| "you have gastroenteritis" | *(nothing)* | gastroenteritis |
| "patient is suffering from migraine" | *(nothing)* | migraine |
| "yeh gastritis hai" | "yeh gastritis" | gastritis |

Added: *you have*, *patient has*, *suffering from*, *case of*, *diagnosed
with*, *aapko*, *inko*, and the Hindi "… hai" pattern — with the leading
filler words (*yeh*, *ek*, *koi*) stripped.

### 2. The diagnosis box was pre-filled with the wrong illness

This was the worst one. The box opened pre-filled with the patient's stored
**chronic condition**, so a diabetic coming in with a fever started with
"Type 2 Diabetes" already typed, and Fatima Khan's visits all opened with
"Dengue fever - Day 4".

Because something was already in the box, the recording's finding looked like
it had been ignored — the medicine and illness appeared "not captured" when
they had been found correctly.

The stored conditions are now **offered, not filled**:

```
ON RECORD  [ Type 2 Diabetes ]  [ Hypertension ]
```

Tap one if it is relevant. Otherwise the box stays empty and the recording
fills it.

### 3. Precautions were barely recognised

Only nine advice phrasings existed in code, and the 13 lines in your Settings
list were **never used for matching at all** — you could add advice there and
the recording would not recognise it when spoken.

Two fixes. The app now matches against **your own advice list** from the
database, using a 60% word-overlap so "avoid oily food" still finds "Avoid
oily and spicy food". And a precautions table was added for the wording
doctors actually use, especially in Hindi:

| Said | Captured |
|---|---|
| "thanda paani mat piye" | Avoid cold drinks and ice |
| "dhoop mein mat jaiye" | Stay out of the sun |
| "parhez rakhiye" | Follow the diet advised |
| "no smoking" | Stop smoking |
| "do not skip meals" | Do not skip meals |

The Hindi case went from **nothing at all** to three precautions.

### Medicines were fine

Worth saying plainly: the medicine capture was working. "cefixime 200 twice
daily" produced `Tab Cefixime 200mg` correctly in the first test. It *looked*
broken because the pre-filled diagnosis made the whole result look stale.

### Locked in

Four new test cases — `en-dx-you-have`, `en-dx-suffering-from`,
`en-precautions`, `hi-precautions` — and the harness now scores advice.

Suite: **87/87, 100%**, up from 79 checks. 19 pages pass.


---

## v37 — full clinical vocabulary loaded (and the bug it caused)

You asked how many medicines were loaded. The answer was **20** — enough for
a demo, not for a real OPD. Now:

| | Before | After |
|---|---|---|
| Medicines | 20 | **136** |
| Diagnoses | 0 | **93** |
| Tests | 18 | **40** |
| Advice lines | 13 | **30** |
| Symptoms recognised | 18 | **45** |
| Indian brands | 102 | 102 |

Loaded by `tools/seed_clinical.php`, which is safe to re-run: existing rows
keep their edits and their usage counts.

The 136 cover the usual Indian OPD — analgesics, ten antibiotic classes,
acidity and gut, diabetes including insulins, cardiac, respiratory and
inhalers, thyroid, vitamins, neuro and psychiatry, dermatology, and eye,
ear and nose preparations. Each carries a common adult starting dose so the
form fills in something sensible; **they are starting points, not
recommendations**, and should be reviewed in Settings once before real use.

### The bigger list broke drug matching — badly

This is the part worth reading. Immediately after loading, the accuracy suite
fell from **100% to 83%**, with medicine matching at **18%**. Seven times more
candidates meant seven times more ways to match the wrong one:

| Said | Picked | Why |
|---|---|---|
| "dry cough and sore throat" | Syp Cough Expectorant | the symptom word *cough* is in a drug name |
| "dolo 650" | Syp Paracetamol 250mg/5ml | brand mapped to the first matching row — a **paediatric syrup for an adult** |
| "paanch din" | Tab Folic Acid | fuzzy match on a number word |
| "telma 40" | Tab Telmisartan + HCTZ | a combination beat the single ingredient |
| "omez twenty" | Tab Esomeprazole | *omeprazole* is a substring of *esomeprazole* |

Every one of these would have put a wrong drug in front of a patient. Four
fixes:

1. A drug whose name contains an everyday clinical word (*cough*, *iron*,
   *calcium*) now needs a prescribing cue nearby — *tab*, *mg*, *giving*,
   *daily* — before it can be selected.
2. The brand map prefers **tablet or capsule over syrup or injection**, then
   the doctor's most-used form, then the higher strength.
3. A **single ingredient beats a combination** unless one was asked for.
4. An **exact generic beats a longer name containing it**.

Back to **87/87, 100%** — now with 136 drugs rather than 20.

### Worth keeping in mind

A bigger reference list is not automatically better. It made this app
measurably worse until the matcher was tightened, and without the accuracy
suite the regression would have shipped silently — the pages all still loaded
and nothing threw an error.

19 pages pass, all 136 drugs and 93 diagnoses reach the browser's
autocomplete.


---

## v38 — the vocabulary is now *recognised*, not just stored

v37 loaded 136 drugs and 93 diagnoses into the database. Testing showed a gap
I had not closed: the diagnoses were **in the list but not recognised when
spoken**.

### What was failing

| Said | Before | Now |
|---|---|---|
| "…typhoid fever. dolo 650…" | *(no illness)* | **Typhoid fever** |
| "…acute gastroenteritis" (named last) | *(no illness)* | **Acute gastroenteritis** |
| "I have hiccups and my voice is hoarse" | *(nothing)* | hiccups, hoarse voice |
| "pet mein gas bharti hai aur dakar aati hai" | illness = *"pet mein gas bharti"* | acidity, belching — **no false illness** |

A diagnosis was only found after a lead-in phrase — *"this is…"*, *"you
have…"*. Say the illness plainly, as doctors usually do, and nothing was
captured.

### Three fixes

**1. Diagnoses are matched by name.** All 93 from the database are now sent
to the page and matched as whole phrases. Whole-phrase only, deliberately: a
substring test would let *anaemia* fire on *anaemic* and *gout* on *gouty* —
close enough to be wrong on a prescription. The longest match wins, so
"dengue fever" beats "fever".

**2. Symptoms: 45 → 75.** Added hiccups, hoarse voice, bloating, belching,
heartburn, mouth ulcers, toothache, neck and shoulder pain, cramps, tremor,
chills, excessive thirst, blood in urine, white discharge, irregular and
painful periods, hair fall, snoring, forgetfulness, low mood, burning feet,
nose block and nose bleed — with the Hindi words patients actually use.

**3. A false-diagnosis bug.** The loose Hindi *"X hai"* pattern turned
"pet mein gas bharti hai" into a diagnosis. It now requires the captured
words to look like an illness — at most three words, no verb or preposition
inside.

### A bug worth naming

`\bhiccup\b` does not match **"hiccups"**. The word boundary sits before the
*s*, so the plural never matched — and the same trap was in *cramp*, *belch*,
*tremor*, *snor*, *rigor* and *chill*. Six patterns that looked correct and
silently caught nothing. Fixed with explicit plurals.

### Locked in

Four new test cases, including `hi-no-false-diagnosis`, which fails if a
Hindi sentence ever invents an illness again.

Suite: **99/99, 100%** across 22 consultations, up from 87 checks.
19 pages pass.


---

## v39 — the printed prescription, rebuilt for paper

What you type now prints as a proper clinical document rather than a web
page sent to a printer.

### What was wrong

The old sheet used **Georgia at 11.5px**. On a monitor that is fine; on
paper it is small and dated, and the person who has to read a drug name
across a pharmacy counter is exactly who the sheet is for. Sizes were in
pixels, which is the wrong unit for print; the patient line ran together
as one string; and there was no footer.

### What it looks like now

- **Sized in print units** — `pt` for type, `mm` for spacing, `@page A4`
  with a 12mm margin. A pixel has no fixed size on paper; a point does.
- **A humanist sans** (Inter, falling back to Segoe UI / Helvetica), which
  stays legible at small sizes on a cheap inkjet where a serif fills in.
- **The drug name is the biggest thing in the table** at 11pt bold, with
  the note underneath in italic. Doses use tabular figures so the numbers
  line up in a column.
- **Medicines are numbered** 1, 2, 3 — a pharmacist can tick them off.
- **A labelled patient strip** — Patient · Age/Sex · ABHA · Phone · Rx No.
  (zero-padded, so the prescription has a reference).
- **The allergy warning** is a red-bordered band that cannot be skimmed past.
- **A footer** with the validity note from Settings and the clinic address.
- **Page-break rules**: a medicine row, an advice line or the signature
  block will never be split across two pages, and the table header repeats
  if it runs onto a second.

### Two bugs found while testing it

- **The Print button was still teal.** Two hard-coded `#0e7c86` values in
  `print.php` survived the v35 theme change, because they were inline
  styles rather than tokens. Same class of bug as the four found then.
- **"SOS" was missing from the When list.** Several of the new drugs —
  Sumatriptan, Albendazole, Loperamide — are prescribed "when needed", and
  with no such option the timing silently failed to fill. Added.

Also verified the unit now follows the drug: typing *ORS sachets* fills
**sachet**, *Inh Salbutamol* fills **puff**, *Cream Clotrimazole* fills
**application** — not "tab" for everything.

### Verified

A real A4 PDF was rendered, not just a screenshot. 14 pages pass, a
prescription with no vitals, medicines or advice still prints cleanly, and
the accuracy suite is unchanged at **99/99, 100%**.


---

## v40 — phone recording fixed, eraser fixed, vitals stored properly

Three separate problems.

### 1. Recording did not work on a phone

`rec.continuous = true` was set for every device. That is a desktop
setting:

- **Android Chrome** ignores it and ends the session after each utterance,
  so a consultation arrived as one phrase and then silence.
- **iOS Safari refuses to start at all** when continuous is true.

Tested against a simulated iPhone, the old code captured **nothing** —
`start()` threw a `NotSupportedError` and recording never began. With the
fix it captured the whole consultation.

The app now detects the device and adapts: continuous off on mobile,
interim results off on iOS (which does not provide usable ones), restart
delay cut from 300ms to 120ms, and the restart limit raised from 60 to 400
because a phone ends sessions constantly. On a simulated Android phone it
restarted 5 times by itself and kept all four spoken phrases.

### 2. The eraser destroyed the letterhead

The handwriting pad painted the clinic header, the ruled lines and the
doctor's ink onto **one canvas**, and the eraser used `destination-out` —
which removes whatever is underneath. Rubbing out a mistake punched a white
hole through the form itself.

There are now two layers: the printed form behind, the ink in front. The
eraser can only touch the ink. Verified by drawing a stroke (2,398 ink
pixels), erasing it (0 pixels), and confirming the letterhead and ruled
lines were untouched. The two layers are merged when the sheet is saved, so
the stored image is still a complete document.

### 3. Vitals could be printed but never used

Temperature, BP and the rest were stored as a **JSON blob** inside the
prescription. They printed correctly, but nothing could be asked of them —
no trend line, and no way to answer "which patients had BP over 140".

A `vitals` table now stores each reading as its own row, with the number
split out so it can be compared. `130/80` becomes systolic 130 and
diastolic 80; `101.4` becomes 101.40.

```
temp    101.4     num=101.40
bp      148/94    num=148.00  num2=94.00
sugar   210       num=210.00
```

That makes three new things possible, all verified working:

- **Search** — every visit where BP went over 140, or fever reached 101
- **Trends** — `vitals_history()` returns a patient's readings over time
- **Automatic flagging** — `vitals_flagged()` found High fever, High BP and
  High sugar on the test patient without being asked

The JSON copy is still written, so nothing that printed before has changed.
Allergies were already handled in v33: spoken aloud, added to the patient
record, and checked on every future prescription.

18 pages pass, accuracy suite unchanged at **99/99, 100%**.


---

## v41 — Hinglish mode

Added **Hinglish (mixed)** to the language selector, next to English and
हिन्दी. It is how most Indian doctors actually speak: English clinical
words inside Hindi grammar.

> "patient ko teen din se **fever** hai aur **body ache** bhi.
> **temperature** 101.2 hai **BP** 130 by 80. ye **viral fever** hai.
> dolo 650 din mein teen baar khane ke baad teen din."

### How it works

There is no mixed-language speech model in any browser. "hinglish" is our
own label, not a real language tag — sending it to the recogniser would
throw. Under the hood it selects **en-IN**, which keeps English drug names
and strengths intact while still transcribing Hindi words spoken in an
Indian accent. `hi-IN` mangles the drug names, which matters more.

### Five bugs the Hinglish tests found

Testing four realistic mixed consultations exposed failures that English
and Hindi alone never triggered:

| Said | Before | Cause |
|---|---|---|
| "paani **zyada** piye" | no advice | pattern expected `paani piye` adjacent |
| "**fasting** 180 hai" | **sugar not recorded** | value pattern needed the word *sugar* |
| "roz walk kijiye" | advice shown **twice** | built-in list and the doctor's list word it differently |
| "**bahar ka khana** mat khaiye" | no advice | not in the precautions table |
| "**thirty days baad** aana" | no follow-up | the "X baad" rule accepted only Hindi units |

All fixed. Also added *avoid sweets* and *boiled water* precautions, which
came up in the same tests.

### A wrong fix, corrected

My first attempt at the duplicate-advice problem compared an anagram key of
the letters. It failed, because "Daily 30 minute walk" and "Walk 30 minutes
daily" differ by one letter. The second attempt stemmed words with
`/(ing|es|s)$/`, which turned **minutes** into **minut** while **minute**
stayed — still no match. Stripping `-ing` and then a single `-s` works, and
I checked it against a control pair to be sure it does not merge genuinely
different advice.

Worth noting: both wrong versions *looked* correct and silently changed
nothing.

### Accuracy

**123/123 — 100%**, across 26 consultations: English, Hindi and four
Hinglish. Every field at 100%, including follow-up, which the Hinglish
cases broke.

That figure means 100% **on these 26 scripted consultations with the
transcription replayed**. It does not measure Google's speech recognition,
background noise, or a phrasing nobody has thought of yet. What it
guarantees is that these 26 cannot silently regress.

14 pages pass, no JS errors.
