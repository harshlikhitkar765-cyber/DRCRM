# Database setup — MySQL / MariaDB

The app runs entirely on **MySQL / MariaDB**. There is no database file —
all data lives on the database server, which is what every cheap Indian web
host provides.

---

## 1. Create the database on your host

In cPanel / hPanel: **Databases → MySQL Databases**

1. Create a database — e.g. `drbakshi_clinic`
2. Create a user with a long password
3. **Add the user to the database and tick ALL PRIVILEGES** (this step is
   the one people forget)

The host will prefix things, so your real names often look like
`u123456_clinic` and `u123456_drbakshi`. Use exactly what the panel shows.

## 2. Put the four values in `inc/config.php`

```php
const DB_HOST = 'localhost';        // shared hosts: almost always localhost
const DB_PORT = 3306;
const DB_NAME = 'u123456_clinic';
const DB_USER = 'u123456_drbakshi';
const DB_PASS = 'the-long-password';
```

Nothing else needs editing. On the first page load the app creates all 16
tables by itself.

If the details are wrong you get a plain message saying **"Cannot reach the
database"** and what MySQL complained about — not a blank white page.

## 3. That's it

There is no import step and no data file to upload. The app builds its own
schema on first run.

---

## If you get "Access denied ... to database"

```
SQLSTATE[HY000] [1044] Access denied for user 'u835224156_druser'@'...'
to database ' u835224156_drbd'
```

**Look inside the quotes around the database name.** If there is a space —
or a line break — before the name, that is the whole problem. The value in
`inc/config.php` was pasted out of the control panel and picked up
whitespace:

```php
const DB_NAME = '
u835224156_drbd';                     // wrong — pasted with a line break
const DB_NAME = ' u835224156_drbd';   // wrong — pasted with a space
const DB_NAME = 'u835224156_drbd';    // right
```

A line break is easy to miss because the name still *looks* right on the
next line. MySQL reports it as a space, which sends you hunting for the
wrong thing.

Check `DB_USER` and `DB_PASS` too; the same paste usually spaces those as
well. A space in the password is the nastiest one, because the error just
says "Access denied for user" and gives no clue.

The app now trims these four values automatically, so a stray space no
longer stops it, and the error screen prints each value inside square
brackets and flags any that had spaces around it.

If the name really is correct and it still fails, the user has not been
attached to the database. In cPanel/hPanel: **MySQL Databases → Add user to
database → tick ALL PRIVILEGES**. Creating a user is not the same as giving
it access.

Other messages you might see, and what they mean:

| Message | Cause |
|---|---|
| `[1698] Access denied for user` | Wrong username or password |
| `[1049] Unknown database` | Name does not exist — check the account prefix |
| `could not find driver` | `pdo_mysql` not enabled in PHP |
| `Can't connect` / `Connection refused` | Wrong DB_HOST — usually `localhost` |

---

## What changed under the hood

These are the SQLite→MySQL differences that were dealt with when the app was
converted, kept here in case you ever hand this code to another developer:

| Old (single file) | Now (MySQL) |
|---|---|
| `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT AUTO_INCREMENT PRIMARY KEY` |
| `TEXT` everywhere | `VARCHAR(n)` where indexed, `TEXT`/`MEDIUMTEXT` otherwise |
| `datetime('now','localtime')` | `CURRENT_TIMESTAMP` / `NOW()` |
| `INSERT OR IGNORE` | `INSERT IGNORE` |
| `PRAGMA wal_checkpoint` | not needed |
| backup = copy the file | backup = SQL dump |

**Character set is `utf8mb4` throughout.** This matters: Hindi text and the
emoji in the WhatsApp templates both need it. Plain `utf8` in MySQL is only
3 bytes and silently mangles emoji. Verified: 🏥 and विज़िट सारांश survive a
full backup-and-restore cycle.

**Foreign keys are real now.** Delete a patient and their appointments,
prescriptions, bills and documents go with them (`ON DELETE CASCADE`), which
the old file did not enforce by default.

**MySQL is stricter about dates.** An empty text box used to store as `''`;
MySQL rejects that in a DATE column. There is now a `dnull()` helper in
`inc/config.php` that turns a blank into a proper `NULL`. If you add a new
date field, wrap it: `dnull((string)$_POST['my_date'])`.

---

## Backups are different now

**Backup → Download** now gives you a `.zip` containing a real `.sql` dump
plus the prescription and report images. The dump is produced in pure PHP,
so it works on hosts that block `exec()` and `mysqldump`.

To restore:

```
mysql -u YOUR_USER -p YOUR_DB < clinic-20260905-120249.sql
```

Tested: dumped, restored into an empty database, all rows and all Hindi text
came back intact.

Nightly automatic backup (`crontab -e` on the server):

```
30 22 * * * mysqldump -u USER -p'PASSWORD' DBNAME | gzip > ~/clinic-backups/clinic-$(date +\%Y\%m\%d).sql.gz && find ~/clinic-backups -mtime +30 -delete
```

**Also copy `data/rx` and `data/docs`.** The handwritten prescriptions and
photographed lab reports are files on disk, not database rows. A database
backup alone does not include them.

---

## Requirements

- PHP 8.0+ with **`pdo_mysql`** (on cPanel: Select PHP Version → Extensions)
- MySQL 5.7+ or MariaDB 10.3+
- `php-zip` for zipped backups (without it you still get a plain `.sql`)

## A note on going online

Putting this on a web host means it is reachable from the internet, so the
two warnings from earlier now matter much more than they did on a LAN:

1. **Get HTTPS working** — free with Let's Encrypt, one click in most panels.
   Without it, logins and patient data cross the network in clear text.
2. **Change both demo passwords** before the first real patient.
