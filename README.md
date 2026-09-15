# SchoolHub
## Integrated School Enrollment and Management System

**For:** Lyceum of the East Aurora (college / tertiary)
**Status:** Release candidate. All eight roadmap phases are built and tested; awaiting
acceptance on the school's server
**Version:** 1.0.0-rc.1
**Stack:** PHP 8.2, MariaDB, Tailwind CSS (standalone binary), vanilla JavaScript, Apache on XAMPP (Windows)
**Dependencies:** none. No Composer, no npm, no Node

## What it does
SchoolHub runs a college's enrollment and records in one place:

- **Public website:** programs, announcements, enrollment information, and student registration.
- **Students:** profile, an enrollment application with subject selection from their
  curriculum, requirement uploads, status tracking, online payment with proof, receipts and
  statements, schedule, attendance, grades, academic history, announcements, and notifications.
- **Registrar:** the application queue, document review, approval with a section, automatic
  confirmation once payment policy is met, student records, programs, curricula, subjects,
  sections, rooms, classes with conflict checks, enlistment adjustments, grading periods, and
  reports (enrollment list, applicants by status, requirements, enrollment history; print and CSV).
- **Teachers:** their own classes, rosters, attendance, Prelim/Midterm/Finals grades, and class reports (class list, attendance summary, grade sheet; print and CSV).
- **Cashier:** fee schedule, assessments, payment verification and window payments,
  official receipts, adjustments and reversals, collections and outstanding-balance reports
  (print and CSV).
- **Announcements:** written by the registrar or Super Admin for everyone or for roles,
  programs, and year levels; scheduled, urgent banners, one notification per recipient.
- **Super Admin:** staff accounts with one-time password links, role permissions, system
  settings, the audit log and sign-in activity, backups, system health, and administrative
  reports (account review, sign-in and activity summaries, system usage).

Every protected action is authorized on the server, every form is CSRF-protected, every
change that matters is written to an append-only audit log, and financial records are
corrected by adjustment or reversal, never edited or deleted.

## Roles
Student · Teacher · Registrar · Cashier · Super Admin (one role per account)

## Run it locally (Windows + XAMPP)

1. Clone into `C:\xampp\htdocs\SchoolHub` and start Apache and MySQL in XAMPP.
2. Copy `config/config.local.example.php` to `config/config.local.php` (the defaults suit XAMPP).
3. Set up the database (PHP is at `C:\xampp\php\php.exe`):
   ```bash
   php database/migrate.php
   php database/seed.php
   php database/import_curricula.php
   php database/create_admin.php          # asks for the Super Admin's details and password
   php database/create_demo_users.php     # optional: student / teacher / registrar / cashier test accounts
   ```
4. Open **http://localhost/SchoolHub/public/**.

The compiled stylesheet is committed. To change styles, download the Tailwind standalone
binary as `tailwindcss.exe` into the project root and run
`./tailwindcss.exe -i src/input.css -o public/assets/app.css --watch`.

## Commands

| Command | Purpose |
|---|---|
| `php database/migrate.php [--status \| --rollback]` | Apply, list, or roll back migrations |
| `php database/seed.php` | Roles, permissions, settings, programs, terms, sample fees (never overwrites) |
| `php database/import_curricula.php [--check]` | The six curricula in `database/seeds/curricula` |
| `php database/create_admin.php` | Create a Super Admin interactively |
| `php database/backup.php [--scheduled \| --verify=ID]` | Back up the database and uploads |
| `php database/restore.php <file>` | Restore a backup (command line only) |
| `php database/dispatch_announcements.php` | Deliver scheduled announcements (Task Scheduler) |
| `php database/check_deployment.php [--url=https://…]` | Deployment preflight |
| `php tests/run.php [--filter=name]` | Test suite (241 tests) |
| `php tests/http/crawl.php` | Every route as every role through Apache (1,824 checks) |

## Deploying
Deployment is on a school Windows computer running XAMPP, served over HTTPS, with the web root
at `public/`, a dedicated MariaDB account, nightly backups, and an off-machine copy. Follow
**`docs/23_DEPLOYMENT.md`**, then run the preflight until it reports no failures.

## Documentation
`docs/` is the authoritative specification, updated alongside the code.

| File | Purpose |
|---|---|
| `CLAUDE.md` | Working rules: stack constraints, architecture, security and domain rules |
| `docs/00_MASTER_SPECIFICATION.md` | Primary authority; section 8 records resolved decisions |
| `docs/04_USER_ROLES_AND_PERMISSIONS.md` | What each role can and cannot do |
| `docs/17_DATABASE_DESIGN.md` | Schema, integrity rules, identifier formats |
| `docs/22_TESTING_AND_ACCEPTANCE.md` | Test levels, results, acceptance scenarios |
| `docs/23_DEPLOYMENT.md` | Windows/XAMPP deployment procedure |
| `docs/24_DEVELOPMENT_ROADMAP.md` | Phase status and what is not in 1.0.0 |
| `docs/26_STATUS_TRANSITIONS.md` | Enrollment status transition matrix |
| `docs/27_GRADING_POLICY.md` | Grading scale and final-grade rule |
| `docs/28_CURRICULA.md` | The school's curricula and open questions |

## Working on the code
Before changing a feature: read the relevant module document, keep permissions enforced on
the server, add tests for the rule and its failure cases, update the document in the same
change, and run the suite (and the crawl for route or layout changes).
