<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    /* Copy-pasting these four values out of a hosting control panel very often
       drags in a leading or trailing space. MySQL then reports a confusing
       "Access denied ... to database ' name'" that looks like a permissions
       problem when it is really a stray character. Trim them. */
    /* The live values come from inc/config.php. A local sandbox or a second
       machine can override them by dropping a data/config.local.php that
       returns an array — that file is never uploaded to the host, so the
       real credentials in config.php are left untouched. */
    $host = trim(DB_HOST); $name = trim(DB_NAME);
    $user = trim(DB_USER); $pass = trim(DB_PASS, " \t\n\r\0\x0B");
    $port = (int)DB_PORT;

    /* Production uses only inc/config.php. Do not allow a local override on the live server. */

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                   $host, $port, $name, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        /* A clear message beats a stack trace when the host details are wrong. */
        http_response_code(500);
        $msg  = $e->getMessage();
        $hint = '';

        /* Point at the actual cause instead of making the reader guess. */
        if (str_contains($msg, 'Access denied') && str_contains($msg, 'to database')) {
            $hint = 'MySQL refused this user for that database. Either the user was '
                  . 'never added to the database, or the database name is not exactly right. '
                  . 'In cPanel/hPanel open <b>MySQL Databases</b>, find "Add user to database", '
                  . 'and make sure the user is attached with <b>ALL PRIVILEGES</b>.';
        } elseif (str_contains($msg, 'Access denied for user')) {
            $hint = 'The username or password was not accepted. Retype the password by hand '
                  . 'rather than pasting it, and check for a space at either end.';
        } elseif (str_contains($msg, 'Unknown database')) {
            $hint = 'That database does not exist on this server. On shared hosting the real '
                  . 'name usually carries an account prefix, like <code>u123456_clinic</code>.';
        } elseif (str_contains($msg, 'could not find driver')) {
            $hint = 'PHP has no MySQL driver. Enable <b>pdo_mysql</b> — on cPanel that is '
                  . '"Select PHP Version" &rarr; Extensions &rarr; tick <code>pdo_mysql</code>.';
        } elseif (str_contains($msg, 'Connection refused') || str_contains($msg, "Can't connect")) {
            $hint = 'Nothing is answering at that host and port. On shared hosting DB_HOST is '
                  . 'nearly always <code>localhost</code>.';
        }

        /* Show what was actually used, so an invisible stray character is visible. */
        $shown = [
            'DB_HOST' => $host,
            'DB_PORT' => (string)$port,
            'DB_NAME' => $name,
            'DB_USER' => $user,
            'DB_PASS' => $pass === '' ? '(empty)' : str_repeat('•', min(12, strlen($pass))),
        ];
        $rows = '';
        foreach ($shown as $k => $v) {
            $raw   = defined($k) ? constant($k) : $v;
            $dirty = is_string($raw) && $raw !== trim($raw) && $v === trim((string)$raw);
            $rows .= '<tr><td style="padding:3px 12px 3px 0;color:#667">' . $k . '</td>'
                   . '<td style="padding:3px 0"><code>[' . htmlspecialchars($v) . ']</code>'
                   . ($dirty ? ' <b style="color:#c62828">&larr; had a space around it; '
                             . 'remove it in inc/config.php</b>' : '')
                   . '</td></tr>';
        }

        echo '<div style="font:14px/1.6 system-ui;margin:40px auto;max-width:640px;color:#222">'
           . '<h2 style="font-size:18px;margin:0 0 10px">Cannot reach the database</h2>'
           . '<p style="margin:0 0 12px">The server said:<br><b style="color:#c62828">'
           . htmlspecialchars($msg) . '</b></p>'
           . ($hint ? '<p style="background:#fff6e5;border:1px solid #e5c07b;border-radius:8px;'
                    . 'padding:11px 13px;margin:0 0 14px">' . $hint . '</p>' : '')
           . '<p style="margin:0 0 6px;color:#667">These are the values it used '
           . '(square brackets show any stray spaces):</p>'
           . '<table style="border-collapse:collapse;font-size:13px">' . $rows . '</table>'
           . '<p style="margin:14px 0 0;color:#667">Edit them in <code>inc/config.php</code>. '
           . 'Setup steps are in <code>DATABASE.md</code>.</p></div>';
        exit;
    }

    /* Dates are written by PHP in Asia/Kolkata; keep MySQL in step so that
       anything evaluated server-side (pad expiry) agrees with it. */
    $pdo->exec("SET time_zone = '+05:30'");

    $fresh = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                               WHERE table_schema = DATABASE()")->fetchColumn() === 0;
    migrate($pdo);
    if ($fresh) seed($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    /* MySQL/MariaDB schema.
       Notes on the translation from the old single-file database:
       - INTEGER PRIMARY KEY AUTOINCREMENT  ->  INT AUTO_INCREMENT PRIMARY KEY
       - TEXT columns that are indexed or UNIQUE must be VARCHAR(n) in MySQL,
         because an unbounded TEXT cannot carry a plain unique index.
       - datetime('now','localtime')        ->  CURRENT_TIMESTAMP
       - Everything is InnoDB + utf8mb4 so the Hindi text and the emoji in the
         message templates survive. */
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS patients(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL, age INT, sex VARCHAR(10),
      phone VARCHAR(30) NOT NULL, abha VARCHAR(40), city VARCHAR(80),
      conditions TEXT, allergies TEXT,
      care VARCHAR(30) DEFAULT 'OPD', risk VARCHAR(20) DEFAULT 'Low',
      lang VARCHAR(20) DEFAULT 'English',
      wa_consent TINYINT DEFAULT 0,
      consent_at DATETIME NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(name), INDEX(phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS appointments(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      appt_date DATE NOT NULL, appt_time VARCHAR(10) NOT NULL,
      visit_type VARCHAR(40) DEFAULT 'New', mode VARCHAR(30) DEFAULT 'In-clinic',
      reason TEXT, status VARCHAR(20) DEFAULT 'Waiting', token VARCHAR(20),
      INDEX(appt_date), INDEX(patient_id),
      CONSTRAINT fk_appt_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS prescriptions(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      rx_date DATE NOT NULL,
      diagnosis TEXT, vitals TEXT, meds TEXT, labs TEXT,
      advice TEXT, follow_up VARCHAR(20),
      ink_file VARCHAR(255), ink_mode TINYINT DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(patient_id), INDEX(rx_date),
      CONSTRAINT fk_rx_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS wa_messages(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      rx_id INT NULL,
      phone VARCHAR(30), lang VARCHAR(20), body MEDIUMTEXT,
      driver VARCHAR(20), status VARCHAR(20) DEFAULT 'Queued', response TEXT,
      sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(patient_id),
      CONSTRAINT fk_wa_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
      CONSTRAINT fk_wa_rx FOREIGN KEY (rx_id) REFERENCES prescriptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS templates(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL, lang VARCHAR(20) NOT NULL DEFAULT 'English',
      body MEDIUMTEXT NOT NULL, is_default TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS homecare(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      service VARCHAR(60), addr TEXT, equipment TEXT, staff TEXT,
      started DATE, rate INT, note TEXT, status VARCHAR(20) DEFAULT 'Active',
      INDEX(patient_id),
      CONSTRAINT fk_hc_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS payments(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      rx_id INT NULL,
      pay_date DATE NOT NULL, item VARCHAR(160), amount INT NOT NULL DEFAULT 0,
      paid TINYINT NOT NULL DEFAULT 0, mode VARCHAR(20) DEFAULT 'Cash', note TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(pay_date), INDEX(patient_id),
      CONSTRAINT fk_pay_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
      CONSTRAINT fk_pay_rx FOREIGN KEY (rx_id) REFERENCES prescriptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS documents(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      kind VARCHAR(40) DEFAULT 'Report', title VARCHAR(200), file VARCHAR(255) NOT NULL,
      doc_date DATE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(patient_id),
      CONSTRAINT fk_doc_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS pad_sessions(
      token VARCHAR(64) PRIMARY KEY,
      patient_id INT NOT NULL,
      appt_id INT, mode VARCHAR(20) DEFAULT 'write',
      status VARCHAR(20) DEFAULT 'waiting', result_file VARCHAR(255), result_kind VARCHAR(20),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      CONSTRAINT fk_pad_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS wa_replies(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NULL,
      phone VARCHAR(30), body TEXT, intent VARCHAR(30), handled TINYINT DEFAULT 0,
      received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(patient_id),
      CONSTRAINT fk_rep_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS users(
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(60) UNIQUE NOT NULL, pass_hash VARCHAR(255) NOT NULL,
      name VARCHAR(120), role VARCHAR(20) DEFAULT 'staff', active TINYINT DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS audit(
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(60), action VARCHAR(60), entity VARCHAR(40), entity_id INT,
      detail TEXT, ip VARCHAR(45), at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS drugs(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(160) NOT NULL, generic VARCHAR(120), form VARCHAR(30) DEFAULT 'Tab',
      strength VARCHAR(40), def_dose VARCHAR(20) DEFAULT '1', def_unit VARCHAR(20) DEFAULT 'tab',
      def_when VARCHAR(30) DEFAULT 'After Food', def_freq VARCHAR(20) DEFAULT 'OD',
      def_duration VARCHAR(30) DEFAULT '5 days', notes TEXT,
      uses INT DEFAULT 0, active TINYINT DEFAULT 1,
      INDEX(name), INDEX(generic)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS labs(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL UNIQUE, grp VARCHAR(60) DEFAULT 'General',
      uses INT DEFAULT 0, active TINYINT DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS consult_notes(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      appt_id INT,
      rx_id INT,
      transcript MEDIUMTEXT,
      turns MEDIUMTEXT,        /* JSON: [{s:'dr'|'pt', t:'...'}] speaker-tagged lines */
      summary MEDIUMTEXT,      /* JSON: the final main-points record for the visit */
      ended_at DATETIME NULL,  /* when the doctor stopped recording */
      secs INT DEFAULT 0,      /* how long the consultation ran */
      disease VARCHAR(200),    /* what the conversation was about */
      dr_points MEDIUMTEXT,    /* JSON: what the doctor said about it */
      pt_points MEDIUMTEXT,    /* JSON: what the patient said about it */
      lang VARCHAR(20) DEFAULT 'en-IN',
      picked TEXT,
      started_at DATETIME NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(patient_id), INDEX(rx_id), INDEX(disease),
      CONSTRAINT fk_note_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
      CONSTRAINT fk_note_rx FOREIGN KEY (rx_id) REFERENCES prescriptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    /* ---- Settings: anything the doctor should be able to change without
            editing a PHP file. Stored as key/value so a new setting never
            needs a schema change. ---- */
    /* ---- Vitals as their own rows, not a JSON blob ----
       Vitals were stored as JSON inside the prescription, which meant a
       reading could be printed but never searched - finding every visit
       where BP went over 140 was impossible, and so was a trend line.
       Each reading is now a row, with the numeric part split out so it
       can be compared. The JSON stays for backward compatibility. ---- */
    CREATE TABLE IF NOT EXISTS vitals(
      id INT AUTO_INCREMENT PRIMARY KEY,
      patient_id INT NOT NULL,
      rx_id INT NULL,
      taken_on DATE NOT NULL,
      kind VARCHAR(16) NOT NULL,        /* temp | bp | pulse | sugar | spo2 | weight */
      val VARCHAR(24) NOT NULL,         /* as written, e.g. 130/80 or 101.2 */
      num DECIMAL(6,2) NULL,            /* comparable: 101.2, or systolic 130 */
      num2 DECIMAL(6,2) NULL,           /* diastolic 80, where it applies */
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX(patient_id, kind, taken_on),
      INDEX(kind, num),
      CONSTRAINT fk_vit_pt FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
      CONSTRAINT fk_vit_rx FOREIGN KEY (rx_id) REFERENCES prescriptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS settings(
      skey VARCHAR(60) PRIMARY KEY,
      sval TEXT,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    /* ---- Every dropdown in the app. One table, a `kind` column per list,
            so adding a new When option is a row, not a code change. ---- */
    CREATE TABLE IF NOT EXISTS picklists(
      id INT AUTO_INCREMENT PRIMARY KEY,
      kind VARCHAR(30) NOT NULL,      /* unit | when | freq | form | lang | care | risk | paymode */
      val VARCHAR(80) NOT NULL,
      sort INT DEFAULT 0,
      active TINYINT DEFAULT 1,
      UNIQUE KEY uq_kind_val (kind, val),
      INDEX(kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    /* ---- Indian brand to generic. Was a PHP array; now the doctor can add
            the brands their own patients actually bring in. ---- */
    CREATE TABLE IF NOT EXISTS brands(
      id INT AUTO_INCREMENT PRIMARY KEY,
      brand VARCHAR(80) NOT NULL,
      generic VARCHAR(80) NOT NULL,
      active TINYINT DEFAULT 1,
      UNIQUE KEY uq_brand (brand),
      INDEX(generic)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    /* ---- Diagnoses written often, for autocomplete. Learns by use. ---- */
    CREATE TABLE IF NOT EXISTS diagnoses(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(160) NOT NULL,
      icd VARCHAR(20),
      uses INT DEFAULT 0,
      active TINYINT DEFAULT 1,
      UNIQUE KEY uq_dx (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    /* ---- Standard advice lines, in each language. ---- */
    CREATE TABLE IF NOT EXISTS advice_lines(
      id INT AUTO_INCREMENT PRIMARY KEY,
      text VARCHAR(240) NOT NULL,
      lang VARCHAR(20) DEFAULT 'English',
      uses INT DEFAULT 0,
      active TINYINT DEFAULT 1,
      INDEX(lang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS rx_sets(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(160) NOT NULL, diagnosis TEXT, meds TEXT, labs TEXT,
      advice TEXT, uses INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    /* Upgrade an existing consult_notes table that predates speaker separation. */
    $cn = array_column($pdo->query('SHOW COLUMNS FROM consult_notes')->fetchAll(), 'Field');
    foreach ([
        'turns'     => 'MEDIUMTEXT',
        'disease'   => 'VARCHAR(200)',
        'dr_points' => 'MEDIUMTEXT',
        'pt_points' => 'MEDIUMTEXT',
        'summary'   => 'MEDIUMTEXT',
        'ended_at'  => 'DATETIME NULL',
        'secs'      => 'INT DEFAULT 0',
    ] as $col => $type) {
        if (!in_array($col, $cn, true)) $pdo->exec("ALTER TABLE consult_notes ADD COLUMN `$col` $type");
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM picklists')->fetchColumn() === 0)    seed_picklists($pdo);
    if ((int)$pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn() === 0)       seed_brands($pdo);
    if ((int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0)     seed_settings($pdo);
    if ((int)$pdo->query('SELECT COUNT(*) FROM advice_lines')->fetchColumn() === 0) seed_advice($pdo);

    if ((int)$pdo->query('SELECT COUNT(*) FROM drugs')->fetchColumn() === 0)  seed_drugs($pdo);
    if ((int)$pdo->query('SELECT COUNT(*) FROM labs')->fetchColumn()  === 0)  seed_labs($pdo);
}

function seed(PDO $pdo): void {
    $patients = [
        ['Anjali Sharma',47,'F','+91 98220 41192','91-4271-8830-5512','M.P. Nagar','Type 2 Diabetes, Hypertension','Sulfa drugs','OPD','High','Hindi'],
        ['Ramesh Iyer',58,'M','+91 99870 33210','91-2210-4471-9083','Arera Colony','Hypertension, Dyslipidemia','','OPD','Medium','English'],
        ['Fatima Khan',35,'F','+91 91456 77821','91-8834-2019-4477','Shahpura','Dengue fever - Day 4, Thrombocytopenia','Penicillin','OPD','High','Hindi'],
        ['Vikram Rao',64,'M','+91 90045 11223','91-5567-3390-1102','Kolar Road','COPD GOLD III, Ex-smoker','','Home ICU','High','Hindi'],
        ['Neha Gupta',29,'F','+91 98999 45601','91-7712-6654-3308','Bittan Market','Hypothyroidism, GERD','','OPD','Low','English'],
        ['Suresh Patil',52,'M','+91 97600 22119','91-3345-9987-2201','Ayodhya Bypass','Type 2 Diabetes, Fatty Liver','','OPD','Medium','Marathi'],
        ['Lata Verma',71,'F','+91 93011 88450','91-6690-1123-7745','M.P. Nagar','CHF NYHA II, Hypertension, CKD Stage 3','NSAIDs','Home Care','High','Hindi'],
        ['Arjun Desai',41,'M','+91 95555 30017','91-4408-7761-9930','Hoshangabad Rd','Acid peptic disease','','OPD','Low','English'],
        ['Mohan Tiwari',78,'M','+91 94250 66710','91-9921-4432-6650','Govindpura','Post-stroke hemiparesis, Bedridden','','Home Care','High','Hindi'],
    ];
    $st = $pdo->prepare('INSERT INTO patients(name,age,sex,phone,abha,city,conditions,allergies,care,risk,lang)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($patients as $p) $st->execute($p);

    $today = date('Y-m-d');
    $appts = [
        [3,'19:00','Follow Up (2)','In-clinic','Dengue day 4 - platelet recheck','Waiting','A-01'],
        [1,'19:15','Follow Up (1)','In-clinic','Diabetes review + HbA1c report','Waiting','A-02'],
        [7,'19:30','Follow Up (3)','In-clinic','BP high on home log','Waiting','A-03'],
        [6,'19:45','Follow Up (2)','In-clinic','Sugar control, LFT report','Waiting','A-04'],
        [5,'20:00','New','In-clinic','Acidity, thyroid recheck','Waiting','A-05'],
        [2,'20:30','Follow Up (1)','Teleconsult','BP follow-up','Scheduled','T-01'],
        [8,'18:45','Follow Up (1)','In-clinic','Acidity review','Completed','A-00'],
    ];
    $st = $pdo->prepare('INSERT INTO appointments(patient_id,appt_date,appt_time,visit_type,mode,reason,status,token)
                         VALUES(?,?,?,?,?,?,?,?)');
    foreach ($appts as $a) $st->execute([$a[0],$today,$a[1],$a[2],$a[3],$a[4],$a[5],$a[6]]);

    $hc = [
        [4,'Home ICU','C-14, Kolar Road, Bhopal','ICU bed, BiPAP, Oxygen concentrator, Syringe pump',
         'Nurse: Sunita R. (24h), Dr. visit alt-day','2026-08-20',4500,'SpO2 trending 90-92%. BiPAP overnight. Review ABG.'],
        [7,'Home Nursing','R-9, Zone-2, M.P. Nagar, Bhopal','Oxygen concentrator, Hospital bed',
         'Nurse: Priya M. (12h day shift)','2026-07-11',1800,'CHF with CKD. Strict I/O charting. Low salt diet.'],
        [9,'Home Nursing + Physio','H-4, Govindpura, Bhopal','Hospital bed, Air mattress, Walker',
         'Nurse: Kavita S. (24h), Physio: Ajay T. (alt-day)','2026-06-02',2600,'Post-stroke. Neuro physio 5x/wk. Watch for bed sores.'],
    ];
    $st = $pdo->prepare('INSERT INTO homecare(patient_id,service,addr,equipment,staff,started,rate,note)
                         VALUES(?,?,?,?,?,?,?,?)');
    foreach ($hc as $h) $st->execute($h);

    /* Seed the editable WhatsApp templates */
    require_once __DIR__ . '/whatsapp.php';
    $st = $pdo->prepare('INSERT INTO templates(name,lang,body,is_default) VALUES(?,?,?,1)');
    foreach (default_templates() as $lang => $body) {
        $st->execute(["Visit Summary ($lang)", $lang, $body]);
    }
}

/* Starter formulary - the doctor edits this from Drugs, it is only a starting point. */
/* ------------------------------------------------------------------
   Seeds for the new reference tables. These carry across exactly the
   values that used to be hardcoded, so nothing changes on day one —
   but now every one of them is editable in Settings.
   ------------------------------------------------------------------ */
function seed_picklists(PDO $pdo): void {
    $lists = [
        'unit'    => ['tab','cap','ml','puff','sachet','drop','unit','application'],
        'when'    => ['After Food','Before Food','With Food','Empty Stomach','Bedtime','Anytime'],
        'freq'    => ['OD','BD','TDS','QID','Weekly','SOS','STAT'],
        'form'    => ['Tab','Cap','Syp','Inj','Inh','Drops','Cream','Sachet','Gel','Spray'],
        'lang'    => ['English','Hindi','Marathi'],
        'care'    => ['OPD','Home Care','Home ICU'],
        'risk'    => ['Low','Medium','High'],
        'paymode' => ['Cash','UPI','Card','Bank Transfer','Pending'],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO picklists(kind,val,sort) VALUES(?,?,?)');
    foreach ($lists as $kind => $vals) {
        foreach (array_values($vals) as $i => $v) $st->execute([$kind, $v, $i * 10]);
    }
}

function seed_brands(PDO $pdo): void {
    /* Indian brands patients actually say at the desk, mapped to the
       generic so the safety checks still fire when a brand is written. */
    $map = [
        'Crocin'=>'paracetamol','Dolo'=>'paracetamol','Calpol'=>'paracetamol','Metacin'=>'paracetamol',
        'Combiflam'=>'ibuprofen','Brufen'=>'ibuprofen','Ibugesic'=>'ibuprofen',
        'Voveran'=>'diclofenac','Dynapar'=>'diclofenac',
        'Zerodol'=>'aceclofenac','Hifenac'=>'aceclofenac',
        'Pan'=>'pantoprazole','Pantop'=>'pantoprazole','Pantocid'=>'pantoprazole',
        'Omez'=>'omeprazole','Ocid'=>'omeprazole',
        'Razo'=>'rabeprazole','Rablet'=>'rabeprazole',
        'Augmentin'=>'amoxicillin','Clavam'=>'amoxicillin','Mox'=>'amoxicillin','Novamox'=>'amoxicillin',
        'Azee'=>'azithromycin','Azithral'=>'azithromycin','Zithromax'=>'azithromycin',
        'Taxim'=>'cefixime','Zifi'=>'cefixime','Cefi'=>'cefixime',
        'Cifran'=>'ciprofloxacin','Ciplox'=>'ciprofloxacin',
        'Levoflox'=>'levofloxacin','Levotas'=>'levofloxacin',
        'Doxt'=>'doxycycline','Minicycline'=>'doxycycline',
        'Flagyl'=>'metronidazole','Metrogyl'=>'metronidazole',
        'Glycomet'=>'metformin','Glucophage'=>'metformin','Obimet'=>'metformin',
        'Amaryl'=>'glimepiride','Glimestar'=>'glimepiride',
        'Januvia'=>'sitagliptin','Istamet'=>'sitagliptin',
        'Telma'=>'telmisartan','Telsartan'=>'telmisartan',
        'Losar'=>'losartan','Repace'=>'losartan',
        'Amlopres'=>'amlodipine','Amlong'=>'amlodipine','Stamlo'=>'amlodipine',
        'Ecosprin'=>'aspirin','Disprin'=>'aspirin',
        'Atorva'=>'atorvastatin','Storvas'=>'atorvastatin','Lipitor'=>'atorvastatin',
        'Rosuvas'=>'rosuvastatin','Crestor'=>'rosuvastatin',
        'Thyronorm'=>'levothyroxine','Eltroxin'=>'levothyroxine',
        'Allegra'=>'fexofenadine','Cetzine'=>'cetirizine','Alerid'=>'cetirizine',
        'Montair'=>'montelukast','Montek'=>'montelukast',
        'Asthalin'=>'salbutamol','Levolin'=>'levosalbutamol',
        'Deriphyllin'=>'etophylline','Foracort'=>'budesonide','Seroflo'=>'salmeterol',
        'Wysolone'=>'prednisolone','Omnacortil'=>'prednisolone',
        'Zincovit'=>'multivitamin','Becosules'=>'vitamin b complex',
        'Shelcal'=>'calcium','Calcimax'=>'calcium',
        'Orofer'=>'iron','Dexorange'=>'iron','Livogen'=>'iron',
        'Neurobion'=>'vitamin b12','Meganeuron'=>'vitamin b12',
        'Emeset'=>'ondansetron','Vomikind'=>'ondansetron','Perinorm'=>'metoclopramide',
        'Buscopan'=>'hyoscine','Meftal'=>'mefenamic acid','Spasmindon'=>'dicyclomine',
        'Cyclopam'=>'dicyclomine','Rantac'=>'ranitidine','Digene'=>'antacid','Gelusil'=>'antacid',
        'ORS'=>'oral rehydration salts','Electral'=>'oral rehydration salts',
        'Sporlac'=>'probiotic','Vizylac'=>'probiotic',
        'Betadine'=>'povidone iodine','Soframycin'=>'framycetin',
        'Alprax'=>'alprazolam','Restyl'=>'alprazolam','Zolfresh'=>'zolpidem',
        'Nise'=>'nimesulide','Ultracet'=>'tramadol','Tramazac'=>'tramadol',
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO brands(brand,generic) VALUES(?,?)');
    foreach ($map as $b => $g) $st->execute([$b, $g]);
}

function seed_settings(PDO $pdo): void {
    /* Seeded from the CLINIC constant so the app behaves identically
       until the doctor edits something. */
    $c = defined('CLINIC') ? CLINIC : [];
    $vals = [
        'clinic_name'   => $c['name']   ?? 'Dr Bakshi Clinic',
        'clinic_doctor' => $c['doctor'] ?? '',
        'clinic_qual'   => $c['qual']   ?? '',
        'clinic_spec'   => $c['spec']   ?? '',
        'clinic_reg'    => $c['reg']    ?? '',
        'clinic_addr'   => $c['addr']   ?? '',
        'clinic_phone'  => $c['phone']  ?? '',
        'clinic_email'  => $c['email']  ?? '',
        'clinic_hours'  => $c['hours']  ?? '',
        'rx_footer'     => 'This prescription is valid only for the named patient.',
        'default_lang'  => 'English',
        'follow_default'=> '7',
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO settings(skey,sval) VALUES(?,?)');
    foreach ($vals as $k => $v) $st->execute([$k, (string)$v]);
}

function seed_advice(PDO $pdo): void {
    $rows = [
        ['Plenty of fluids','English'], ['Take rest','English'],
        ['Avoid oily and spicy food','English'], ['Light home food only','English'],
        ['Come back immediately if it worsens','English'],
        ['Check sugar daily and keep a record','English'],
        ['Walk 30 minutes daily','English'], ['Steam inhalation twice a day','English'],
        ['Warm salt water gargle','English'],
        ['ज्यादा पानी पिएं','Hindi'], ['आराम करें','Hindi'],
        ['तेल मसाला मत खाइए','Hindi'], ['हालत बिगड़े तो तुरंत आएं','Hindi'],
    ];
    $st = $pdo->prepare('INSERT INTO advice_lines(text,lang) VALUES(?,?)');
    foreach ($rows as $r) $st->execute($r);
}

function seed_drugs(PDO $pdo): void {
    $rows = [
        ['Tab Paracetamol 650mg','paracetamol','Tab','650mg','1','tab','After Food','TDS','3 days','Only if fever above 100F'],
        ['Tab Metformin 500mg','metformin','Tab','500mg','1','tab','After Food','BD','30 days',''],
        ['Tab Metformin 1000mg','metformin','Tab','1000mg','1','tab','After Food','BD','30 days',''],
        ['Tab Glimepiride 1mg','glimepiride','Tab','1mg','1','tab','Before Food','OD','30 days',''],
        ['Tab Amlodipine 5mg','amlodipine','Tab','5mg','1','tab','After Food','OD','30 days',''],
        ['Tab Telmisartan 40mg','telmisartan','Tab','40mg','1','tab','After Food','OD','30 days',''],
        ['Tab Atorvastatin 20mg','atorvastatin','Tab','20mg','1','tab','Bedtime','OD','30 days',''],
        ['Tab Furosemide 40mg','furosemide','Tab','40mg','1','tab','Before Food','OD','15 days','Morning dose only'],
        ['Cap Omeprazole 20mg','omeprazole','Cap','20mg','1','cap','Empty Stomach','OD','14 days',''],
        ['Tab Pantoprazole 40mg','pantoprazole','Tab','40mg','1','tab','Empty Stomach','OD','14 days',''],
        ['Tab Thyronorm 50mcg','levothyroxine','Tab','50mcg','1','tab','Empty Stomach','OD','30 days','Half hour before breakfast'],
        ['Syp Sucralfate','sucralfate','Syp','','10','ml','Before Food','TDS','7 days',''],
        ['Inh Formoterol + Budesonide','formoterol budesonide','Inh','','2','puff','After Food','BD','30 days','Rinse mouth after use'],
        ['Inh Tiotropium 18mcg','tiotropium','Inh','18mcg','1','puff','After Food','OD','30 days',''],
        ['Tab Azithromycin 500mg','azithromycin','Tab','500mg','1','tab','Before Food','OD','3 days',''],
        ['Tab Cefixime 200mg','cefixime','Tab','200mg','1','tab','After Food','BD','5 days',''],
        ['ORS sachets','ors','Sachet','','1','sachet','Anytime','SOS','3 days','Dissolve in 1 litre water'],
        ['Tab Doxycycline 100mg','doxycycline','Tab','100mg','1','tab','After Food','BD','7 days',''],
        ['Tab Aspirin 75mg','aspirin','Tab','75mg','1','tab','After Food','OD','30 days',''],
        ['Tab Clopidogrel 75mg','clopidogrel','Tab','75mg','1','tab','After Food','OD','30 days',''],
    ];
    $st = $pdo->prepare('INSERT INTO drugs(name,generic,form,strength,def_dose,def_unit,def_when,def_freq,def_duration,notes)
                         VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) $st->execute($r);
}

function seed_labs(PDO $pdo): void {
    $rows = [
        ['CBC','Blood'],['Platelet Count','Blood'],['Dengue NS1','Serology'],['Widal','Serology'],
        ['HbA1c','Diabetes'],['Fasting Blood Sugar','Diabetes'],['Post Prandial Sugar','Diabetes'],
        ['Lipid Profile','Blood'],['LFT','Blood'],['KFT','Blood'],['TSH','Hormone'],
        ['Serum Electrolytes','Blood'],['Urine R/M','Urine'],['ECG','Cardiac'],['2D Echo','Cardiac'],
        ['Chest X-ray','Imaging'],['USG Abdomen','Imaging'],['Spirometry','Lung'],
    ];
    $st = $pdo->prepare('INSERT INTO labs(name,grp) VALUES(?,?)');
    foreach ($rows as $r) $st->execute($r);
}
