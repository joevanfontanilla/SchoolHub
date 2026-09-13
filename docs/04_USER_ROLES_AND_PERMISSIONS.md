# User Roles and Permissions

## Permission Principle
Users may access only functions explicitly allowed for their role. Backend authorization is mandatory.

## Student
### Can
- Register/login
- Manage own profile
- Create and submit enrollment application
- Upload own requirements
- View requirement status
- View enrollment status/history
- View schedule
- View grades
- View attendance
- View fees and balances
- View payment history/receipts
- Read announcements
- Receive notifications

### Cannot
- Approve enrollment
- Modify official grades
- Record payments
- Manage other students
- Manage system settings

## Teacher
### Can
- View assigned classes
- View students in assigned classes
- Record attendance
- Submit/update grades within allowed periods
- View teaching schedule
- Read relevant announcements

### Cannot
- Process enrollment
- Record cashier transactions
- Change system roles
- Access unrelated student records

## Registrar
### Can
- Review applications
- Verify requirements
- Approve/reject/request corrections
- Manage student records
- Manage programs, subjects, sections
- Manage enrollment records
- Assign students/classes
- Generate registrar reports
- Publish registrar announcements if permitted

### Cannot
- Record payments or issue receipts
- Change grades submitted by teachers
- Change user roles or permissions
- Access system settings, backups, or audit log administration
- Delete financial or academic records

## Cashier
### Can
- View student financial accounts
- Assess/maintain fees according to policy
- Record payments
- Generate receipts
- View collection reports
- View payment history

### Cannot
- Approve enrollment applications
- Change grades
- Change user roles

## Super Admin
### Can
- Manage users
- Manage roles and permissions
- Configure school settings
- Configure academic settings
- Manage system settings
- View audit logs
- Manage backups
- View system health
- Access administrative reports

### Cannot
- Edit or delete audit log entries. Audit logs are **append-only for every role,
  including Super Admin** — an administrator who can rewrite the log defeats its
  purpose.
- Read a stored password. Passwords are hashed and may only be reset, never retrieved.
- Bypass the enrollment status transition rules in `26_STATUS_TRANSITIONS.md`.
- Record payments or enter grades in place of the Cashier or Teacher. Super Admin
  administers the system; it is not a substitute operator role.

## Sensitive Operations
Enrollment approval, grade changes, fee changes, payment recording, role changes, and backup restoration must be logged.
