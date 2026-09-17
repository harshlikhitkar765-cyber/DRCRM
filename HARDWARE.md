# Screens & hardware for Dr Bakshi Clinic

## The cheapest answer: buy nothing

Keep writing prescriptions **on paper**, exactly as you do today. Photograph the
sheet with the phone already in your pocket. `scan.php` cleans it up and sends it
on WhatsApp.

**Cost: ₹0. No tablet, no stylus, no board, no training.**

Use this first. Only spend money if it actually annoys you in daily use.

| Option | Cost | When it makes sense |
|---|---|---|
| **Paper + phone camera** (`scan.php`) | **₹0** | **Start here.** Nothing changes in how you work. |
| Typed form (`consult.php`) | ₹0 | When you want searchable data + a clean, readable script for the chemist |
| Tablet + stylus (`write.php`) | ₹30,000 | Only if writing directly on glass genuinely saves you time |
| Interactive board | ₹70,000+ | Patient education only — not for writing prescriptions |

The one screen genuinely worth buying is the **waiting-room TV**, below — because
that is the only one that does something paper cannot.

---
## What you actually need

| # | Where | What it shows | Buy |
|---|---|---|---|
| 1 | Waiting room wall | `display.php` — token board, "Now serving A-02" | 43″ TV + Fire TV Stick — **₹27,000** |
| 2 | Doctor's desk | `consult.php` — the consultation form | Any laptop / mini PC + monitor — **₹35,000** |
| 3 | Reception desk | `queue.php`, `appointment_new.php` | Existing desktop — **₹0** |

**Total for a working setup: ₹60,000–70,000.** A single 65″ smart board costs more
than double this and does less.

---

## 1. Waiting-room token board — the highest-value screen

Perceived waiting time drops sharply when patients can see their place in the
queue. Page: **`display.php`** (built and working — open it full-screen).

* Auto-refreshes every 15 seconds
* Big "Now in consultation" token + next 7 waiting
* Shows **first name + last initial only** ("Anjali S.") — no diagnosis, no phone,
  no ABHA. Safe on a public wall under DPDP.
* Footer carries OPD hours and the WhatsApp booking number — free advertising
  to a captive audience

**Recommended hardware**

| Item | Model | Price |
|---|---|---|
| Display | Any 43″ 4K TV (Xiaomi / TCL / Hisense) | ₹22,000–26,000 |
| Player | Amazon Fire TV Stick 4K + Silk browser in kiosk mode | ₹3,500–5,000 |
| Mount | Fixed VESA wall mount | ₹800 |

Mount it **landscape at 7–7.5 ft**, angled slightly down. 43″ is right for a room
where the farthest chair is under 15 ft; go 50″ if your waiting area is deeper.

Cheaper alternative: an old Android phone or a ₹3,000 Android TV box also works —
the page is just a web page.

Even cheaper: a ₹6,000–15,000 seven-segment token display from an IndiaMART
vendor. But those are numbers only — no name, no clinic branding, no WhatsApp
number, and they need separate wiring. Since you already have the software, the
TV is better value.

## 2. Doctor's desk

`consult.php` is a two-column layout — the form on the left, the live WhatsApp
preview on the right. It needs **1920×1080 minimum**. A 24″ monitor is ideal; a
13″ laptop will feel cramped.

A **touchscreen is genuinely useful here** — the lab-test tags and medicine rows
are tap-friendly. But a ₹40,000 24″ touch monitor, not a ₹1.5 lakh board.

An entry mini PC (Intel N100, 8 GB, ~₹18,000) runs PHP + MySQL for this app
without effort. It is a single-doctor clinic — the database will be a few MB
after years of use.

## 3. Handwriting prescriptions on a touch screen — **revised advice**

`write.php` now lets the doctor **write the prescription by hand with a stylus**
on a real letterhead. This changes the hardware answer for the desk.

You still don't want a 65″ wall panel for this. Writing a prescription on a
vertical 65″ board means standing up and writing at arm's length above your
head — worse than paper. Handwriting needs a screen that **lies flat or tilts**,
at desk height.

Best options, cheapest first:

| Option | Price | Notes |
|---|---|---|
| **Android tablet + stylus** (Samsung Tab S9 FE, Xiaomi Pad 6) | **₹25,000–35,000** | Best value. Open `write.php` in Chrome. S-Pen reports true pressure. **Recommended.** |
| iPad + Apple Pencil | ₹40,000–70,000 | Best writing feel available. Safari fully supports the pressure API used here. |
| Wacom One 13 pen display | ₹35,000–45,000 | Tilts flat on the desk, plugs into the mini PC, feels closest to paper. |
| 24″ touch monitor | ₹40,000+ | Fine for tapping, poor for writing — vertical, and most are finger-only, no pressure. |
| 65″ interactive board | ₹70,000+ | Wrong tool for writing scripts. |

**Buy a tablet with an active stylus.** ₹30,000, writes better than any board,
and it's portable — which matters because you also do **home visits and Home
ICU rounds**. Carry the tablet to the patient's house, write the script at the
bedside, send it on WhatsApp before you leave. A wall panel can't do that.

Make sure the stylus is **active/EMR (pressure-sensitive)**, not a cheap rubber-tip
capacitive pen. Tick **"Stylus only"** in the toolbar for palm rejection.

## 4. If you still want a large interactive panel

Only worth it if you plan to do **patient education** — drawing on an anatomy
diagram to explain a stent or a diabetic foot. In that case:

| Size | Street price in India (2026) |
|---|---|
| 65″ | ₹70,000 (unbranded) – ₹1.3 L (LG/Samsung) |
| 75″ | ₹85,000 (unbranded) – ₹1.5 L |

Buy an **Android 13/14 panel with 20-point IR touch, 8 GB RAM, and 3-year on-site
warranty**. Verified Indian street pricing sits far below the "MRP" lists some
sites publish — 65″ IFPs start around ₹70,000–₹1,00,000 and 75″ around
₹85,000–₹1,30,000, so treat any ₹2.5 lakh quote for a 65″ as heavily padded.

Put it **in the consultation room, not the waiting room**. A touch panel on a
waiting-room wall just collects fingerprints — that room needs a display, not a
whiteboard.

---

## Network note

The app must be reachable from the TV. Options:

1. **Run it on the clinic LAN** (mini PC at reception, static IP). The TV loads
   `http://192.168.1.x:3000/display.php`. Fastest, no internet dependency, no
   patient data leaves the building. **Recommended.**
2. Host on a cloud VPS with HTTPS. Then add authentication to `display.php` or an
   IP allowlist — right now it is deliberately open so a dumb player can load it,
   which is fine on a private LAN but not on the public internet.

Put the mini PC and the router on a **small UPS** (~₹3,000). Bhopal power cuts
during a 7–9 PM OPD should not stop the queue.
