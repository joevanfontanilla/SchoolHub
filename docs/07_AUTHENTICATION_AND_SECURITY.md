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
