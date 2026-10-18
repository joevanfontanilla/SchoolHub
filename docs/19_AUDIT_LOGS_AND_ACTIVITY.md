# Audit Logs and Activity

## Audit Event Fields
- ID
- Actor/user
- Role
- Action
- Module
- Target entity/type
- Target ID
- Previous value where appropriate
- New value where appropriate
- IP address when appropriate
- User agent when appropriate
- Timestamp
- Result
- Remarks

## Important Events
- Login success/failure
- Password change
- User creation/disable/delete/restore/erase
- Role/permission changes
- Enrollment status changes
- Requirement verification
- Grade changes
- Fee changes
- Payment creation/adjustment
- Announcement publication
- Backup creation/restoration
- System setting changes

## Principle
Audit logs are for accountability and must not be casually editable by normal users.

## Append-Only Rule
Audit logs are **append-only for every role, including Super Admin.** The application
exposes no update or delete path for `audit_logs` — not in a controller, not in a
service, not in an administrative screen. An administrator who can rewrite the log
defeats the purpose of having one.

Audit entries are purged only by a deliberate, documented retention action performed
directly against the database by the system owner, never through the application.

## Never Recorded
Audit entries must never contain passwords or hashes, session identifiers, reset or
CSRF tokens, or the contents of uploaded documents. Record the identifier of what
changed, not the sensitive value itself.

---

## As built (Phase 7)
- **Audit Logs** (`/admin/audit-logs`, Super Admin with `audit.view`): newest first, 50 per
  page, filtered by date range, who (name, username, or email), action prefix (e.g.
  `finance.`), module, result, and record type and id. Each entry opens with its before and
  after values. **Export CSV** (up to 10,000 rows, formula cells neutralized) is itself audited
  (`audit.exported`).
- **Sign-in Activity** (`/admin/login-activity`): every attempt from `login_logs`, filtered by
  date, login tried, IP address, and result, linked to the account.
- Append-only is enforced in code and by a test: every audit route is GET-only, and no file in
  `app/` contains an `UPDATE`, `DELETE`, or `TRUNCATE` of `audit_logs`.
- When an entry's actor account no longer exists (a stale session), the entry is still written
  without the user link instead of being lost; values that are not valid UTF-8 are substituted,
  never dropped. A write that still fails goes to `storage/logs/audit-failures.log`, which System
  Health reports.

## As built: administrative reports (`/admin/reports`, `reports.admin`)

Super Admin role **and** `reports.admin`. Each report is on screen, printable, and an audited CSV
export (`reports.exported`). Reports count and summarize; they never show passwords, the values
inside audit entries, or document contents.

| Report | Screen | Contents |
|---|---|---|
| **Account review** | `/admin/reports/accounts` (role, status, flagged only) | Accounts by role × status, and each account with its last sign-in and review flags: **never signed in** (active, no sign-in yet), **no sign-in for 90 days**, **locked** (status or an unexpired lockout), and **set-up link expired or unused** (a Pending staff account with no valid link). A disabled account is already out of use and carries no flag. Counts cover every account; the filters narrow the list. |
| **Sign-in summary** | `/admin/reports/sign-ins` (date range, default the last 30 days) | Attempts, successes, failures, accounts signed in, and addresses; failed attempts per day (per month past 31 days); the ten addresses and the ten logins with the most failures, linked to Sign-in Activity and the account. |
| **Activity summary** | `/admin/reports/activity` (date range, default the last 30 days) | Audit entries and refusals; entries per day; counts by module and by role; the ten most active people; the fifteen most frequent actions. Each group links to the audit log filtered to it. |
| **System usage** | `/admin/reports/usage` | Accounts by role; applications, enrolled students, classes, and net collections for the six most recent terms; announcements and notifications; requirement documents and payment proofs with their size; audit and sign-in log sizes; the database size; and files and bytes in `storage/uploads`, `storage/backups`, and `storage/logs`, read from the disk. |

A date range is at most a year; a mistyped, reversed, or longer range falls back to the default
with a message (`ReportRange`, shared with the cashier's collections report).
