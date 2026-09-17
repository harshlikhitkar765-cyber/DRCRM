# Uploading to Hostinger

## 1. Upload the application safely

Upload the application files to `public_html`, including `.htaccess` and the
`data/.htaccess` file. Do **not** upload generated patient files from another
installation (`data/rx/`, `data/docs/`) or any existing `data/secret.key`.

The repository deliberately contains **no live database or Meta credentials**.
After upload, create a private configuration file on the host:

1. In Hostinger File Manager, open `public_html/data/`.
2. Copy `config.local.php.example` to `config.local.php`.
3. Edit `config.local.php` with the database values shown in hPanel, a
   canonical HTTPS `APP_URL` (required for QR/image links), and long, unique
   `INITIAL_DOCTOR_USERNAME` / `INITIAL_DOCTOR_PASSWORD` credentials.
4. Save it. `data/.htaccess` denies browser access to this folder; never place
   the same values in a public PHP file or Git repository.

Environment variables named `DRCRM_DB_HOST`, `DRCRM_DB_PORT`,
`DRCRM_DB_NAME`, `DRCRM_DB_USER`, `DRCRM_DB_PASS`, `DRCRM_APP_URL` and
`DRCRM_INITIAL_DOCTOR_*` can be used instead if your plan supports them.

## 2. DB_HOST — check this one

Hostinger commonly uses `localhost` for `DB_HOST`; some plans show a dedicated
host name in hPanel. Use exactly what hPanel shows. `localhost` and
`127.0.0.1` are not always interchangeable there:

- `localhost` connects through a Unix socket
- `127.0.0.1` connects over TCP

If the app reports **“Cannot reach the database”**, check the database name,
username, password and assigned privileges in hPanel → Databases.

## 3. First run

Open the site after the private config is in place. The app creates all
**22 tables**, the message templates, lists and your configured doctor account
on the first request. A production database starts with no sample patients.

For a disposable private demonstration only, set `DEMO_MODE` to `1`. This
creates sample data and enables the known demo accounts. Never enable it on a
public site or with real patient information.

## 4. Before entering real patient data

1. **Turn on HTTPS.** hPanel → Security → SSL → install Let’s Encrypt, then
   enable Force HTTPS. It protects sign-in and patient data, and browsers will
   not permit voice recording on plain HTTP.
2. **Keep the `data/` folder protected.** Confirm `data/.htaccess` was
   uploaded. It contains the signing key, private config and patient images.
3. **Use consent before WhatsApp.** The app now blocks prescription and recall
   sends until WhatsApp consent is recorded for that patient.
4. **Set Meta secrets for Cloud API.** When using Cloud API, put both
   `WA_APP_SECRET` and `WA_VERIFY_TOKEN` in the private config. Webhook posts
   without Meta’s valid `X-Hub-Signature-256` are rejected.
5. **Back up every evening.** Backup → Download includes the database plus
   prescription and report images.

## Troubleshooting

- **“No initial user yet”** — set `INITIAL_DOCTOR_USERNAME` and
  `INITIAL_DOCTOR_PASSWORD` in `data/config.local.php`, then reload.
- **“Cannot reach the database”** — verify all four database values and that
  the MySQL user has all privileges on the selected database.
- **Voice recording is disabled** — open the site through `https://` in Chrome
  or Edge. Brave and Firefox intentionally do not provide this transcription
  route; typed notes still work.
