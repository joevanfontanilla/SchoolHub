# User Roles and Permissions

## Permission Principle
Users may access only functions explicitly allowed for their role. Backend authorization is mandatory.

## Student
### Can
- Register/login
- Manage own profile
- Create and submit enrollment application
- Upload own requirements
- Request official documents from the registrar (TOR, Good Moral, COE; `credentials.request`, docs/08)
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
- Post announcements to the students of their own classes (`announcements.class`; docs/16)

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
- Manage programs, subjects, sections, and the academic calendar (semester dates and
  enrollment windows; `academic.manage`)
- Credit subjects to a student — transfer credit, or study before this system
  (`academic.credit`, docs/28)
- Process student document requests — TOR, Good Moral, Certificate of Enrollment
  (`credentials.manage`, docs/08)
- Manage enrollment records
- Assign students/classes
- Generate registrar reports
- Write, publish, schedule, and archive announcements (`announcements.manage`,
  `announcements.publish`; docs/16)

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
- Write and publish announcements (holds every permission; docs/16)

### Cannot
- Edit or delete audit log entries. Audit logs are **append-only for every role,
  including Super Admin** — an administrator who can rewrite the log defeats its
  purpose.
- Read a stored password. Passwords are hashed and may only be reset, never retrieved.
- Bypass the enrollment status transition rules in `26_STATUS_TRANSITIONS.md`.
- Record payments or enter grades in place of the Cashier or Teacher. Super Admin
  administers the system; it is not a substitute operator role.

## As built (Phase 7): editing role permissions
The Super Admin edits the permissions of Student, Teacher, Registrar, and Cashier at
`/admin/roles` (`PermissionService`). Every change is audited with the permissions added and
removed. Super Admin itself always holds every permission and cannot be edited, and the
`administration` permissions stay Super Admin only. Routes also require the matching role, so
granting a permission to another role does not open that role's screens; the matrix is mainly
for narrowing access. `database/seed.php` no longer rewrites grants: it fills a role that has
none (a fresh install) and grants only permissions that are new in that run, so choices made in
the screen survive re-seeding.

## As built: administrative reports
The Super Admin's reports (account review, sign-in summary, activity summary, system usage) are
described in docs/19.

## Sensitive Operations
Enrollment approval, grade changes, fee changes, payment recording, role changes, and backup restoration must be logged.
