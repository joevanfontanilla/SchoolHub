# Backup, Recovery, and System Settings

## Backup
The system should support:
- Manual backup
- Scheduled backup
- Backup history
- Backup verification
- Restore process

Recommended initial schedule:
- Daily at 02:00 AM

## Restore
Restore must be restricted to authorized administration and should require deliberate confirmation.

## Backup Security
Backups may contain sensitive information. They must be protected from public access.

## System Settings
- School name
- Logo
- Address/contact information
- Academic year
- Current term
- Enrollment configuration
- Requirement configuration
- Fee configuration
- Notification configuration
- Security settings
- Theme/UI settings

## System Health
Potential indicators:
- PHP version
- Database connection
- Storage
- Backup status
- Application version
- Recent errors

---

## As built (Phase 7)

### Backups (`BackupService`)
- **What:** one ZIP archive per backup in `storage/backups` (never web-reachable) holding
  `database.sql` (every table, written by PHP through PDO inside one consistent snapshot;
  no `mysqldump` needed), `manifest.json` (version, time, row count per table, SHA-256 of the
  SQL), and, when `backup_include_uploads` is on, every uploaded file under `uploads/`.
  Session rows are not kept.
- **Integrity:** the archive's SHA-256 is recorded in `backups`. **Verify** re-checks the
  file checksum, the SQL checksum against the manifest, every table's `CREATE TABLE`, and that
  no entry path can escape its folder.
- **Manual:** Backups → Create backup, or `php database/backup.php`.
- **Scheduled:** Windows Task Scheduler, daily at 02:00, runs
  `php database/backup.php --scheduled` (the exact command is shown on the Backups screen).
  It exits with status 1 on failure. One backup runs at a time (a database lock).
- **History:** type (Manual, Scheduled, Before a restore), status (Running, Completed, Failed,
  File deleted), size, tables, files, checksum, who, notes, last verification, and any error.
- **Retention:** after each backup, completed manual and scheduled backups beyond
  `backup_retention_count` (default 14) have their files removed; records stay.
- **Download and delete** require the Super Admin to re-enter their password; both, and any
  refused attempt, are audited.

### Restore: command line only
Restoring replaces the entire database and all uploaded files, so it is **not available
from the website**. On the server:

    php database/restore.php <backup file name, or a path to a downloaded archive>

It verifies the archive (and its recorded checksum when the file is in the history), shows
what it contains, asks for the backup's file name to be typed, and makes a **safety backup**
(type Before a restore) first. It then drops and recreates every table, replays the SQL,
swaps in the uploaded files (the previous folder is kept as
`storage/uploads.before-restore-*`), re-adds the safety backup to the restored history, and
audits `backup.restored`. Run `php database/migrate.php` afterwards if the backup came from an
older version. The test suite restores a real backup into a scratch database and compares
every table.

### System settings (`SettingsService`)
Grouped screens: **School profile** (name, tagline, address, email, phone, office hours,
logo), **Enrollment** (payment policy, minimum down payment, enrollment page message),
**Academic** (maximum units per term), **Finance** (payment instructions), **Security** (see
docs/07), and **Backups** (include uploads, retention). Each value has its own rule; a save
writes only what changed and audits each change with its old and new value. Text that is not
valid UTF-8 is refused. The logo (JPG/PNG, up to 1 MB, checked like every upload) is stored in
`storage/uploads/branding` and served at `/branding/logo`; with none, the built-in seal is
used. Terms, enrollment windows, fees, and requirements keep their own registrar and cashier
screens.

### System health (`SystemHealth`)
OK / Check / Problem for: application version and environment; debug mode (a **problem** in
production); secure session cookie (a **check** in production without it); PHP version and
required extensions (pdo_mysql, mbstring, fileinfo, openssl, zip); database connection and
pending migrations; whether `storage/uploads`, `storage/backups`, and `storage/logs` are
writable; free disk space; the latest backup (a check when older than a day) and a failed last
attempt; failed sign-ins in 24 hours; locked accounts; audit write failures in the last 7 days.
The screen also shows the last lines of `storage/logs/php-error.log`. The Super Admin dashboard
shows anything that is not OK.

### Not built
Theme settings and notification settings (nothing yet reads them), email or off-site copies
of backups, and restoring a single table or file.
