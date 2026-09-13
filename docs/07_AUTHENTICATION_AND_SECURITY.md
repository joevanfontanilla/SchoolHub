# Authentication and Security

## Authentication
- Email/username + password login
- Logout
- Password change
- Password reset flow
- Session management
- Account status checks

## Account States
- Pending
- Active
- Disabled
- Locked

## Security Controls
- Password hashing
- Session regeneration after login
- Session timeout
- Login rate limiting
- CSRF protection
- Input validation
- Output escaping
- Prepared statements
- Secure cookies where applicable
- File upload restrictions
- Authorization middleware

## Login Rules
A user must not access a dashboard belonging to another role.

## Password Rules
Password policy must be configurable but should require reasonable minimum strength.

## Audit
Log login success/failure, logout, password changes, permission changes, and other security-sensitive actions.

## Account State Rules
`09_ENROLLMENT_SYSTEM.md` has students self-registering and proceeding immediately,
while this document defines a `Pending` state. They are reconciled as follows:

| State | Set when | May log in |
|---|---|---|
| `Active` | A student completes self-registration | Yes |
| `Pending` | Super Admin creates a staff account (Teacher, Registrar, Cashier) before its first login | Yes, but forced to change password |
| `Disabled` | An administrator deactivates the account | No |
| `Locked` | Failed login attempts exceeded the limit | No, until `locked_until` passes or an administrator unlocks it |

A self-registered student is `Active` immediately and can reach only their own profile
and their own enrollment application. No approval gate is needed before login, because
nothing of consequence is reachable until a registrar acts on their application.

Staff accounts are created by Super Admin with `must_change_password` set, so the
temporary password cannot survive the first session.

## Rate Limiting and Lockout
Failed attempts are counted per account **and** per IP address from `login_logs`. After
the configured threshold the account moves to `Locked` with a `locked_until` timestamp.
Both thresholds are `system_settings` values. A lockout is written to the audit log.

## Password Reset Delivery
The reset flow stores a **hash** of a single-use, expiring token. Delivery in v1.0 uses
PHP's native `mail()` where SMTP is configured; where it is not, a Super Admin issues
the reset link directly to the user. No third-party mail library is used, per the
zero-dependency rule in `00_MASTER_SPECIFICATION.md`.
