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
php database/seed.php               # roles, permissions, system settings (idempotent)
php database/create_admin.php       # interactively create the first Super Admin
php database/backup.php             # manual database backup

php tests/run.php                   # full test suite (hand-rolled runner, no PHPUnit)
php tests/run.php --filter=auth     # a single test group

# CSS — Tailwind standalone CLI (tailwindcss.exe in the project root, gitignored)
./tailwindcss.exe -i src/input.css -o public/assets/app.css --watch
./tailwindcss.exe -i src/input.css -o public/assets/app.css --minify   # production
```

Serve with XAMPP Apache. The app's document root is `public/`.

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
→ Repository / Model (PDO) → View.

```
public/            front controller + compiled assets; the ONLY public directory
app/Core/          Router, Request, Response, View, Database, Validator, Csrf, Session, Auth
app/Controllers/   request handling only — no business logic, no SQL
app/Services/      business workflows, transactions, audit writes
app/Repositories/  database access (prepared statements)
app/Models/        entities
app/Middleware/    RequireAuth, RequireRole, RequirePermission, VerifyCsrf, SecurityHeaders
app/Views/         PHP templates: layouts/, partials/, components/, and per-module folders
app/Helpers/       url(), asset(), e(), old(), flash(), money(), dt()
config/            config.php (committed) + config.local.php (gitignored secrets)
routes/            web.php, api.php
database/          migrations/, seeds/, and the migrate/seed/backup runners
storage/           uploads, logs, backups — deny-all, never web-reachable
tests/             run.php + test files
src/input.css      Tailwind source
```

Keep controllers thin and SQL out of views. Multi-table workflows (enrollment
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
- **`payment_status` is derived** from assessments and payments. Never set it by hand.
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
