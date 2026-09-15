# Testing and Acceptance

SchoolHub is tested at four levels, all without third-party tools (docs/00): a unit and
integration suite, a code-wide security scan, an HTTP crawl through the real web server,
and a performance harness with a school-sized dataset. Deployment is checked by the
preflight in docs/23.

| Level | Command | What it proves |
|---|---|---|
| Suite | `php tests/run.php [--filter=name]` | Business rules, authorization, validation, and failure paths, against the real database |
| Security scan | `php tests/run.php --filter=security` | Rules that hold across all source files (part of the suite) |
| HTTP crawl | `php tests/http/crawl.php` | Every route as every role through Apache: status codes, headers, cookies, escaping, injection, CSRF, exposed files, accessibility |
| Performance | `tests/perf/*` (below) | Page time and database statements with 2,000 students |
| Deployment | `php database/check_deployment.php --url=…` | The server is configured safely (docs/23) |

Every change runs the suite. The crawl runs before a phase is committed and after any
route, middleware, or layout change. The performance pass runs after a change to a list,
report, or dashboard query, and before a release.

---

## 1. The suite (`tests/run.php`)

A hand-written runner: each `tests/*_test.php` returns `'description' => callable`, and a
test passes if it throws nothing. Tests use the real database with fixtures that are
always removed (`__test_` usernames, `ZZT` catalog codes; see `CLAUDE.md`).

**235 tests in 29 files**:

| Area | Files (tests) |
|---|---|
| Authentication and sessions | `auth` (13), `csrf` (6), `rbac` (7), `registration` (7), `validation` (13) |
| Enrollment | `enrollment_application` (12), `enrollment_status` (10), `enrollment_flow` (9), `enrollment_confirmation` (8) |
| Documents | `upload` (11), `document_access` (5), `requirement_review` (8) |
| Registrar | `registrar_access` (5), `registrar_decisions` (6), `academic_catalog` (8), `curriculum` (11) |
| Academic | `class_schedule` (9), `enlistment` (7), `grade_calculator` (11), `grading` (7), `attendance` (3), `academic_record` (2), `teacher_access` (5) |
| Finance | `finance` (12) |
| Communication | `communication` (8) |
| Administration | `admin` (11) |
| Reports | `reports` (7) |
| Quality | `security` (7), `deployment` (7) |

Route-table tests (`registrar_access`, `teacher_access`, `rbac`) walk `routes/web.php`
itself, so a new route without the right role and permission gates fails the suite.

## 2. Security scan (`tests/security_test.php`)

Reads the source, so a rule broken anywhere fails even where no other test looks:

- Every `<?= … ?>` in a view prints through `e()` or one of a short list of reviewed
  safe forms (integers, components that escape inside, fixed lookups).
- Every `method="POST"` form contains `csrf_field()`.
- Controllers contain no SQL; models and services never read `$_GET`/`$_POST`/`$_FILES`.
- No `eval`, `exec`, `system`, `unserialize`, `md5`, `var_dump`, `print_r`, or `extract`
  (outside the view renderer) in the application.
- Every variable interpolated into a SQL string (found with PHP's tokenizer) is on a
  reviewed list per file: table and column names chosen in code, `?, ?, ?` placeholder
  lists, WHERE fragments built from fixed text, and integers. Anything else must be
  bound with `?`.
- An unexpected exception shows a generic message and no path or trace when debug is off.
- The security headers and session cookie flags are set, and `index.php` is the only
  PHP file in `public/`.

## 3. HTTP crawl (`tests/http/crawl.php`)

```bash
php tests/http/crawl.php                          # against http://localhost/SchoolHub/public
php tests/http/crawl.php --keep --report=r.json   # leave the fixtures for a browser pass
php tests/http/crawl.php --cleanup
```

Runs through Apache (the PHP built-in server hides real differences; see `CLAUDE.md`).
It builds fixtures for every role (an enrolled student with a class, grades, a payment,
an online payment awaiting verification, an adjustment, a public urgent announcement
with an image, uploaded documents, a password link), plants script payloads in names,
rooms, and subjects, signs in through the login form, and then:

1. Requests **every GET route** as a visitor and as each of six sessions (student,
   a second student, teacher, registrar, cashier, Super Admin). The expected status
   comes from each route's own middleware plus the ownership rules: login redirect for
   visitors, 403 for the wrong role, 404 for another student's record, and no 5xx
   anywhere.
2. Checks the security headers on every response and the cookie flags.
3. Fails if any page returns a planted payload unescaped, or shows an error message.
4. Sends SQL- and script-injection payloads in every filter parameter of every page.
5. POSTs to **every state-changing route** without a CSRF token: each must return 403
   and write no audit entry.
6. Probes `.git`, `config`, `storage`, `docs`, `tests`, and `database` files through the
   web server: all must be refused.
7. Checks every page's HTML for basic accessibility (docs/21): `lang` and `<title>`, one
   `<main>`, `alt` on images, a label for every field, a name for every link and button,
   and no duplicate ids.
8. Opens receipts, statements, every CSV export (cashier, registrar, and teacher reports), and printouts as their owners and as others.

Result with the registrar and teacher reports: **1,651 checks, 0 failures** (840 page requests, 284 CSRF probes).
The crawl found and fixed missing `<main>` landmarks on the sign-in and printable
layouts and an unlabeled logo upload field.

### Responsive and browser pass
With `--keep`, every page of every role was opened in a browser at 375 px (phone),
768 px (tablet), and desktop widths: no page scrolls horizontally, tables scroll inside
their own container, and the console shows no errors. The Content-Security-Policy
(`script-src 'self'`) blocks no script or style the application uses.

## 4. Performance (`tests/perf/`)

A throwaway database `schoolhub_perf` holds a school-sized dataset. The web server and
scripts are pointed at it with `SCHOOLHUB_DATABASE`, which only non-production
installations honor.

```bash
SCHOOLHUB_DATABASE=schoolhub_perf php database/migrate.php
SCHOOLHUB_DATABASE=schoolhub_perf php database/seed.php
SCHOOLHUB_DATABASE=schoolhub_perf php database/import_curricula.php
SCHOOLHUB_DATABASE=schoolhub_perf php tests/perf/generate.php
php -S 127.0.0.1:8099 -t public tests/perf/router.php          # the "schoolhub-perf" launch configuration
SCHOOLHUB_DATABASE=schoolhub_perf php tests/perf/measure.php
# afterwards: DROP DATABASE schoolhub_perf;
```

The dataset: 2,067 accounts; 2,000 students with applications in every status and
12,000 requirement records with 11,400 files; 48 sections with 304 classes; 1,500
enrollments with 9,375 enlistments, 18,750 grades, and 37,500 attendance records;
1,500 assessments and 2,000 payments; 60 announcements with 30,000 notifications;
100,000 audit entries and 30,000 sign-in attempts.

`measure.php` signs in as each role and requests 70 pages three times each. With debug
on (never in production), every response carries `X-Debug-Queries` and
`X-Debug-Time-Ms`. A page over **300 ms** of server time or **60 statements** is flagged.

Findings and fixes:

| Page | Before | Fix | After |
|---|---|---|---|
| Student application (subject selection) | 177 statements | Prerequisites fetched with the subjects; passed subjects computed once | ≤ 33 |
| Student grades and history, registrar student record | 73 and 61 statements | Meetings and grades for all classes in one query each | ≤ 33 |
| Manage announcements | 68 statements | Audience labels resolved in one batch | ≤ 33 |
| Outstanding balances report | 321 ms, 2.4 MB | 100 rows per page (the CSV export still has every row); open-balance index | < 90 ms |
| Student accounts (cashier) | all rows | 50 per page | < 90 ms |
| Audit log | 126 ms | Indexes on `created_at` and `(action, created_at)`; count skips the users join unless filtering by person | 43 ms |

Migration `022_add_reporting_indexes` holds the indexes. After the fixes **every
measured page is under 90 ms and 33 statements** on this dataset (XAMPP on a Windows 11
laptop).

The registrar and teacher reports were measured the same way. On screen every one is
under 100 ms and 13 statements. The requirements status list first took 165 ms and
557 KB, because it drew an icon badge for every missing document; plain-text statuses
brought it to 100 ms and 266 KB. The printable enrollment and applicant lists, which carry
every row (1,500 and 2,000), are about 1.3 MB and render in 56–75 ms. The history report
takes 21 ms.

Backups at this size: 3.5 MB archive in 4.2 s, verification 0.3 s, restore into an empty
database 11 s with every table's checksum matching the source (except `audit_logs`, which
gains the restore's own entries).

## 5. Deployment testing

`tests/deployment_test.php` proves the preflight fails on each development default
(environment, debug, http address, insecure cookie, root account, empty password,
server-wide privileges, demo and leftover test accounts) and passes a correct
production configuration.

The preflight was also run against this XAMPP installation switched temporarily to
production settings over HTTPS (`https://localhost/SchoolHub/public/`, self-signed):
security headers, HSTS, no debug headers, `Secure; HttpOnly; SameSite=Lax` cookie scoped
to the site path, sign-in and a registrar page working, generic 403 and 404 pages, and
42 private-file probes refused. That run found that a full `https://` `app.base_url`
became the cookie path; `base_path_uri()` now uses only the path of the address.

---

## Acceptance criteria

A module is accepted when:
1. Required workflows work.
2. Invalid workflows are rejected.
3. Role permissions work.
4. Data is stored correctly.
5. UI is responsive.
6. Errors are understandable.
7. Audit requirements are satisfied.
8. Existing modules remain functional (the full suite and crawl pass).

## Acceptance scenarios (manual, on the deployed server)

1. **Student:** registers, completes the profile, applies for the open term, chooses
   subjects, uploads every requirement, and sees the application status.
2. **Registrar:** asks for a correction on one document, then verifies all; approves with
   a section; the assessment appears.
3. **Student:** sends proof of an online payment. **Cashier:** verifies it; the receipt
   opens; the student is enrolled automatically and receives a student number and a
   schedule.
4. **Teacher:** records attendance for a meeting day and a Prelim grade while the period
   is open; a closed period refuses the grade.
5. **Registrar:** publishes an urgent announcement for the student's program; the student
   sees the banner and a notification.
6. **Super Admin:** creates a staff account (set-up link works once), changes a setting,
   runs and verifies a backup, and finds each of the above in the audit log.
7. **Refusals:** a teacher opening a cashier URL gets 403; a student opening another
   student's application gets 404; a forged form gets 403.
