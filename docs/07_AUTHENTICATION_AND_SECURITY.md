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

---

## Framing
Every response is sent with `X-Frame-Options: DENY` and `frame-ancestors 'none'`. The only
exception is the document preview window (docs/10): an uploaded file with `?inline=1` and a
printable page with `?embed=1` may be framed by this site itself (`SAMEORIGIN`,
`frame-ancestors 'self'`), never by another site. An inline file also gets a policy that runs
nothing (`default-src 'none'`). The HTTP crawl checks these headers.

## As built (Phase 7)

### Staff accounts and one-time password links
- The Super Admin creates Teacher, Registrar, Cashier, and Super Admin accounts
  (`/admin/users/create`). This amends the table above: a new staff account is **Pending
  with no usable password** (a random hash nobody knows), not a temporary password, so
  there is nothing to "must change". Students still self-register.
- Creating the account shows a **one-time set-password link** (valid `setup_link_hours`,
  default 72). A forgotten password gets a **reset link** from the user's page (valid
  `reset_link_minutes`, default 60). Both are shown once, on the page that issued them.
- Only a **SHA-256 hash** of the 256-bit token is stored (`password_reset_tokens`, with its
  `purpose` and `created_by`). A link works once; issuing a new one, setting the password,
  or disabling the account ends the older links. A disabled account's link never works.
- Using a link (`/password/reset/{token}`, signed-out visitors only) applies the password
  policy, activates a Pending account, and unlocks a Locked one. Invalid, used, and expired
  links all show the same message. Every issue and use is audited (`password.*`).
- No mail is sent: the administrator gives the link to the person. The sign-in page says
  to contact the school administrator.

### Account administration (`UserAdminService`)
- Edit name, username, and email (unique); disable with a required reason; enable; unlock.
- A disabled account's open sessions end on their next request.
- Roles change only between staff roles. Students keep the Student role, staff never
  become students, no one changes their own role, and a teacher who still teaches active
  classes keeps the Teacher role.
- The Super Admin cannot disable their own account, and the last active Super Admin can be
  neither disabled nor moved to another role.

### Security settings
`max_login_attempts`, `lockout_minutes`, `ip_max_attempts`, `ip_window_minutes`,
`password_min_length`, `reset_link_minutes`, `setup_link_hours`, and
`registration_limit_per_hour` are system settings edited in System Settings → Security
(`SystemSetting::security()`, falling back to `config security.*`). The per-IP limit may not
be lower than the per-account limit.
