# Uploading to Hostinger

## Files to upload

Upload everything in this folder to `public_html` **except**:

| Do NOT upload | Why |
|---|---|
| `data/config.local.php` | Sandbox-only database override. If this reaches the host, the app will try to connect to a database that does not exist there. |
| `data/clinic.sqlite` | Already gone — no longer used. |

Everything else goes up as it is. `inc/config.php` already contains your live
credentials, so there is nothing to edit after uploading.

## DB_HOST — check this one

Your config currently says:

```php
const DB_HOST = '127.0.0.1';
```

On Hostinger this is **usually** `localhost`, not `127.0.0.1`. They are not
interchangeable in MySQL:

- `localhost` connects through a Unix socket
- `127.0.0.1` connects over TCP

Many shared hosts allow only one of them. If you get **"Access denied"** or
**"Can't connect"** after uploading, change that single line to:

```php
const DB_HOST = 'localhost';
```

Hostinger sometimes shows a dedicated hostname instead (like
`mysql.hostinger.in`) on the database page in hPanel. If it does, use exactly
what is shown there.

## First run

Open the site. The app creates all 16 tables and seeds the demo data on the
first page load. No import step, nothing to run by hand.

Log in with `drbakshi` / `clinic@2026`.

## Do these two things before real patients

1. **Turn on HTTPS.** Free in hPanel (Security → SSL → install Let's Encrypt,
   then Force HTTPS). Two reasons, both serious:
   - Without it, logins and patient data cross the internet in clear text.
   - **Voice recording will not work at all on plain http.** Browsers block
     the microphone on insecure pages and report it as a confusing network
     error. The app now detects this and says so, but the only real fix is
     the certificate.

2. **Change both passwords.** `clinic@2026` and `front@2026` are in this
   README and in your config file. Anyone who finds your URL can log in as
   you until you change them.

Also worth doing soon: the database password `dr@bhopal@DR1` has been typed
into a chat window, so rotate it in hPanel → Databases once the site is
working.

## Protecting the config file

`inc/config.php` holds your database password. It sits inside `inc/`, which
nothing links to, but on Apache you can block it outright by putting this in
`public_html/inc/.htaccess`:

```
Require all denied
```

That makes the folder unreachable from a browser while PHP can still include
from it.
