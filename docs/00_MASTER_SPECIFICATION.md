# Master Specification

## 1. Project Identity
- **Name:** SchoolHub
- **Type:** Integrated School Enrollment and Management System
- **Version:** 1.0.0
- **Primary Goal:** Centralize student enrollment, academic, financial, communication, and administrative workflows.

## 2. Technology Constraints
### Required
- PHP
- MySQL or MariaDB
- HTML5
- Tailwind CSS
- JavaScript
- Apache/XAMPP
- Git

### Architecture
Use a modular, MVC-inspired PHP structure with clear separation between:
- Presentation/UI
- Controllers/request handling
- Business logic/services
- Database/data access
- Authentication/authorization
- Utilities

### Restrictions
Do not introduce Laravel, React, Vue, Node.js, or another major framework unless explicitly authorized.

## 3. Roles
### Student
Can manage their own profile, enrollment application, requirements, enrollment status, schedule, grades, attendance, fees, payments, announcements, and notifications.

### Teacher
Can manage academic information only for assigned classes: class lists, attendance, grades, schedules, and relevant announcements.

### Registrar
Manages student records, enrollment applications, requirements, programs, subjects, sections, enrollment approval, and registrar reports.

### Cashier
Manages fees, student accounts, payments, receipts, collections, and financial reports.

### Super Admin
Manages system users, roles, permissions, school/academic settings, backups, audit logs, system settings, and system administration.

## 4. Non-Negotiable Security Rules
- Passwords must never be stored in plaintext.
- Use prepared statements or a safe database abstraction.
- Validate and sanitize input.
- Validate uploaded files by type, size, and storage rules.
- Enforce authorization on every protected backend action.
- Never trust hidden UI buttons as authorization.
- Protect sessions.
- Implement CSRF protection for state-changing requests.
- Record important administrative and financial actions in audit logs.
- Do not expose sensitive data to unauthorized roles.
- Do not place secrets directly in public source files.

## 5. Core Business Flow
Student Registration → Enrollment Application → Requirement Submission → Registrar Review → Approval/Correction/Reject → Fee Generation → Payment → Enrollment Confirmation → Class/Section Assignment → Academic Activities.

## 6. Development Rules
- Follow the existing project structure.
- Avoid duplicate functionality.
- Avoid unnecessary dependencies.
- Keep modules loosely coupled.
- Use reusable components.
- Use consistent naming.
- Keep database logic out of presentation files where practical.
- Make interfaces responsive.
- Test both successful and failed scenarios.
- Report files created/modified and assumptions after implementation.

## 7. Definition of Done
A feature is not complete until:
- Functional behavior works.
- Role permissions are enforced.
- Validation works.
- Error states are handled.
- Database operations are safe.
- UI is responsive.
- Relevant logs/notifications are implemented.
- Existing features remain functional.
- The feature has been tested.

## 8. Resolved Constraints

These decisions resolve contradictions found in the original specification. They are
authoritative.

### School type: tertiary (college)
The academic structure is Academic Year → Semester → Program → Year Level → Section →
Subject → Class Schedule. Subjects carry **units** and prerequisites. The document list
in `10_REQUIREMENTS_AND_DOCUMENTS.md` is configurable per enrollment period and is
seeded with tertiary admission requirements.

### Tailwind without Node.js
Section 2 requires Tailwind CSS and also forbids Node.js. These are reconciled by using
the **Tailwind standalone CLI binary** (`tailwindcss.exe`), which bundles its own
runtime and needs no Node installation:

```
tailwindcss.exe -i src/input.css -o public/assets/app.css --minify
```

No `package.json`, no `node_modules/`. The restriction in section 2 stands as written.

### Zero dependencies
There is no Composer either. The router, view rendering, validation, CSRF, session
handling, database access, migration runner, and test runner are hand-written in
`app/Core/`. Consequently:

- **Receipts and printable reports** are HTML with print CSS, not a PDF library.
- **Spreadsheet export** uses native `fputcsv`.
- **Tests** use a small hand-written runner (`tests/run.php`), not PHPUnit.

Adding any dependency requires explicit authorization and an amendment to this section.

### Enrollment status
The twelve statuses listed in `09_ENROLLMENT_SYSTEM.md` are **three independent axes**,
not one enum. See `26_STATUS_TRANSITIONS.md`, which is authoritative for every status
change.
