# Deployment

SchoolHub runs on any server with **PHP 8.2 or newer, MySQL 8 or MariaDB 10.4 or newer, and
Apache**. This document is the as-built procedure for the three supported ways to put it online,
and `php database/check_deployment.php` checks the result:

| | Best for | You manage |
|---|---|---|
| **A. Shared hosting** (cPanel, Hostinger hPanel, and similar) | Most schools: low cost, HTTPS and email included | Files, database, settings, cron jobs from a web panel |
| **B. Linux server (VPS)** | Many users, full control | The whole server: updates, firewall, Apache, MariaDB |
| **C. The school's own Windows computer** (XAMPP) | School network only, no hosting budget | The computer, power, internet connection |

Tested with PHP 8.2.12 and MariaDB 10.4.32 on Windows 11 (XAMPP), and with the application's
database session set to MySQL 8's strict SQL mode, as it is on every server (see §0.4).

## Security rule
Never deploy with development passwords, demo accounts, database credentials in a committed file,
debug output, the development mail log, or backups reachable from the web. The preflight fails on
each of these.

## Local development
Windows, XAMPP, Git, and a browser. The project lives at `C:\xampp\htdocs\SchoolHub` and runs at
`http://localhost/SchoolHub/public/` with the defaults in `config/config.php` (`env` local, debug
on, MariaDB as `root`, email written to `storage/logs/mail.log`). The root `.htaccess` refuses
everything outside `public/` in that layout. See `README.md` for the commands.

---

## 0. Before any deployment

### 0.1 What you need

- **An address** such as `enroll.lyceum.edu.ph`: a subdomain of the school's domain, or a new domain.
- **HTTPS.** Hosting panels include a free certificate (AutoSSL or Let's Encrypt). SchoolHub refuses
  to email password links, and the preflight fails, without an `https://` address.
- **A mailbox for outgoing mail**, such as `no-reply@lyceum.edu.ph`, with its SMTP server name, port,
  and password. It sends "Forgot password?" links and account set-up links (§0.3).
- **Storage** for uploaded requirements: plan about 3 MB per applicant (a 2,000-student year is
  about 6 GB), plus room for backups.

### 0.2 Build the release (on a development computer)

```bash
./tailwindcss.exe -i src/input.css -o public/assets/app.css --minify   # only if the CSS changed
php tests/run.php                                                        # everything passes
php database/build_release.php
```

This writes `build/schoolhub-<version>.zip` with two folders:

```
schoolhub/      the application: app, config, database, routes, an empty storage,
                deploy/ templates, DEPLOYMENT.md (this file). Never inside the web folder.
public_html/    the web folder: index.php, .htaccess, assets.
```

Local settings, uploads, logs, backups, tests, development documents, and the Tailwind binary are
left out; the script refuses to package them. `public_html/index.php` finds the application in a
folder named `schoolhub` beside the web folder, or up to two levels above it (for a subdomain
folder such as `public_html/enroll`).

Hosting **without a command line** (no SSH or Terminal in the panel) also needs the database built
here, as an importable file:

```bash
php database/build_install_sql.php --username=admin --email=you@lyceum.edu.ph --first=Maria --last=Santos
```

It builds a throwaway database with every migration, the reference data, and the curricula, adds
the first Super Admin with a **temporary password printed once** (changed at the first sign-in),
writes `build/schoolhub-install.sql`, and drops the throwaway database. The schema comes from the
migration files, so the file imports into MySQL 8 or MariaDB alike. Delete it after importing.

### 0.3 Production settings

On the server, copy `schoolhub/deploy/config.production.php` to `schoolhub/config/config.local.php`
and replace every `<...>` value. That file holds the passwords: it is never committed, never in the
web folder, and readable only by the account that runs PHP (`chmod 600` or `640`).

| Setting | Why |
|---|---|
| `app.env` `production`, `app.debug` `false` | Error details go to `storage/logs/php-error.log`, never to visitors. `SCHOOLHUB_DATABASE` is ignored. |
| `app.base_url` `https://…` | Password links are built from it, never from the Host header a visitor sends. Only its path is used inside the site, so the site can be at a domain root or in a folder. In production, a plain-HTTP request is redirected to this address (301; a form post gets 308). |
| `app.trusted_proxies` | Only when visitors reach the server through Cloudflare or the host's load balancer. Without it, every visitor appears to come from the proxy's address, and one person's failed sign-ins would block the whole school (docs/07). List the proxy ranges, for example Cloudflare's published ranges from `https://www.cloudflare.com/ips/`. Never `0.0.0.0/0`: the preflight fails on it. **How to tell:** if Super Admin → Login Activity shows the same few addresses for everyone, a proxy is in front. |
| `app.public_path` | Shared hosting only, for the command-line scripts: the full path of `public_html`. Web requests find it on their own. |
| `db.*` | A dedicated account for this database only, with a long random password. Never `root`. |
| `session.secure` `true` | The sign-in cookie travels only over HTTPS; SchoolHub also sends `Strict-Transport-Security` for one year. Turn it on once HTTPS works. |
| `mail.*` | See below. |

**Email.** `mail.driver` is `smtp` in production (`log` only writes to a file and fails the preflight;
`none` turns "Forgot password?" off and only warns). Settings for common providers:

| Provider | host | port / encryption | username / password |
|---|---|---|---|
| Hostinger | `smtp.hostinger.com` | 587 / `tls` or 465 / `ssl` | the mailbox address and its password |
| cPanel hosting | `mail.<your domain>` (shown in *Email Accounts → Connect Devices*) | 465 / `ssl` or 587 / `tls` | the mailbox address and its password |
| Google Workspace | `smtp.gmail.com` | 587 / `tls` | the account address and an **app password** (2-step verification on) |
| Microsoft 365 | `smtp.office365.com` | 587 / `tls` | the mailbox address and its password (SMTP AUTH enabled for that mailbox) |

`from_address` must be a real mailbox on the school's domain, and the domain should have **SPF and
DKIM** turned on in the panel (usually one click), or messages land in spam. Test with
`php database/send_test_email.php you@example.com`; without a command line, have the Super Admin
issue a link for their own account and press **Email the link**.

### 0.4 Database compatibility

Every connection sets MySQL 8's strict SQL mode (`ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`,
`NO_ZERO_DATE`, …) and the application's time zone (`+08:00`), so the same queries run on XAMPP's
MariaDB and a provider's MySQL 8, and `CURRENT_TIMESTAMP` agrees with PHP even on a server that
runs on UTC. No MySQL-reserved word is used as a table or column name. `tests/portability_test.php`
also fails if any class, template, or file is referenced with a different letter case than its file
name, which Windows forgives and Linux does not.

---

## A. Shared hosting (cPanel, hPanel)

Choose a plan with PHP 8.2+, MySQL/MariaDB, cron jobs, free SSL, email accounts, and ideally SSH or
a Terminal. The folder names below are cPanel's and Hostinger's; others are similar.

1. **Address and HTTPS.** Add the domain or subdomain in the panel and turn on SSL (cPanel:
   *SSL/TLS Status → Run AutoSSL*; hPanel: *Security → SSL*). Wait until `https://` opens without a
   warning.
2. **PHP version and settings.** Select PHP 8.2 or newer (*MultiPHP Manager* / *PHP Configuration*)
   with the extensions `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `zip`, and `curl`. Upload
   `schoolhub/deploy/user.ini` as `.user.ini` in the web folder (upload and post size, errors hidden,
   strict sessions), or set the same values in the panel.
3. **Upload the release.** In *File Manager*, open the **home folder** (the one that *contains*
   `public_html`), upload the zip, and *Extract*. This creates `~/schoolhub` and merges
   `public_html/` into the existing web folder; delete the host's placeholder `index.html` or
   `default.php` there. For a subdomain with its own folder (`public_html/enroll`), move the three
   extracted web files (`index.php`, `.htaccess`, `assets/`) into that folder instead.
   The application must **not** end up inside `public_html`.
4. **Database.** *MySQL Databases* (cPanel) or *Databases → Management* (hPanel): create a database
   and a user with a long random password, and add the user to the database with *All Privileges*
   (that grant covers only this database). The panel prefixes both names with the account name.
5. **Settings.** Copy `schoolhub/deploy/config.production.php` to `schoolhub/config/config.local.php`
   (§0.3), set `app.public_path` to the full path of the web folder (shown in File Manager, for
   example `/home/u123456/public_html`), and set the file's permissions to `600`.
6. **Tables and the first administrator.**
   - With SSH or a Terminal:
     ```bash
     cd ~/schoolhub
     php database/migrate.php && php database/seed.php
     php database/import_curricula.php --check && php database/import_curricula.php
     php database/create_admin.php
     ```
   - Without: open *phpMyAdmin*, select the new database, *Import* `schoolhub-install.sql` from
     §0.2, then delete the file from your computer. Sign in with the printed temporary password.
7. **Permissions.** Folders `755`, files `644`, `config/config.local.php` `600`. PHP runs as your
   account on shared hosting, so `storage/` needs no special rights; if uploads fail, set
   `storage/uploads`, `storage/logs`, and `storage/backups` to `750`.
8. **Scheduled tasks.** *Cron Jobs*: add the two jobs in `schoolhub/deploy/crontab.txt` (announcements
   every 5 minutes, backup nightly), using the PHP path the panel shows.
9. **Email.** Create the mailbox, turn on SPF and DKIM, fill in `mail.*`, and test it (§0.3).
10. **Preflight** (§D), then the acceptance steps in §E.

Updates: §F. The panel's own daily backups are a useful extra, but SchoolHub's backups (step 8) are
what `restore.php` reads.

## B. Linux server (Ubuntu 24.04 VPS)

1. **Server.** Create the VPS with an SSH key, point the domain's `A` record at it, then:
   ```bash
   sudo apt update && sudo apt upgrade -y
   sudo apt install -y apache2 mariadb-server php8.3 libapache2-mod-php8.3 php8.3-mysql php8.3-mbstring \
       php8.3-zip php8.3-curl php8.3-xml unzip certbot python3-certbot-apache unattended-upgrades
   sudo ufw allow OpenSSH && sudo ufw allow 'Apache Full' && sudo ufw enable
   ```
   Do not open port 3306.
2. **MariaDB.** `sudo mariadb-secure-installation` (Ubuntu's MariaDB already listens on 127.0.0.1
   only), then `sudo mariadb` and create the database and account as in §C.3 step 4.
3. **Files.**
   ```bash
   sudo mkdir -p /var/www/lyceum && cd /var/www/lyceum
   sudo unzip ~/schoolhub-<version>.zip
   sudo chown -R root:www-data schoolhub public_html
   sudo chown -R www-data:www-data schoolhub/storage
   sudo find schoolhub public_html -type d -exec chmod 750 {} \; && sudo find schoolhub public_html -type f -exec chmod 640 {} \;
   ```
4. **Settings.** `sudo cp schoolhub/deploy/config.production.php schoolhub/config/config.local.php`,
   edit it (§0.3), `sudo chown root:www-data` and `chmod 640` it.
5. **php.ini.** In both `/etc/php/8.3/apache2/php.ini` and `/etc/php/8.3/cli/php.ini`: the values in
   §C.5 (`expose_php = Off` included).
6. **Apache and HTTPS.** Install `schoolhub/deploy/apache-vhost.conf` as described at its top; it
   serves only `public_html`, denies the application folder, and redirects HTTP to HTTPS. Then
   `sudo certbot --apache -d <address>`; certbot renews the certificate by itself.
7. **Tables and the first administrator**, as the web server's account so it owns what it creates:
   ```bash
   cd /var/www/lyceum/schoolhub
   sudo -u www-data php database/migrate.php && sudo -u www-data php database/seed.php
   sudo -u www-data php database/import_curricula.php
   sudo -u www-data php database/create_admin.php
   ```
8. **Scheduled tasks.** `sudo crontab -u www-data -e` and paste `deploy/crontab.txt` with the paths
   changed to `/var/www/lyceum/schoolhub` and `/usr/bin/php`. Running them as `www-data` matters:
   uploads and backups are created with mode `750`.
9. **Off-server backup copy.** For example a nightly `rclone copy` of `storage/backups` to cloud
   storage, or a weekly download from Super Admin → Backups.
10. **Email** (§0.3), **preflight** (§D), **acceptance** (§E).

## C. The school's own Windows computer (XAMPP)

Staff and students reach it over the school network, or from the internet if the school publishes
it with HTTPS.

### C.1 The server computer

- Windows 10 or 11 (64-bit), at least 8 GB RAM, an SSD with 20 GB free, on a UPS.
- A **fixed IP address** on the school network (set in the router's DHCP reservations),
  and a name staff can type: either a DNS entry such as `enroll.lyceum.local` on the
  school's router or DNS server, or the school's public domain.
- Power settings: never sleep, never hibernate. Windows Update active hours outside
  enrollment hours.
- A Windows account with a strong password; nobody uses this computer for browsing.
- A second disk, NAS share, or USB drive for off-machine backup copies (C.9).

### C.2 Install XAMPP and the application

1. Install XAMPP for Windows (PHP 8.2) to `C:\xampp`. Only Apache, MySQL, and PHP are
   needed; untick FileZilla, Mercury, Tomcat, Perl, and Webalizer.
2. Put SchoolHub **outside htdocs**, at `C:\SchoolHub` (clone the repository, or unzip the
   release and use its `schoolhub` folder with `public_html` renamed to `C:\SchoolHub\public`).
   Everything below uses that path.
   - Include `public/assets/app.css` (it is committed). `tailwindcss.exe` is not needed
     on the server.
3. Delete XAMPP's sample site so nothing else is served: the contents of
   `C:\xampp\htdocs` except `index.php` may go (`dashboard\`, `img\`, `webalizer\`,
   `xampp\`).

### C.3 MariaDB

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

### C.4 Configuration

Copy `deploy/config.production.php` to `config/config.local.php` (§0.3). On a school network
without a public domain, `base_url` is the internal HTTPS name (`https://enroll.lyceum.local/`).
`session.secure` sends `Strict-Transport-Security` for a year: turn it on only once the
certificate (C.6) is trusted by the school's computers, since browsers then refuse the site over
plain HTTP or with a certificate error.

### C.5 php.ini

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

### C.6 Apache: HTTPS site with DocumentRoot at `public/`

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

### C.7 Windows services and firewall

1. Run the XAMPP Control Panel **as administrator** and tick **Svc** for Apache and MySQL.
   Both then start with Windows, before anyone signs in. Set both services to
   *Automatic* with recovery "Restart the service" in `services.msc`.
2. Windows Defender Firewall, inbound rules: allow TCP **443** (and 80, which only
   redirects) on the *Domain/Private* profile. Do **not** open 3306. If the site is
   published on the internet, forward only 443 (and 80) on the school router.
3. The Apache service runs as *Local System*, which can write to `C:\SchoolHub\storage`.
   If it runs as another account, give that account Modify rights on `storage\`.

### C.8 Database schema, reference data, and the first administrator

From `C:\SchoolHub` in a command prompt:

```bat
C:\xampp\php\php.exe database\migrate.php
C:\xampp\php\php.exe database\seed.php
C:\xampp\php\php.exe database\import_curricula.php --check
C:\xampp\php\php.exe database\import_curricula.php
C:\xampp\php\php.exe database\create_admin.php
```

`create_admin.php` asks for the password; nothing is stored in a file.

### C.9 Scheduled tasks

Create these with Task Scheduler, or from an administrator command prompt. Both run as
SYSTEM, whether or not anyone is signed in.

```bat
schtasks /Create /TN "SchoolHub\Nightly backup" /SC DAILY /ST 02:00 /RU SYSTEM /RL HIGHEST ^
  /TR "C:\xampp\php\php.exe C:\SchoolHub\database\backup.php --scheduled"

schtasks /Create /TN "SchoolHub\Deliver announcements" /SC MINUTE /MO 5 /RU SYSTEM ^
  /TR "C:\xampp\php\php.exe C:\SchoolHub\database\dispatch_announcements.php"
```

**Keep a copy off this computer.** A backup on the same disk does not survive a disk failure or
theft. Copy the folder nightly to the second disk or NAS:
```bat
schtasks /Create /TN "SchoolHub\Copy backups" /SC DAILY /ST 03:00 /RU SYSTEM ^
  /TR "robocopy C:\SchoolHub\storage\backups \\nas\schoolhub-backups *.zip /XO /R:2 /W:30"
```
(`/RU SYSTEM` reaches a network share only if the share allows the server's computer
account; otherwise run that task as a service account with access.)

---

## D. Preflight

```bash
php database/check_deployment.php --url=https://enroll.lyceum.edu.ph/
```

Add `--insecure` while testing a self-signed certificate from a computer that does not trust it
yet. On shared hosting without a command line, add a one-time cron job with the same command and
`> ~/schoolhub/storage/logs/preflight.txt 2>&1`, read the file in File Manager, then remove the job.
The script exits with status 1 on any failure:

| Group | Checks |
|---|---|
| Configuration | `config.local.php` present; `env` production; debug off; `base_url` is https; secure cookie; not `root`; database password set; idle sign-out ≤ 1 hour; trusted proxies never "everyone" |
| Email | driver is `smtp` (off only warns; `log` fails); server set; TLS or SSL unless a relay on the same server; certificate checked; signs in; a real sender address, not a sample |
| PHP settings | upload and post size limits; `display_errors`, `expose_php`, `session.use_strict_mode` |
| Database server | listens only locally (a separate provider-managed server is accepted with a strong dedicated account; a network-listening local one only warns when the account is strong); the account has no server-wide privileges |
| Accounts and data | an active Super Admin; no demo, test, or performance-test accounts; roles seeded; fee amounts no longer the samples |
| Files | the web folder found (also when moved out of the application folder); the `.htaccess` files; only `index.php` in the web folder; compiled stylesheet present |
| Server, database, storage, backups | PHP version and extensions; connection; pending migrations; storage writable; free disk space; latest backup |
| Live site (`--url`) | sign-in page loads; security headers; no debug headers; session cookie `Secure; HttpOnly; SameSite`; HSTS; HTTP redirects to HTTPS; 15 private files refused under the site address and every folder above it |

The release was verified end to end in the shared-hosting layout: unzipped as `schoolhub/` and
`public_html/`, the database imported from `build_install_sql.php`'s file with the `mysql` client,
served with only `public_html` as the web root in production mode. The front controller found the
application, the stylesheet and logo loaded, `/bootstrap.php`, `/config/config.local.php`, and the
storage log were 404, no debug headers were sent, the first sign-in went straight to *change
password*, "Forgot password?" gave the same answer for a known and an unknown address and refused
to build a link without an `https://` address, and the preflight found the moved web folder.

## D.1 Web-server hardening

The preflight's live checks also flag server exposure that comes from the web server, not from
SchoolHub. Turn these off on the deployed server:

- **Apache version banner.** `ServerTokens Prod` and `ServerSignature Off` (in `httpd.conf` on
  XAMPP, or `/etc/apache2/conf-available/security.conf` on Ubuntu) so error pages and the `Server`
  header do not name the exact Apache, OpenSSL, and PHP versions. Also set `expose_php = Off` in
  php.ini.
- **Status and info pages.** XAMPP's `mod_status` (`/server-status`) and `mod_info`
  (`/server-info`) list every request and the whole configuration. On XAMPP they are opened to the
  local machine in `httpd-xampp.conf` — remove those `<Location>` blocks, or restrict them to a
  trusted address, and do not `LoadModule` `mod_info` in production. On a fresh Ubuntu Apache they
  are already limited to localhost.
- **TRACE method.** `TraceEnable Off` at the top of `httpd.conf`, so the server does not echo
  requests back (cross-site tracing).
- **Directory listings** are already off via `Options -Indexes` in the vhost and `.htaccess`.

Shared hosting usually handles all of these; the preflight tells you if one is still open.

**PHP version.** The preflight and System Health warn when PHP is older than **8.2.19** (or
**8.3.7**), the first releases with the fix for CVE-2024-3096, where a NUL byte truncated a password
before bcrypt. SchoolHub already refuses passwords that contain a NUL or other control byte, but
keep PHP on the latest 8.2 or 8.3 patch anyway. XAMPP 8.2.12 is older than this; use a current XAMPP
or update PHP.

## E. After installing: before opening enrollment

1. **Never run `create_demo_users.php`** (it refuses in production). If the database came from a
   test machine, disable the `student`, `teacher`, `registrar`, `cashier` and any `*@schoolhub.test`
   accounts in Administration → Users.
2. Sign in as the Super Admin and change the temporary password if the install file was used.
   Complete **System Settings** (school name, logo, contact details, enrollment and payment policy,
   payment instructions, backup retention), create staff accounts in **Administration → Users** and
   **email** each one its set-up link, and have the cashier replace the sample fee amounts in **Fees**.
3. Run a manual backup from **Administration → Backups**, verify it, download it, and restore it once
   on a development computer (`php database/restore.php <file>`) to prove it is usable.
4. Sign in as each role with a real staff account and walk the acceptance scenarios in docs/22 (a
   student registers, applies, uploads, pays online; the registrar approves; the cashier verifies;
   the student is enrolled; a teacher records attendance and a grade). Try "Forgot password?" with a
   real mailbox and check the message arrives, not in spam.
5. From a phone on mobile data: `http://<address>/` redirects to HTTPS, `https://<address>/` works,
   and `https://<address>/schoolhub/config/config.local.php`, `/bootstrap.php`, and
   `/storage/logs/php-error.log` are refused. The preflight's live checks do the same.
6. Review **System Health** and **Audit Logs**.

## F. Updating to a new version

1. Announce a short maintenance window; make a manual backup and download it.
2. Build the new release (§0.2). Replace the application files **except** `config/config.local.php`
   and `storage/`: on hosting, extract the new zip into a temporary folder, then replace
   `schoolhub/app`, `schoolhub/database`, `schoolhub/routes`, `schoolhub/deploy`,
   `schoolhub/bootstrap.php`, `schoolhub/config/config.php`, and the web folder's `index.php`,
   `.htaccess`, and `assets/`. With Git: `git pull`.
3. `php database/migrate.php`, then `php database/seed.php` (adds new permissions and settings;
   never removes or overwrites). Without a command line, ask for the upgrade SQL to be prepared on a
   development computer, or use the panel's Terminal.
4. Run the preflight with `--url`. If anything is wrong, restore the backup and put the previous
   files back.

### F.1 Updating the InfinityFree site (no command line)

The school's site runs on InfinityFree, which has no SSH, no Terminal, and no cron. Nothing is deployed
from Git: an update is **one SQL file pasted into phpMyAdmin, then one zip extracted into `htdocs/`**.
The server layout is `htdocs/{assets, schoolhub, index.php, .htaccess, .user.ini}`, with the application
in `htdocs/schoolhub/`.

**Never overwrite or delete** `htdocs/schoolhub/config/config.local.php` (the database login) or
`htdocs/schoolhub/storage/` (uploaded documents). The update zip contains neither, so extracting over the
site leaves them alone; deleting the `schoolhub` folder first would destroy both.

**On the development computer**
1. `php tests/run.php` and `php tests/http/crawl.php` pass.
2. Build the CSS: `./tailwindcss.exe -i src/input.css -o public/assets/app.css --minify`.
3. Write the database file `build/deploy-db-<first>-<last>.sql` (the `build/` folder is git-ignored), if
   there are new migrations in `database/migrations/` since the last deploy. It must contain:
   - each new migration's statements, written to be **safe to run twice**: `ADD COLUMN IF NOT EXISTS`,
     `CREATE TABLE IF NOT EXISTS`, and foreign keys added only when `information_schema` says they are
     missing (a `PREPARE` guarded by a count);
   - the **starting data** those migrations need, because `seed.php` cannot be run there: new permissions
     and their `role_permissions`, new reference rows, new accounts. Replace only untouched starter rows;
     never overwrite what the school has edited;
   - `INSERT IGNORE INTO migrations (filename, batch, applied_at) VALUES (...)` for each migration
     (the filename column is unique).
4. **Test the SQL before the school sees it.** Create a scratch database, build it with `migrate.php` and
   `seed.php`, wind it back to the live state using the `-- @down` sections of the new migrations, run the
   file twice, and compare the result with the development database (columns, foreign keys, reference
   rows). Then run the new feature tests against the scratch database, which needs
   `php database/import_curricula.php` too. Drop the scratch database.
5. Build the zip: `php database/build_infinityfree_update.php` writes
   `build/schoolhub-infinityfree-update-<date>.zip` (assets and the application, without
   `config.local.php`, `storage/`, `index.php`, `.htaccess`, or `.user.ini`).

**On the server, in this order: database first, code second**
1. In phpMyAdmin, **Export** the database and keep the file.
2. Check the starting point: `SELECT filename FROM migrations WHERE filename >= '0..' ORDER BY filename;`
   (use the first new number minus a few). It shows what is already applied.
3. Paste the SQL file into the SQL tab and run it. It must finish without an error; if it stops, do not
   edit it by hand, copy the error and the statement it stopped at.
4. Run the same query again: every new migration should be listed.
5. In the file manager, upload the zip into `htdocs/`, extract it with **overwrite**, then delete the zip
   (it holds the whole application).
6. Hard-refresh the browser (Ctrl+F5) so the new CSS and fonts load, then check the new pages for the
   roles that use them.

The old code runs fine against the new database (the changes only add), but the new code does not run
against the old database, which is why the database always goes first.

**Going back:** restore the phpMyAdmin export, and extract the previous update zip (keep the last one in
`build/`).

## G. Recovery

- **Restore** is command-line only: `php database/restore.php <backup.zip>`. It verifies the
  archive, asks you to type the file name, makes a PRE_RESTORE safety backup, then replaces the
  database and uploaded files (the old uploads folder is kept beside the new one). Run
  `migrate.php` afterwards if the backup came from an older version. On hosting without a command
  line, restore on a development computer and move the result with an install-style import.
- **Server or hosting account lost:** set up a new one with §A, §B, or §C, recreate
  `config.local.php` from a secure record, restore the latest off-server backup, and run the
  preflight.
- **Forgotten password:** "Forgot password?" on the sign-in page, or another Super Admin issues and
  emails a link from Administration → Users. With no Super Admin able to sign in, run
  `create_admin.php` to add one.
