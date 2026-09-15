# Deployment

SchoolHub is deployed on **one Windows computer at the school running XAMPP** (Apache,
PHP 8.2, MariaDB). Staff and students reach it over the school network, or from the
internet if the school chooses to publish it, by HTTPS. This document is the as-built
procedure; `php database/check_deployment.php` checks the result.

Tested with XAMPP 8.2.12 (PHP 8.2.12, MariaDB 10.4.32) on Windows 11.

## Security rule
Never deploy with development passwords, demo accounts, database credentials in a
committed file, debug output, or backups reachable from the web. The preflight fails on
each of these.

## Local development
Windows, XAMPP, Git, and a browser. The project lives at `C:\xampp\htdocs\SchoolHub` and
runs at `http://localhost/SchoolHub/public/` with the defaults in `config/config.php`
(`env` local, debug on, MariaDB as `root`). The root `.htaccess` refuses everything
outside `public/` in that layout. See `README.md` for the commands.

---

## 1. The server computer

- Windows 10 or 11 (64-bit), at least 8 GB RAM, an SSD with 20 GB free, on a UPS.
- A **fixed IP address** on the school network (set in the router's DHCP reservations),
  and a name staff can type: either a DNS entry such as `enroll.lyceum.local` on the
  school's router or DNS server, or the school's public domain.
- Power settings: never sleep, never hibernate. Windows Update active hours outside
  enrollment hours.
- A Windows account with a strong password; nobody uses this computer for browsing.
- A second disk, NAS share, or USB drive for off-machine backup copies (step 9).

## 2. Install XAMPP and the application

1. Install XAMPP for Windows (PHP 8.2) to `C:\xampp`. Only Apache, MySQL, and PHP are
   needed; untick FileZilla, Mercury, Tomcat, Perl, and Webalizer.
2. Put SchoolHub **outside htdocs**, at `C:\SchoolHub` (clone the repository or copy
   the release folder). Everything below uses that path.
   - Include `public/assets/app.css` (it is committed). `tailwindcss.exe` is not needed
     on the server.
3. Delete XAMPP's sample site so nothing else is served: the contents of
   `C:\xampp\htdocs` except `index.php` may go (`dashboard\`, `img\`, `webalizer\`,
   `xampp\`).

## 3. MariaDB

1. Check XAMPP's own tables first. A damaged `mysql.db` (seen on a development copy of
   XAMPP) makes `GRANT` fail with *"Read page with wrong checksum"*:
   ```bat
   C:\xampp\mysql\bin\mysqlcheck -u root --check mysql
   ```
   If a table is reported corrupt, run `--repair` instead of `--check` before
   continuing.
2. **Only this computer may connect.** In `C:\xampp\mysql\bin\my.ini`, under `[mysqld]`,
   uncomment:
   ```ini
   bind-address="127.0.0.1"
   ```
   XAMPP ships with this line commented out, so MariaDB listens on the network.
3. Restart MySQL, then give `root` a password (XAMPP's is empty):
   ```bat
   C:\xampp\mysql\bin\mysqladmin -u root password
   ```
   Also set it in `C:\xampp\phpMyAdmin\config.inc.php` (`$cfg['Servers'][$i]['auth_type'] = 'cookie';`)
   if phpMyAdmin will be used. XAMPP already restricts phpMyAdmin to this computer
   (`Require local`).
4. Create the database and a **dedicated application account** with rights on that
   database only (`C:\xampp\mysql\bin\mysql -u root -p`):
   ```sql
   CREATE DATABASE schoolhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'schoolhub_app'@'localhost' IDENTIFIED BY 'a long random password';
   CREATE USER 'schoolhub_app'@'127.0.0.1' IDENTIFIED BY 'the same password';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
       ON schoolhub.* TO 'schoolhub_app'@'localhost';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
       ON schoolhub.* TO 'schoolhub_app'@'127.0.0.1';
   ```
   These are what the code uses: reads and writes for the application; CREATE, ALTER,
   DROP, INDEX, and REFERENCES for `migrate.php` and `restore.php`. Backups need only
   SELECT (`SHOW CREATE TABLE` and a consistent snapshot); no LOCK TABLES, FILE, triggers,
   or views are used. The two host entries cover `localhost` and `127.0.0.1`
   connections. The preflight fails if the account has any server-wide privilege.

## 4. Configuration

Copy `config/config.local.example.php` to `config/config.local.php` (never committed) and
set:

```php
return [
    'app' => [
        'env'      => 'production',
        'debug'    => false,
        'base_url' => 'https://enroll.lyceum.local/',   // the address people type
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'database' => 'schoolhub',
        'username' => 'schoolhub_app',
        'password' => 'the password from step 3',
    ],
    'session' => [
        'secure' => true,   // the sign-in cookie only travels over HTTPS
    ],
];
```

- `base_url` is used for password set-up and reset links and as the cookie path. A full
  `https://` address is expected; only its path is used inside the site, so the site
  may be at the domain root or in a folder (`https://lyceum.edu.ph/enroll/`).
- In production the `SCHOOLHUB_DATABASE` override is ignored and error details are never
  shown; they go to `storage/logs/php-error.log`, visible to the Super Admin in System
  Health.
- `session.secure` also makes SchoolHub send `Strict-Transport-Security` for one year.
  Turn it on only once the certificate (step 6) is trusted by the school's computers:
  after that, browsers refuse to open the site over plain HTTP or with a certificate
  error.

## 5. php.ini

XAMPP's Apache and the command line both read `C:\xampp\php\php.ini`. Set:

```ini
display_errors = Off
log_errors = On
expose_php = Off
session.use_strict_mode = 1
upload_max_filesize = 8M      ; SchoolHub accepts files up to 5 MB
post_max_size = 10M           ; must exceed the upload limit
memory_limit = 256M
date.timezone = Asia/Manila
```

`extension=zip`, `fileinfo`, `openssl`, `pdo_mysql`, `mbstring`, and `curl` must be
enabled (they are in XAMPP 8.2).

## 6. Apache: HTTPS site with DocumentRoot at `public/`

Only `C:\SchoolHub\public` is served. Everything else (configuration, storage, backups,
the repository) sits above the web root and cannot be requested at all.

1. **Certificate.**
   - School network only: create one with `C:\xampp\apache\makecert.bat` (Common Name =
     the server name, e.g. `enroll.lyceum.local`), then install `server.crt` as a
     *Trusted Root Certification Authority* on the school's computers (Group Policy,
     or once per computer). Students' own phones will show a warning unless they install it.
   - Published on the internet with a real domain: use a free Let's Encrypt certificate
     obtained with win-acme, which also renews it on a schedule.
2. In `C:\xampp\apache\conf\extra\httpd-ssl.conf`, edit the existing
   `<VirtualHost _default_:443>`:
   ```apache
   DocumentRoot "C:/SchoolHub/public"
   ServerName enroll.lyceum.local:443
   SSLCertificateFile "conf/ssl.crt/server.crt"
   SSLCertificateKeyFile "conf/ssl.key/server.key"

   <Directory "C:/SchoolHub/public">
       Options -Indexes +FollowSymLinks
       AllowOverride All
       Require all granted
   </Directory>
   ```
   `AllowOverride All` lets `public/.htaccess` route every page to `index.php`.
3. In `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, send plain HTTP to HTTPS:
   ```apache
   <VirtualHost *:80>
       ServerName enroll.lyceum.local
       Redirect permanent / https://enroll.lyceum.local/
   </VirtualHost>
   ```
   Being the first (only) port-80 site, it also answers requests by IP address.
4. In `C:\xampp\apache\conf\httpd.conf`, hide version details:
   ```apache
   ServerTokens Prod
   ServerSignature Off
   ```
5. Restart Apache from the XAMPP Control Panel and check the Apache error log if it does
   not start (a typo in a path is the usual cause).

## 7. Windows services and firewall

1. Run the XAMPP Control Panel **as administrator** and tick **Svc** for Apache and MySQL.
   Both then start with Windows, before anyone signs in. Set both services to
   *Automatic* with recovery "Restart the service" in `services.msc`.
2. Windows Defender Firewall, inbound rules: allow TCP **443** (and 80, which only
   redirects) on the *Domain/Private* profile. Do **not** open 3306. If the site is
   published on the internet, forward only 443 (and 80) on the school router.
3. The Apache service runs as *Local System*, which can write to `C:\SchoolHub\storage`.
   If it runs as another account, give that account Modify rights on `storage\`.

## 8. Database schema, reference data, and the first administrator

From `C:\SchoolHub` in a command prompt:

```bat
C:\xampp\php\php.exe database\migrate.php
C:\xampp\php\php.exe database\seed.php
C:\xampp\php\php.exe database\import_curricula.php --check
C:\xampp\php\php.exe database\import_curricula.php
C:\xampp\php\php.exe database\create_admin.php
```

- `create_admin.php` asks for the password; nothing is stored in a file.
- **Never run `create_demo_users.php`** (it refuses in production). If the database was
  copied from a test machine, disable the `student`, `teacher`, `registrar`, `cashier`
  and any `*@schoolhub.test` accounts in Administration → Users.
- Sign in as the Super Admin and complete **System Settings** (school name, logo,
  contact details, enrollment and payment policy, backup retention), create staff
  accounts in **Administration → Users** (each receives a one-time set-up link), and
  have the cashier replace the sample fee amounts in **Fees**.

## 9. Scheduled tasks

Create these with Task Scheduler, or from an administrator command prompt. Both run as
SYSTEM, whether or not anyone is signed in.

```bat
schtasks /Create /TN "SchoolHub\Nightly backup" /SC DAILY /ST 02:00 /RU SYSTEM /RL HIGHEST ^
  /TR "C:\xampp\php\php.exe C:\SchoolHub\database\backup.php --scheduled"

schtasks /Create /TN "SchoolHub\Deliver announcements" /SC MINUTE /MO 5 /RU SYSTEM ^
  /TR "C:\xampp\php\php.exe C:\SchoolHub\database\dispatch_announcements.php"
```

- The backup contains the database and (per System Settings) uploaded files, is
  checksummed, and exits with status 1 on failure, which Task Scheduler shows as the
  last run result. Retention removes the oldest beyond the configured count. A failed
  or missing backup appears in **System Health**.
- **Keep a copy off this computer.** A backup on the same disk does not survive a disk
  failure or theft. Copy the folder nightly to the second disk or NAS:
  ```bat
  schtasks /Create /TN "SchoolHub\Copy backups" /SC DAILY /ST 03:00 /RU SYSTEM ^
    /TR "robocopy C:\SchoolHub\storage\backups \\nas\schoolhub-backups *.zip /XO /R:2 /W:30"
  ```
  (`/RU SYSTEM` reaches a network share only if the share allows the server's computer
  account; otherwise run that task as a service account with access.)
- Announcements are also delivered whenever someone loads a portal page; the task makes
  scheduled ones go out on time on quiet days.

## 10. Preflight and acceptance

```bat
C:\xampp\php\php.exe database\check_deployment.php --url=https://enroll.lyceum.local/
```

Add `--insecure` while testing a self-signed certificate from a computer that does not
trust it yet. The script checks, and exits with status 1 on any failure:

| Group | Checks |
|---|---|
| Configuration | `config.local.php` present; `env` production; debug off; `base_url` is https; secure cookie; not `root`; database password set; idle sign-out ≤ 1 hour |
| PHP settings | upload and post size limits; `display_errors`, `expose_php`, `session.use_strict_mode` in php.ini |
| MariaDB | listens on 127.0.0.1 only; the application account has no server-wide privileges |
| Accounts and data | an active Super Admin; no demo, test, or performance-test accounts; roles seeded; fee amounts no longer the samples |
| Files | the three `.htaccess` files; only `index.php` in `public/`; compiled stylesheet present |
| Server, database, storage, backups | PHP version and extensions; connection; pending migrations; storage writable; free disk space; latest backup |
| Live site (`--url`) | sign-in page loads; security headers; no debug headers; session cookie `Secure; HttpOnly; SameSite`; HSTS; HTTP redirects to HTTPS; 14 private files refused under the site address and every folder above it |

Then, before opening enrollment:

1. Run a manual backup from **Administration → Backups** and verify it; restore it once
   on a spare computer (`php database/restore.php <file>`) to prove the backup is usable.
2. Sign in as each role with a real staff account and walk the acceptance scenarios in
   docs/22 (a student registers, applies, uploads, pays online; the registrar approves;
   the cashier verifies; the student is enrolled; a teacher records attendance and a
   grade).
3. From another computer on the network, open `http://<server>/` (redirects),
   `https://<server>/` (works), and confirm `https://<server>:3306` and phpMyAdmin are
   unreachable.
4. Review **System Health** and **Audit Logs**.

## 11. Updating to a new version

1. Announce a short maintenance window; make a manual backup.
2. Replace the application files (`git pull`, or copy the new release over
   `C:\SchoolHub`, keeping `config\config.local.php` and `storage\`).
3. `C:\xampp\php\php.exe database\migrate.php`, then `database\seed.php` (adds new
   permissions and settings; never removes or overwrites).
4. Run the preflight with `--url`. If anything is wrong, restore the backup and put the
   previous files back.

## 12. Recovery

- **Restore** is command-line only: `php database/restore.php <backup.zip>`. It verifies
  the archive, asks you to type the file name, makes a PRE_RESTORE safety backup, then
  replaces the database and uploaded files (the old uploads folder is kept beside the
  new one). Run `migrate.php` afterwards if the backup came from an older version.
- **Server lost:** install a new computer with steps 1–7, copy `config.local.php` from a
  secure record (or recreate it), restore the latest off-machine backup, and run the
  preflight.
- **Forgotten Super Admin password:** another Super Admin sends a reset link from
  Administration → Users; if there is none, run `create_admin.php` to add one.
