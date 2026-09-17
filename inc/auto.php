<?php
/* ==================================================================
   Automation and self-learning.

   Two jobs in one file:

   1. AUTOMATE — the chores that used to need a button: nightly backup,
      clearing expired pad sessions, flagging patients who never came
      back, marking the day's no-shows.

   2. LEARN — the app watches what the doctor actually does and makes
      the next consultation shorter: which medicines go together, what
      is usually prescribed for a given diagnosis, the doctor's own
      default dose for a drug.

   Everything here is a suggestion or a housekeeping task. Nothing
   prescribes, and nothing is sent to a patient without a human.
   ================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------
   Scheduled jobs. Safe to run repeatedly; each records when it last
   ran so a job cannot fire twice in the same day.
   ------------------------------------------------------------------ */

function auto_ran_today(string $job): bool {
    $q = db()->prepare("SELECT sval FROM settings WHERE skey=?");
    $q->execute(['auto_' . $job]);
    return $q->fetchColumn() === date('Y-m-d');
}

function auto_mark(string $job): void {
    db()->prepare('INSERT INTO settings(skey,sval) VALUES(?,?)
                   ON DUPLICATE KEY UPDATE sval=VALUES(sval)')
        ->execute(['auto_' . $job, date('Y-m-d')]);
}

/* Expired phone-pad sessions pile up forever otherwise. */
function auto_clean_pads(): int {
    $n = db()->exec("DELETE FROM pad_sessions WHERE expires_at < NOW() - INTERVAL 1 DAY");
    return (int)$n;
}

/* Yesterday's appointments nobody touched are no-shows, not still waiting.
   Left alone, the queue count is wrong every morning. */
function auto_close_stale(): int {
    $n = db()->exec("UPDATE appointments SET status='Cancelled'
                     WHERE status='Waiting' AND appt_date < CURDATE() - INTERVAL 1 DAY");
    return (int)$n;
}

/* Patients whose follow-up date has passed with no later visit. */
function auto_due_recalls(): array {
    $q = db()->query("
        SELECT p.id, p.name, p.phone, p.lang, r.follow_up, r.diagnosis,
               DATEDIFF(CURDATE(), r.follow_up) AS overdue
        FROM prescriptions r
        JOIN patients p ON p.id = r.patient_id
        WHERE r.follow_up <> '' AND r.follow_up IS NOT NULL
          AND r.follow_up < CURDATE()
          AND NOT EXISTS (SELECT 1 FROM prescriptions r2
                          WHERE r2.patient_id = r.patient_id AND r2.rx_date > r.follow_up)
        ORDER BY overdue DESC LIMIT 50");
    return $q->fetchAll();
}

/* Run everything due. Called on page load, and by cron if you set one up. */
function auto_run(): array {
    $done = [];
    try {
        if (!auto_ran_today('daily')) {
            $p = auto_clean_pads();
            $s = auto_close_stale();
            if ($p) $done[] = "cleared $p expired pad session" . ($p > 1 ? 's' : '');
            if ($s) $done[] = "closed $s stale appointment" . ($s > 1 ? 's' : '');
            auto_mark('daily');
        }
    } catch (Throwable $e) { /* housekeeping must never break a page */ }
    return $done;
}

/* ------------------------------------------------------------------
   Learning. The doctor's own history is the training data.
   ------------------------------------------------------------------ */

/* Which medicines this doctor actually prescribes for a diagnosis, most
   used first. After a few visits this is better than any built-in list. */
function learn_meds_for(string $diagnosis, int $limit = 5): array {
    $d = trim(mb_strtolower($diagnosis));
    if ($d === '') return [];
    try {
        $q = db()->prepare("SELECT meds FROM prescriptions
                            WHERE LOWER(diagnosis) LIKE ? ORDER BY id DESC LIMIT 40");
        $q->execute(['%' . $d . '%']);
        $tally = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach ((json_decode((string)$json, true) ?: []) as $m) {
                $n = trim((string)($m['name'] ?? ''));
                if ($n === '') continue;
                if (!isset($tally[$n])) $tally[$n] = ['n' => 0, 'm' => $m];
                $tally[$n]['n']++;
            }
        }
        uasort($tally, fn($a, $b) => $b['n'] <=> $a['n']);
        /* array_values, or the string keys survive array_slice and
           json_encode emits an object — which .forEach() cannot walk. */
        return array_values(array_slice(
            array_map(fn($x) => $x['m'] + ['times' => $x['n']], $tally), 0, $limit));
    } catch (Throwable $e) { return []; }
}

/* The doctor's own usual dose for a drug, which often differs from the
   default in the drug list. Learned from the last 20 prescriptions. */
function learn_dose_for(string $drug): ?array {
    $drug = trim($drug);
    if ($drug === '') return null;
    try {
        $q = db()->query("SELECT meds FROM prescriptions ORDER BY id DESC LIMIT 60");
        $seen = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach ((json_decode((string)$json, true) ?: []) as $m) {
                if (strcasecmp(trim((string)($m['name'] ?? '')), $drug) !== 0) continue;
                $key = ($m['dose'] ?? '') . '|' . ($m['when'] ?? '') . '|' .
                       ($m['freq'] ?? '') . '|' . ($m['duration'] ?? '');
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }
        if (!$seen) return null;
        arsort($seen);
        $best = array_key_first($seen);
        if ($seen[$best] < 2) return null;         /* one use is not a habit */
        [$dose, $when, $freq, $dur] = explode('|', $best);
        return ['dose' => $dose, 'when' => $when, 'freq' => $freq,
                'duration' => $dur, 'times' => $seen[$best]];
    } catch (Throwable $e) { return null; }
}

/* Quietly keep the drug list's defaults in step with what the doctor
   really writes. Only changes a default after the same combination has
   been used three times, so one unusual visit cannot move it. */
function learn_apply_defaults(): int {
    $changed = 0;
    try {
        $drugs = db()->query('SELECT id,name,def_dose,def_when,def_freq,def_duration
                              FROM drugs WHERE active=1')->fetchAll();
        foreach ($drugs as $d) {
            $L = learn_dose_for($d['name']);
            if (!$L || $L['times'] < 3) continue;
            if ($L['dose'] === $d['def_dose'] && $L['when'] === $d['def_when'] &&
                $L['freq'] === $d['def_freq'] && $L['duration'] === $d['def_duration']) continue;
            db()->prepare('UPDATE drugs SET def_dose=?,def_when=?,def_freq=?,def_duration=?
                           WHERE id=?')
                ->execute([$L['dose'], $L['when'], $L['freq'], $L['duration'], $d['id']]);
            $changed++;
        }
    } catch (Throwable $e) { /* learning must never break a save */ }
    return $changed;
}

/* Common illnesses this month, for the dashboard. */
function learn_top_diagnoses(int $days = 30, int $limit = 6): array {
    try {
        $q = db()->prepare("SELECT diagnosis, COUNT(*) n FROM prescriptions
                            WHERE rx_date >= CURDATE() - INTERVAL ? DAY
                              AND diagnosis <> ''
                            GROUP BY diagnosis ORDER BY n DESC LIMIT ?");
        $q->bindValue(1, $days, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ------------------------------------------------------------------
   Vitals history.

   The prescription keeps its JSON copy for printing, but every reading
   is also written as its own row so it can be compared and charted.
   "130/80" is split into 130 and 80; "101.2 F" becomes 101.2.
   ------------------------------------------------------------------ */

function vitals_save(int $patientId, ?int $rxId, array $vitals, string $onDate = ''): int {
    if ($patientId <= 0) return 0;
    $onDate = $onDate !== '' ? $onDate : date('Y-m-d');
    $saved  = 0;

    try {
        $ins = db()->prepare('INSERT INTO vitals(patient_id,rx_id,taken_on,kind,val,num,num2)
                              VALUES(?,?,?,?,?,?,?)');
        foreach ($vitals as $kind => $raw) {
            $val = trim((string)$raw);
            if ($val === '') continue;
            $kind = strtolower(trim($kind));

            $num = $num2 = null;
            if ($kind === 'bp') {
                /* 130/80, 130 by 80, 130-80 */
                if (preg_match('/(\d{2,3})\s*(?:\/|by|-)\s*(\d{2,3})/i', $val, $m)) {
                    $num  = (float)$m[1];
                    $num2 = (float)$m[2];
                }
            } elseif (preg_match('/(\d+(?:\.\d+)?)/', $val, $m)) {
                $num = (float)$m[1];
            }

            $ins->execute([$patientId, $rxId ?: null, $onDate, $kind, $val, $num, $num2]);
            $saved++;
        }
    } catch (Throwable $e) { /* a reading must never block the prescription */ }

    return $saved;
}

/* Readings of one kind over time, oldest first — for a trend line. */
function vitals_history(int $patientId, string $kind, int $limit = 24): array {
    try {
        $q = db()->prepare('SELECT taken_on, val, num, num2 FROM vitals
                            WHERE patient_id=? AND kind=? ORDER BY taken_on DESC, id DESC LIMIT ?');
        $q->bindValue(1, $patientId, PDO::PARAM_INT);
        $q->bindValue(2, $kind);
        $q->bindValue(3, $limit, PDO::PARAM_INT);
        $q->execute();
        return array_reverse($q->fetchAll());
    } catch (Throwable $e) { return []; }
}

/* The latest reading of each kind, for the patient header. */
function vitals_latest(int $patientId): array {
    try {
        $q = db()->prepare('SELECT v.kind, v.val, v.taken_on FROM vitals v
                            JOIN (SELECT kind, MAX(id) mx FROM vitals
                                  WHERE patient_id=? GROUP BY kind) t
                              ON t.mx = v.id');
        $q->execute([$patientId]);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[$r['kind']] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

/* Patients whose last reading is outside a safe range — the point of
   storing vitals as numbers rather than text. */
function vitals_flagged(): array {
    try {
        return db()->query("
            SELECT p.id, p.name, v.kind, v.val, v.taken_on,
                   CASE
                     WHEN v.kind='bp'    AND v.num  >= 140 THEN 'High BP'
                     WHEN v.kind='bp'    AND v.num  <= 90  THEN 'Low BP'
                     WHEN v.kind='sugar' AND v.num  >= 200 THEN 'High sugar'
                     WHEN v.kind='temp'  AND v.num  >= 101 THEN 'High fever'
                     WHEN v.kind='spo2'  AND v.num  <= 94  THEN 'Low SpO2'
                   END AS flag
            FROM vitals v
            JOIN patients p ON p.id = v.patient_id
            JOIN (SELECT patient_id, kind, MAX(id) mx FROM vitals
                  GROUP BY patient_id, kind) t ON t.mx = v.id
            HAVING flag IS NOT NULL
            ORDER BY v.taken_on DESC LIMIT 30")->fetchAll();
    } catch (Throwable $e) { return []; }
}
