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
- User creation/disable
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
