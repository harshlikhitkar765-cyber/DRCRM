# Database tables

The clinic runs on **MySQL / MariaDB**, `utf8mb4`, InnoDB. **21 tables.**

The app creates all of them by itself on first run, so you do not
normally need to do anything. `schema.sql` in this folder is the full
`CREATE TABLE` script if you want to load it through phpMyAdmin or hand
the project to another developer.

## Patients and visits

| Table | Rows now | Holds |
|---|---|---|
| `patients` | 9 | Patient register - name, age, phone, ABHA, conditions, allergies, consent |
| `appointments` | 7 | OPD queue - date, slot, visit type, status, token |
| `prescriptions` | 1 | Every prescription issued - diagnosis, vitals, medicines, labs, advice |
| `consult_notes` | 0 | Recorded consultations - transcript, speaker turns, summary, timing |

## Money and files

| Table | Rows now | Holds |
|---|---|---|
| `payments` | 0 | Billing and day book |
| `documents` | 0 | Photographed lab reports |
| `pad_sessions` | 6 | Phone-pad handwriting sessions (expire after 15 min) |

## Your own lists

| Table | Rows now | Holds |
|---|---|---|
| `drugs` | 20 | Your medicine list with default dose/when/frequency |
| `labs` | 18 | Your test list |
| `rx_sets` | 0 | Favourite prescription sets |
| `diagnoses` | 0 | Diagnoses you write often (learns by use) |
| `advice_lines` | 13 | Standard advice, per language |
| `brands` | 102 | Indian brand to generic map, for safety checks |
| `picklists` | 46 | Every dropdown in the app |

## Messaging

| Table | Rows now | Holds |
|---|---|---|
| `templates` | 3 | WhatsApp message wording per language |
| `wa_messages` | 0 | WhatsApp send log |
| `wa_replies` | 0 | Patient replies received |

## System

| Table | Rows now | Holds |
|---|---|---|
| `users` | 2 | Logins with hashed passwords |
| `audit` | 63 | Who did what, and when |
| `settings` | 12 | Clinic details and preferences |
| `homecare` | 3 | Home care and Home ICU patients |

## Columns

### `patients`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `name` | varchar(120) | indexed, required |
| `age` | int(11) |  |
| `sex` | varchar(10) |  |
| `phone` | varchar(30) | indexed, required |
| `abha` | varchar(40) |  |
| `city` | varchar(80) |  |
| `conditions` | text |  |
| `allergies` | text |  |
| `care` | varchar(30) | default OPD |
| `risk` | varchar(20) | default Low |
| `lang` | varchar(20) | default English |
| `wa_consent` | tinyint(4) | default 0 |
| `consent_at` | datetime |  |
| `created_at` | datetime | default current_timestamp() |

### `appointments`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed, required |
| `appt_date` | date | indexed, required |
| `appt_time` | varchar(10) | required |
| `visit_type` | varchar(40) | default New |
| `mode` | varchar(30) | default In-clinic |
| `reason` | text |  |
| `status` | varchar(20) | default Waiting |
| `token` | varchar(20) |  |

### `prescriptions`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed, required |
| `rx_date` | date | indexed, required |
| `diagnosis` | text |  |
| `vitals` | text |  |
| `meds` | text |  |
| `labs` | text |  |
| `advice` | text |  |
| `follow_up` | varchar(20) |  |
| `ink_file` | varchar(255) |  |
| `ink_mode` | tinyint(4) | default 0 |
| `created_at` | datetime | default current_timestamp() |

### `consult_notes`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed, required |
| `appt_id` | int(11) |  |
| `rx_id` | int(11) | indexed |
| `transcript` | mediumtext |  |
| `turns` | mediumtext |  |
| `summary` | mediumtext |  |
| `ended_at` | datetime |  |
| `secs` | int(11) | default 0 |
| `disease` | varchar(200) | indexed |
| `dr_points` | mediumtext |  |
| `pt_points` | mediumtext |  |
| `lang` | varchar(20) | default en-IN |
| `picked` | text |  |
| `started_at` | datetime |  |
| `created_at` | datetime | default current_timestamp() |

### `payments`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed, required |
| `rx_id` | int(11) | indexed |
| `pay_date` | date | indexed, required |
| `item` | varchar(160) |  |
| `amount` | int(11) | required, default 0 |
| `paid` | tinyint(4) | required, default 0 |
| `mode` | varchar(20) | default Cash |
| `note` | text |  |
| `created_at` | datetime | default current_timestamp() |

### `documents`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed, required |
| `kind` | varchar(40) | default Report |
| `title` | varchar(200) |  |
| `file` | varchar(255) | required |
| `doc_date` | date |  |
| `created_at` | datetime | default current_timestamp() |

### `pad_sessions`

| Column | Type | Notes |
|---|---|---|
| `token` | varchar(64) | primary key |
| `patient_id` | int(11) | indexed, required |
| `appt_id` | int(11) |  |
| `mode` | varchar(20) | default write |
| `status` | varchar(20) | default waiting |
| `result_file` | varchar(255) |  |
| `result_kind` | varchar(20) |  |
| `created_at` | datetime | default current_timestamp() |
| `expires_at` | datetime | required |

### `drugs`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `name` | varchar(160) | indexed, required |
| `generic` | varchar(120) | indexed |
| `form` | varchar(30) | default Tab |
| `strength` | varchar(40) |  |
| `def_dose` | varchar(20) | default 1 |
| `def_unit` | varchar(20) | default tab |
| `def_when` | varchar(30) | default After Food |
| `def_freq` | varchar(20) | default OD |
| `def_duration` | varchar(30) | default 5 days |
| `notes` | text |  |
| `uses` | int(11) | default 0 |
| `active` | tinyint(4) | default 1 |

### `labs`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `name` | varchar(120) | unique, required |
| `grp` | varchar(60) | default General |
| `uses` | int(11) | default 0 |
| `active` | tinyint(4) | default 1 |

### `rx_sets`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `name` | varchar(160) | required |
| `diagnosis` | text |  |
| `meds` | text |  |
| `labs` | text |  |
| `advice` | text |  |
| `uses` | int(11) | default 0 |

### `diagnoses`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `name` | varchar(160) | unique, required |
| `icd` | varchar(20) |  |
| `uses` | int(11) | default 0 |
| `active` | tinyint(4) | default 1 |

### `advice_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `text` | varchar(240) | required |
| `lang` | varchar(20) | indexed, default English |
| `uses` | int(11) | default 0 |
| `active` | tinyint(4) | default 1 |

### `brands`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `brand` | varchar(80) | unique, required |
| `generic` | varchar(80) | indexed, required |
| `active` | tinyint(4) | default 1 |

### `picklists`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `kind` | varchar(30) | indexed, required |
| `val` | varchar(80) | required |
| `sort` | int(11) | default 0 |
| `active` | tinyint(4) | default 1 |

### `templates`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `name` | varchar(120) | required |
| `lang` | varchar(20) | required, default English |
| `body` | mediumtext | required |
| `is_default` | tinyint(4) | default 0 |

### `wa_messages`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed, required |
| `rx_id` | int(11) | indexed |
| `phone` | varchar(30) |  |
| `lang` | varchar(20) |  |
| `body` | mediumtext |  |
| `driver` | varchar(20) |  |
| `status` | varchar(20) | default Queued |
| `response` | text |  |
| `sent_at` | datetime | default current_timestamp() |

### `wa_replies`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed |
| `phone` | varchar(30) |  |
| `body` | text |  |
| `intent` | varchar(30) |  |
| `handled` | tinyint(4) | default 0 |
| `received_at` | datetime | default current_timestamp() |

### `users`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `username` | varchar(60) | unique, required |
| `pass_hash` | varchar(255) | required |
| `name` | varchar(120) |  |
| `role` | varchar(20) | default staff |
| `active` | tinyint(4) | default 1 |
| `created_at` | datetime | default current_timestamp() |

### `audit`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `username` | varchar(60) |  |
| `action` | varchar(60) |  |
| `entity` | varchar(40) |  |
| `entity_id` | int(11) |  |
| `detail` | text |  |
| `ip` | varchar(45) |  |
| `at` | datetime | indexed, default current_timestamp() |

### `settings`

| Column | Type | Notes |
|---|---|---|
| `skey` | varchar(60) | primary key |
| `sval` | text |  |
| `updated_at` | datetime | on update current_timestamp(), default current_timestamp() |

### `homecare`

| Column | Type | Notes |
|---|---|---|
| `id` | int(11) | primary key, auto_increment |
| `patient_id` | int(11) | indexed, required |
| `service` | varchar(60) |  |
| `addr` | text |  |
| `equipment` | text |  |
| `staff` | text |  |
| `started` | date |  |
| `rate` | int(11) |  |
| `note` | text |  |
| `status` | varchar(20) | default Active |

## Foreign keys

Deleting a patient removes their appointments, prescriptions, recorded
consultations, bills and documents with them, so no orphan records are
left behind. Verified by test.

| From | To | On delete |
|---|---|---|
| `appointments.patient_id` | `patients.id` | CASCADE |
| `consult_notes.patient_id` | `patients.id` | CASCADE |
| `consult_notes.rx_id` | `prescriptions.id` | SET NULL |
| `documents.patient_id` | `patients.id` | CASCADE |
| `homecare.patient_id` | `patients.id` | CASCADE |
| `pad_sessions.patient_id` | `patients.id` | CASCADE |
| `payments.patient_id` | `patients.id` | CASCADE |
| `payments.rx_id` | `prescriptions.id` | SET NULL |
| `prescriptions.patient_id` | `patients.id` | CASCADE |
| `wa_messages.patient_id` | `patients.id` | CASCADE |
| `wa_messages.rx_id` | `prescriptions.id` | SET NULL |
| `wa_replies.patient_id` | `patients.id` | SET NULL |
