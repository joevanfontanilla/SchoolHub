# CLAUDE.md

Guidance for Claude Code (claude.ai/code) when working in this repository.

## What this is

SchoolHub is an **Integrated School Enrollment and Management System** for a
**college / tertiary** institution: student registration, enrollment applications,
document requirements, registrar processing, academic management, teacher
attendance/grades, cashier fees and payments, announcements, notifications,
reports, audit logs, and system administration.

Stack: **plain PHP 8.2 + MySQL/MariaDB + Tailwind CSS + vanilla JS on Apache/XAMPP.**
Server-rendered. No SPA framework.

## Commands

```bash
# PHP is not on PATH — XAMPP's binary is at C:\xampp\php\php.exe
php database/migrate.php            # apply pending migrations (idempotent)
php database/migrate.php --status   # show applied / pending
php database/seed.php               # roles, permissions, settings, programs, terms (idempotent)
php database/import_curricula.php   # the six curricula from database/seeds/curricula (skips existing)
php database/import_curricula.php --check   # validate the curriculum files; writes nothing
php database/dispatch_announcements.php     # deliver scheduled announcements now (safe to repeat)
php database/create_admin.php       # interactively create the first Super Admin
php database/backup.php             # backup: database + uploads (--scheduled for Task Scheduler, --verify=ID)
php database/restore.php <file>     # restore a backup: command line only, typed confirmation, safety backup first
php database/check_deployment.php --url=https://…/   # deployment preflight (docs/23); exit 1 on any FAIL
php database/build_release.php      # build/schoolhub-<version>.zip: schoolhub/ + public_html/ for a server
php database/build_install_sql.php --username= --email= --first= --last=   # importable install SQL (hosting without SSH)
php database/send_test_email.php you@example.com    # check the mail settings (log driver writes storage/logs/mail.log)

php tests/run.php                   # full test suite (hand-rolled runner, no PHPUnit)
php tests/run.php --filter=auth     # a single test group
php tests/http/crawl.php            # every route × every role through Apache: status, headers, XSS, injection, CSRF, a11y
# performance pass with 2,000 students: see docs/22 §4 (tests/perf, SCHOOLHUB_DATABASE=schoolhub_perf)

# CSS — Tailwind standalone CLI (tailwindcss.exe in the project root, gitignored)
./tailwindcss.exe -i src/input.css -o public/assets/app.css --watch
./tailwindcss.exe -i src/input.css -o public/assets/app.css --minify   # production
```

Serve with XAMPP Apache: the project lives at `C:\xampp\htdocs\SchoolHub` and runs at
**http://localhost/SchoolHub/public/** (the bare `/SchoolHub/` redirects there).

Two `.htaccess` files make that work — do not remove either:

- `public/.htaccess` rewrites every non-file request to `index.php`. Without it, every
  route except the home page is a 404 under Apache.
- The root `.htaccess` refuses everything outside `public/`. Because the whole project
  sits inside htdocs, removing it makes `.git/`, `docs/`, `config/`, and
  `tailwindcss.exe` downloadable.

**Verify under Apache, not only `php -S`.** The PHP built-in server hides real
differences: it routes everything to `index.php` on its own, and it passes through
non-standard status codes that Apache rewrites to 500. Use only registered HTTP status
codes — a CSRF failure is `403`, never Laravel's `419`.

## Hard constraints

### Zero dependencies — no Composer, no npm
`docs/00` forbids Laravel, React, Vue, and Node.js. That is honored literally:

- **No `composer.json`, no `vendor/`, no `package.json`, no `node_modules/`.**
- Tailwind is compiled by the **standalone `tailwindcss.exe` binary**, which bundles
  its own runtime and needs no Node install.
- Router, view rendering, validation, CSRF, sessions, the migration runner, and the
  test runner are **hand-written** in `app/Core/`. Reuse them; do not add a library.
- Receipts and printable reports are **HTML + print CSS** (browser "Save as PDF"),
  not a PDF library. Spreadsheet export uses native `fputcsv`.

Do not add a dependency or change the stack without explicit authorization, and
amend `docs/00_MASTER_SPECIFICATION.md` in the same change if authorization is given.

### The app runs in a subdirectory — never hardcode absolute URLs
SchoolHub is served from a subpath (e.g. `http://localhost/SchoolHub/public/`), not a
domain root. **Every** URL must go through the helpers:

```php
url('/registrar/applications')     // NOT "/registrar/applications"
asset('assets/app.css')            // NOT "/assets/app.css"
```

This applies to inline JavaScript too — pass URLs in from PHP via `url()` or a
`data-` attribute. A hardcoded leading-slash path silently breaks every link.

## Architecture

**Request flow:** `public/index.php` (the only web-reachable PHP file)
→ `routes/web.php` → Middleware → Controller (thin) → Service (business rules)
→ Model (PDO, prepared statements) → View.

```
public/            front controller + compiled assets; the ONLY public directory
app/Core/          Router, Request, Response, View, Database, Validator, Csrf, Session, Auth
app/Controllers/   request handling only — no business logic, no SQL
app/Services/      business workflows, transactions, audit writes
app/Models/        database access (prepared statements) and entities
app/Middleware/    RequireAuth, RequireGuest, RequireRole, RequirePermission, VerifyCsrf
app/Views/         PHP templates: layouts/, partials/, components/, and per-module folders
app/Helpers/       url(), asset(), e(), old(), flash(), money(), dt(); icons.php
config/            config.php (committed) + config.local.php (gitignored secrets)
routes/            web.php
database/          migrations/, seeds/, lib/, and the migrate/seed/backup/restore/preflight/release scripts
deploy/            production templates: config.production.php, crontab.txt, user.ini, apache-vhost.conf
storage/           uploads, logs, backups — deny-all, never web-reachable
tests/             run.php + *_test.php; http/crawl.php; perf/ (dataset generator + timing)
src/input.css      Tailwind source
```

Keep controllers thin, SQL out of controllers and views (`tests/security_test.php` enforces it).
Security headers (CSP `script-src 'self'`, so no inline scripts) are sent by `Response::securityHeaders()`.
With `app.debug` on outside production, pages carry `X-Debug-Queries` and `X-Debug-Time-Ms`; a list,
report, or dashboard should stay well under 60 statements (docs/22 §4). Multi-table workflows (enrollment
approval, payment recording, class assignment) run inside a **transaction** and write
an **audit log** entry.

## Security rules (non-negotiable, from docs/00 and docs/07)

- **Authorize on the backend for every protected action.** A hidden menu item is not
  authorization. Enforce in middleware **and** scope the query — a student reads only
  their own records; a teacher only their assigned classes.
- Prepared statements only. Never concatenate user input into SQL.
- `password_hash()` / `password_verify()`. Never store or log a plaintext password.
- CSRF token verified on every state-changing request (POST/PUT/PATCH/DELETE).
- Escape all output with `e()`.
- Uploads: validate extension **and** MIME **and** size; generate a new storage
  filename (never trust the original); store under `storage/`, never under `public/`;
  serve only through an authorizing controller route that streams the file.
- Secrets live in `config/config.local.php`, which is gitignored.
- The visitor's address is `Request::ip()` and HTTPS is `Request::isSecure()`: forwarded headers count only
  from `app.trusted_proxies`. Never read `REMOTE_ADDR`, `X-Forwarded-For`, or `HTTPS` directly.
- Email goes only through `App\Core\Mailer` (hand-written SMTP, no library, not `mail()`). Links in email use
  `absolute_url()`, which needs `app.base_url` in production; never build one from the Host header.
- Log security- and money-sensitive actions to `audit_logs`. Audit logs are
  **append-only — including for Super Admin.**

## Key domain rules

- **Academic structure:** Academic Year → Semester → Program → Year Level → Section →
  Subject (with **units** and prerequisites) → Class Schedule → Teacher → Students.
- **Three independent status axes** on an enrollment application — `application_status`,
  `payment_status`, and `enrollment_state`. They are *not* one enum; an application can
  be `APPROVED` and `PARTIALLY_PAID` at the same time. Allowed transitions, permitted
  actors, and preconditions are defined in **`docs/26_STATUS_TRANSITIONS.md`** — follow
  that matrix exactly and record every transition in `enrollment_status_history`.
- **Only `EnrollmentStatusService::transition()` changes `application_status`.** It holds
  the docs/26 matrix as data, locks the row, re-checks role/remarks/preconditions, and
  writes history + audit. Never `UPDATE` a status column from a controller or another
  service. (Tests may force a state directly only as fixture setup.)
- **`payment_status` is derived** from assessments and payments. Never set it by hand.
- **Ownership returns 404, not 403.** Student-facing lookups go through
  `EnrollmentApplicationService::ownedApplication()` / `EnrollmentApplication::findForStudent()`,
  so another student's record simply doesn't exist. Roles that may never see a thing at
  all (a teacher opening a student document) get 403.
- **Uploads go through `DocumentUploadService`**, which checks extension, `finfo` MIME, and
  magic bytes, stores under a random name, and supersedes instead of deleting. Files are
  served only via `/documents/{id}` and `DocumentAccessPolicy`.
- **Enrollment confirmation goes only through `EnrollmentConfirmationService::confirm()`.**
  It is the one place a student number is issued, an `enrollments` row is created, and
  `enrollment_state` becomes `ENROLLED` — all in one transaction, so a refused
  confirmation (payment policy, full section, wrong section) leaves no trace and no gap
  in the number sequence. Payment readiness is `PaymentPolicy::problem()`.
- **Registrar routes** are `role:Registrar` **plus** a specific `can:` permission, and
  `tests/registrar_access_test.php` walks the real route table to enforce it. Super Admin
  is intentionally refused. Document reviews go through `RequirementReviewService`; catalog
  changes through `CatalogService` (prerequisite cycles and capacity floors are enforced
  there). The registrar may correct a student's locked legal name via
  `StudentRecordService`, audited by field name only.
- **Curricula (docs/28).** Subjects belong to a curriculum; **a subject code is unique within its
  curriculum, not school-wide** (`CORE 1` means different subjects in BSA and BPA), so never
  look a subject up by code alone. A subject's curriculum never changes, and prerequisites
  come from the same curriculum or the general subjects. The six school curricula are data
  files imported by `CurriculumImporter`, which never overwrites; the open questions for the
  registrar are listed in docs/28.
- **Classes (docs/13).** A class is `class_schedules` (section + subject + teacher) plus its weekly
  `class_meetings`; one class meeting on two days is still one class. Build and close classes only
  through `ClassScheduleService`, which refuses teacher, room, section, and student overlaps.
  **Only `EnlistmentService` writes `class_enlistments`**: block enlistment at confirmation, and
  registrar add/drop. Prerequisite, progress, and load problems are overridable with a reason;
  a time conflict never is. Drops keep the row. The unit limit is `EnlistmentService::unitLimit()`.
- **Grades (docs/27).** All grade arithmetic is in `GradeCalculator`, and the final grade is computed,
  never stored. Teachers record grades through `GradingService` and attendance through
  `AttendanceService`; both find the class with `GradingService::ownedClass()`, so another
  teacher's class is a **404**. Only the registrar opens and closes grading periods, and the
  registrar never enters grades.
- **Enrollment flow (docs/09).** Students choose subjects on the application through
  `SubjectSelectionService` (only their program curriculum's subjects for the year and term;
  locked when a prerequisite is unmet). The registrar approves with a section
  (`EnrollmentApplicationService::approve`), which issues the assessment. Students pay online by
  sending proof; the cashier verifies it. `EnrollmentConfirmationService::confirmIfReady()` enrolls
  automatically once the payment policy is met, enlisting only the chosen subjects.
- **Finance (docs/14).** `FinanceService` is the only writer of assessments, payments, balances, and
  `payment_status`. Money is handled in whole centavos (`FinanceService::cents()` / `money()`), never
  float. Payments are never edited or deleted. Receipts, statements, and proofs are opened only
  through `FinanceAccessPolicy` (owner student, else 404; listed staff roles, else 403).
  Corrections are `FinanceService::adjust()` (signed `financial_adjustments` row, reason required)
  and `FinanceService::reversePayment()` (a new negative `payments` row with no receipt, pointing at
  the original). Use the **amount due** (`FinanceService::dueCents()`: total + adjustments), never
  `total_amount` alone, and sum payment amounts **with their sign**. Spreadsheet exports go through
  `CsvExport`, which neutralizes formula cells; exports are audited.
- **Communication (docs/16).** Notifications are written with `NotificationService` at the moment of
  the event; links are relative in-app paths, and the inbox (`/portal/notifications`) follows a link
  only through `NotificationService::safeLink()`. Who an announcement reaches is defined **once**, in
  `Announcement::audienceSql()`, used both for reading and for the one-time `INSERT … SELECT` of
  notifications in `AnnouncementService::dispatch()` — never filter announcements in a view or
  with a second rule. Writing and publishing go through `AnnouncementService` (permission
  `announcements.manage` / `announcements.publish`, re-checked with `User::hasPermission()`).
  Teachers post `CLASSES` announcements to their own active classes (`announcements.class`); only the
  author manages one, and managers may only archive it. The author is never notified of their own post.
  Scheduled announcements are delivered by `AnnouncementService::dispatchDue()` on portal page loads
  and by `php database/dispatch_announcements.php`.
- **Administration (docs/07, docs/19, docs/20).** Admin routes are `role:Super Admin` **plus** a `can:`
  permission, walked by `tests/admin_test.php`. Accounts change only through `UserAdminService` (staff are
  created Pending with a one-time link from `PasswordResetService`; only token hashes are stored). Role
  permissions change through `PermissionService`; the seeder uses `PermissionService::seedDefaults()`, which
  never removes a grant. Settings edited on screen are defined in `SettingsService::GROUPS`; security
  thresholds are read with `SystemSetting::security()`. Backups go through `BackupService` (PDO dump + zip +
  checksums); **restore is never exposed on the web**. The audit log has no write route and no
  `UPDATE`/`DELETE` of `audit_logs` anywhere in `app/` (a test enforces this).
- **Reports (docs/15, docs/19)** are read-only queries (`FinanceReport`, `RegistrarReport`, `AdminReport`, `ClassSchedule::attendanceSummary()`);
  date ranges go through `ReportRange::parse()` (at most a year, falling back to the default with a message).
  Each report is a screen page, a print page rendered with `Controller::printView()`, and a CSV export through
  `CsvExport` that writes a `reports.exported` audit entry. Teacher class reports find the class with
  `GradingService::ownedClass()` (another teacher's class is a 404). Printout tables sit in
  `overflow-x-auto print:overflow-visible` so a phone scrolls the table, not the page.
- **Documents open in the preview window.** Link an uploaded file, proof, receipt, statement, or
  schedule with `partial('preview-link', [...])`, never a bare download link; it adds Preview with
  Previous/Next (same `group`). Files use `?inline=1`, printable pages `?embed=1`; only those two
  responses may be framed, by this site only (docs/07, docs/10). A student's documents in one place:
  `StudentDocuments::forStudent()`.
- **Deciding buttons confirm in the shared window** (`partials/confirm-dialog.php`, docs/21): `data-confirm`
  on the form or `data-confirm-click` on the button, plus `data-confirm-title` / `-tone` / `-label`, and
  `data-confirm-requires="#field"` when a reason is required. Never `window.confirm()` or an inline script.
  Flash messages and the urgent banner use the same card design. `tests/confirmation_test.php` enforces it.
- **Portal navigation** (sidebar and quick search) is defined once in `app/Helpers/navigation.php`.
  Add a page there with its search words; do not add placeholder "Soon" links. Dashboard stat cards
  take an `href` to the list behind their number.
- **Online hosting (docs/23).** Every database session runs MySQL 8's strict SQL mode and the app time zone
  (`Database::sessionSetup()`), so list every selected column in `GROUP BY` and never rely on the server's
  clock or mode. File names are case-sensitive on Linux: `tests/portability_test.php` checks every class,
  template, asset, and `require`. The web folder is `PUBLIC_PATH` (it may sit outside the project, as
  `public_html`), never `BASE_PATH . '/public'`. "Forgot password?" goes through
  `PasswordResetService::requestByEmail()`, which answers the same whether or not an account exists.
- **Ending a session uses `Session::destroy()`**, which regenerates to a fresh id rather than
  calling `session_destroy()`. The latter keeps the old id for the rest of the request while
  deleting the browser's cookie, which broke every sign-in after an idle timeout (CSRF 403).
- **Financial records are never deleted.** Corrections use reversal/adjustment entries.
- Money is `DECIMAL(12,2)` (PHP peso). Never float.
- Historical records are preserved — past terms stay readable.

## Roles

`Student`, `Teacher`, `Registrar`, `Cashier`, `Super Admin` — **one role per user**
(`users.role_id`). Super Admin implicitly holds every permission; all other roles are
checked against explicitly granted permissions. A user must never reach another role's
dashboard. Per-role Can/Cannot lists are in `docs/04_USER_ROLES_AND_PERMISSIONS.md`.

## Conventions & authority

- **`docs/` is the authoritative product spec.** `docs/00_MASTER_SPECIFICATION.md` is
  the primary authority; `docs/25_CLAUDE_IMPLEMENTATION_GUIDE.md` governs how to work
  here. Read the relevant module doc before changing behavior. Do not invent
  requirements that conflict with it. **If behavior changes, update the doc in the
  same change.**
- Classes `PascalCase`, methods `camelCase`, DB tables/columns `snake_case`, views
  `kebab-case.php`. Tables plural, foreign keys `<singular>_id`.
- UI must be responsive and accessible (`docs/21`): semantic HTML, labels bound to
  inputs, visible focus states, and **status never communicated by color alone** —
  always pair with text.
- Add or update tests in `tests/` for any changed business rule, authorization path,
  or validation rule. Test the failure cases, not just the happy path.
- Migrations need a working `down` as well as `up`.

## Reporting completed work

Per `docs/25`, end an implementation by reporting: feature implemented, files created,
files modified, database changes, route/API changes, permission changes, tests
performed, known limitations, and assumptions made.

## Brand palette — Lyceum of the East Aurora

Defined once in `tailwind.config.js` as `brand.*`. Use the tokens, never a raw hex
and never a stock Tailwind color.

| Token | Hex | Use |
|---|---|---|
| `brand-primary` / `brand-400` | `#2E9AD6` | Primary Blue — large headings, fills, accents |
| `brand-600` | `#1E7BAE` | Buttons, links, small text |
| `brand-navy` / `brand-900` | `#0F4C5C` | Headings and important text |
| `brand-teal` | `#7ED08A` | Accents and success highlights |
| `brand-mint` | `#E8F6ED` | Section and card backgrounds |
| `brand-sky` / `brand-200` | `#B3DFF5` | Borders, hover states |
| `brand-pale` / `brand-50` | `#EAF6FF` | Subtle backgrounds |
| `brand-gray` | `#6B7280` | Secondary text, disabled states |
| `brand-alert` | `#E63946` | Alerts |
| `brand-warning` | `#FFC947` | Warnings |

**Why buttons use `brand-600` and not the brand blue:** white text on `#2E9AD6`
reaches only 3.1:1, below the 4.5:1 WCAG AA minimum for normal text. `brand-600`
(`#1E7BAE`) is the same hue at 4.7:1. The brand blue is still used wherever 3:1 is
enough — large headings, fills, and accents. Do not put white small text on
`brand-primary`.

Status colors are paired text/background/border sets (`success-*`, `warning-*`,
`danger-*`) so a message is readable, and every one of them also carries a word and
an icon — status is never color alone (docs/21).

## Tests

`tests/support.php` holds the fixtures (`makeStudent()`, `applicationInput()`,
`uploadAllRequired()`, `fixtureFile()`). Fixture users are prefixed `__test_` and removed by
`deleteTestUsers()` in each test's `finally`, together with their applications, files, and
uploaded bytes; the application-number counter is rewound so real numbering has no gaps.
When matching the prefix with `LIKE`, use `testPrefixPattern()` — an unescaped `_` is a
wildcard and would match (and delete) real accounts.

Catalog fixtures (sections, rooms, programs, curricula) use codes starting `ZZT`, and fixture
subjects have a `ZZT` code or belong to a `ZZT` curriculum; `deleteTestCatalog()` removes them with
their classes, enlistments, grades, and attendance. Phase 4 fixtures: `enrolledStudent()`,
`makeClass()`, `meeting()`, `makeSubject()`, `makeRoom()`. Tests that open or re-weight a real term's
grading periods must run inside `withGradePeriods()`, which restores them. Attendance tests use
`currentTermId()` and `lastWeekday()`, because a date in the future or outside the term is refused.

Source-wide rules live in `tests/security_test.php`: view output through `e()` (or a reviewed safe form),
`csrf_field()` in every POST form, and a per-file list of reviewed variables inside SQL strings. A new
interpolated variable fails the suite until it is bound with `?` or reviewed and listed.

`SCHOOLHUB_DATABASE=schoolhub_<name>` points the app and scripts at another database (only when
`app.env` is not production); the performance harness uses `schoolhub_perf`. `app.base_url`
may be a full `https://` address; only its path is used for URLs and the cookie path.

Upload tests use `UploadedFile::forTesting()`, which is refused outside the CLI. Real HTTP
uploads (`move_uploaded_file`) are verified end-to-end through Apache with `curl -F`.

## Local development accounts

`php database/create_demo_users.php` creates one account per role for testing
(`student`, `teacher`, `registrar`, `cashier`). It refuses to run when
`app.env` is `production`. The Super Admin is created separately and
interactively by `php database/create_admin.php` — no password is ever committed.
