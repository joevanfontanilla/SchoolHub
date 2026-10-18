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
Password policy must be configurable but should require reasonable minimum strength. As built
(`AuthService::passwordPolicyProblem()`): at least `password_min_length` characters (default 8) with
a letter and a number; and it rejects NUL and other control characters, and anything past 72 bytes.
Refusing the NUL byte matters because bcrypt truncates at the first NUL on PHP before 8.2.19 /
8.1.28 / 8.3.7 (CVE-2024-3096), so a hash made from `secret\0…` would verify against `secret`; the
policy keeps such a hash from ever being stored, whatever the PHP version. The preflight and System
Health also warn when PHP itself is older than the patched release.

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
| `Deleted` | The Super Admin deletes the account (below) | No, and the username and email are freed |

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
The reset flow stores a **hash** of a single-use, expiring token. As built: where email is set up
(`mail.driver` smtp), anyone can ask for a link at **Forgot password?**, and the Super Admin can
email any link they issue; where it is not, the Super Admin gives the link to the person. Mail goes
through `App\Core\Mailer`, a hand-written SMTP client (no mail library and not PHP's `mail()`, per
the zero-dependency rule in `00_MASTER_SPECIFICATION.md`). See "Forgot password and email" below.

## Visitor address and HTTPS behind a proxy
The per-IP limits above, `login_logs`, and the audit log use the visitor's address. Behind Cloudflare
or a hosting provider's load balancer the connection comes from the proxy, so `X-Forwarded-For` and
`X-Forwarded-Proto` are believed **only** from addresses listed in `app.trusted_proxies`
(`Request::ip()`, `Request::isSecure()`); the visitor is the right-most address not added by a
trusted proxy, so a header a visitor sends themselves cannot pick an address. With the list empty
(the default) both headers are ignored. The preflight fails on a list that trusts everyone.

In production with an `https://` `app.base_url`, a plain-HTTP request is redirected to that address
(301 for a page, 308 for a form, before any route runs), so a sign-in form is never sent unencrypted
even where the hosting panel does not redirect.

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
- The link page offers **Email the link**, which sends it to the account's own address (the token
  must still be an unused link of that account; audited `password.link_emailed`). Without email the
  administrator gives the link to the person, and the sign-in page says to contact them.

### Account administration (`UserAdminService`)
- Edit name, username, and email (unique); disable with a required reason; enable; unlock.
- A disabled account's open sessions end on their next request.
- Roles change only between staff roles. Students keep the Student role, staff never
  become students, no one changes their own role, and a teacher who still teaches active
  classes keeps the Teacher role.
- The Super Admin cannot disable their own account, and the last active Super Admin can be
  neither disabled nor moved to another role.

### Deleting an account (`UserAdminService::delete`, `restore`, `erase`)
Two levels, because payments, receipts, grades, enrollments, and the audit log all point to accounts.
- **Delete account** (the default). Status becomes `Deleted`. A required reason (5+ characters) and typing the
  account's username confirm it. The username and email are replaced by `deleted-{id}` and
  `deleted-{id}@deleted.invalid`, so the person cannot sign in and the login is free to reuse; the originals are kept
  in `deleted_username` / `deleted_email` for a restore. The name stays, so old receipts, grades, and logs still
  read correctly. All sessions and open links end, photos and signatures stay with the records they appear on, and a
  student's e-ID stops verifying (the public page says Not valid).
- **Restore.** The Super Admin can restore a deleted account at any time before it is erased. The original username and
  email return if still free; otherwise the restore is refused with a message. It comes back `Disabled`, never
  `Active`, so an administrator enables it on purpose.
- **Erase permanently.** Only for an account that nothing refers to (a mistake or a test account): the database
  refuses it otherwise, and the account stays deleted. Students never qualify (their student record refers to them).
- **Refused** for: your own account; the last active Super Admin; a student who is currently enrolled (withdraw
  them or wait for the term to end); a teacher who still teaches active classes or advises a section.
- Audited: `user.deleted`, `user.restored`, `user.erased`, with the reason. The deleted accounts are listed under the
  Users filter **Deleted** and are left out of every other list, picker, and count of active people.

### Security settings
`max_login_attempts`, `lockout_minutes`, `ip_max_attempts`, `ip_window_minutes`,
`password_min_length`, `reset_link_minutes`, `setup_link_hours`, and
`registration_limit_per_hour`, and `reset_requests_per_hour` are system settings edited in System Settings → Security
(`SystemSetting::security()`, falling back to `config security.*`). The per-IP limit may not
be lower than the per-account limit.

### Forgot password and email (online deployment)
- `/password/forgot` (signed-out visitors) asks for the account's email address. The answer is
  **always the same** ("If an account uses …, a link is on its way"), whether the address has an
  account or not, and where the server supports it the email is sent after the answer has reached the
  browser, so neither the words nor the timing reveal an account.
- A link is sent only to an **Active or Locked** account (a Pending staff account gets its set-up link
  from the Super Admin; a Disabled one gets nothing), at most `reset_requests_per_hour` requests from
  one address per hour (default 5, counted from the audit log) and 3 links per account per hour.
- Every request is audited as `password.reset_requested` with the outcome, **without the address that
  was typed**; a delivery failure is `password.reset_email_failed` and goes to the error log.
- In production a link is emailed only when `app.base_url` is an `https://` address; it is never built
  from the Host header a visitor sent.
- `Mailer` refuses a recipient or subject that would add a header line, encodes the text and HTML parts
  in base64, uses STARTTLS (587) or TLS (465), verifies the mail server's certificate by default, signs
  in with AUTH PLAIN or LOGIN only after encryption, and never repeats the password in an error. The
  `log` driver (development) writes messages to `storage/logs/mail.log`; the preflight fails on it in
  production. `tests/mail_test.php` runs the SMTP conversation against a local test server, with and
  without STARTTLS.
