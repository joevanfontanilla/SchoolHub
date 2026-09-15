# Development Roadmap

## Status (1.0.0-rc.1)

| Phase | State | Commit |
|---|---|---|
| 1 Foundation | Done | `4114efc`, `23f8e5c`, `9a96cea` |
| 2 Student and Enrollment | Done | `e4d710f` |
| 3 Registrar | Done | `996fc25` |
| 4 Academic | Done | `802dce0`, `fcaf65e` (subject selection, online payment, automatic enrollment) |
| 5 Finance | Done | `af1a073` |
| 6 Communication | Done | `1c86cac` |
| 7 Administration | Done | `264d01e` |
| 8 Quality | Done | this release: security scan, HTTP crawl, responsive pass, performance dataset and fixes, deployment preflight and guide |

**1.0.0** is tagged after the school runs the preflight and the acceptance scenarios
(docs/22) on its own server (docs/23) and fixes whatever they turn up.

### Not in 1.0.0
Recorded in the module documents; none blocks enrollment:
- Registrar and teacher reports (class lists, grade sheets, enrollment statistics) (docs/15).
- Email or SMS delivery: the Super Admin hands each password link to its owner (docs/07, docs/16).
- `INC` completion deadlines and transfer-credit evaluation (docs/27, docs/13).
- Virus scanning of uploads (docs/10); off-site backup copies are a scheduled `robocopy`, not a feature (docs/20, docs/23).
- Teacher announcements to their own classes; theme and notification settings.

## Phase 1 — Foundation
- Project structure
- Configuration
- Database connection
- Authentication
- Sessions
- RBAC
- Base UI
- Public website

## Phase 2 — Student and Enrollment
- Student registration
- Student profile
- Document upload
- Enrollment application
- Requirement tracking
- Status tracking

## Phase 3 — Registrar
- Application queue
- Requirement verification
- Approval/rejection/correction
- Student records
- Programs
- Subjects
- Sections
- Enrollment confirmation

## Phase 4 — Academic
- Class assignment
- Teacher module
- Schedules
- Attendance
- Grades
- Academic history

## Phase 5 — Finance
- Fee configuration
- Student accounts
- Payments
- Receipts
- Collections
- Financial reports

## Phase 6 — Communication
- Announcements
- Notifications
- Alerts

## Phase 7 — Administration
- Super Admin
- Users
- Permissions
- Audit logs
- Backups
- System settings
- System health

## Phase 8 — Quality
- Functional testing
- Security testing
- Responsive testing
- Performance testing
- Deployment testing
- Documentation cleanup

## Versioning
- `1.0.0` Core system
- `1.1.0` Academic improvements
- `1.2.0` Finance improvements
- `1.3.0` Communication/reporting improvements
- `2.0.0` Major advanced features
