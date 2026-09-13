# SchoolHub
## Integrated School Enrollment and Management System

**Project Type:** Web-Based School Management System  
**Status:** Planning / Specification  
**Version:** 1.0.0  
**Primary Stack:** PHP, MySQL/MariaDB, HTML5, Tailwind CSS, JavaScript  
**Development Environment:** XAMPP, Apache, MySQL/MariaDB, Windows + WSL2 compatible

## Purpose
SchoolHub is a centralized school management platform for student registration, enrollment, requirements, enrollment status tracking, academic management, fees, payments, announcements, notifications, reports, and administrative operations.

## User Roles
- Student
- Teacher
- Registrar
- Cashier
- Super Admin

## Important Development Rule
No production code should be written until the relevant specifications have been reviewed.

Before implementing a feature:
1. Read this README.
2. Read `docs/00_MASTER_SPECIFICATION.md`.
3. Read the documentation related to the requested module.
4. Inspect the existing project before creating or changing files.
5. Preserve working functionality.
6. Enforce permissions on the backend, not only in the interface.
7. Test the feature after implementation.

## Documentation Authority
`00_MASTER_SPECIFICATION.md` is the primary technical and functional authority for the project.

If a requirement changes, update the relevant Markdown specification before or together with implementation.

## Key Documents
| File | Purpose |
|---|---|
| `CLAUDE.md` | Working rules loaded automatically by Claude Code — stack constraints, architecture, security rules |
| `docs/00_MASTER_SPECIFICATION.md` | Primary authority. Section 8 records resolved constraints |
| `docs/17_DATABASE_DESIGN.md` | Column-level schema, integrity rules, identifier formats, data protection |
| `docs/26_STATUS_TRANSITIONS.md` | Authoritative enrollment status transition matrix |
| `docs/24_DEVELOPMENT_ROADMAP.md` | Phase plan |

## Resolved Project Decisions
- **School type:** tertiary (college) — programs, semesters, year levels, subjects with units and prerequisites.
- **Zero dependencies:** no Composer, no npm. Tailwind is compiled by the standalone `tailwindcss.exe` binary, so the no-Node rule in `docs/00` holds literally.
- **Receipts** are HTML with print CSS; **exports** use native `fputcsv`; **tests** use a hand-written runner.
