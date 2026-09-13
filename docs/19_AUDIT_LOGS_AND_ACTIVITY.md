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
