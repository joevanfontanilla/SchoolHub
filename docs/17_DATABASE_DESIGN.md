# Database Design Specification

## Core Tables

### Authentication
- users
- roles
- permissions
- role_permissions
- login_logs
- password_reset_tokens

### Student
- students
- student_profiles
- student_documents
- guardians/emergency_contacts as required

### Academic
- academic_years
- terms
- programs
- year_levels
- subjects
- sections
- rooms
- class_schedules
- class_assignments

### Enrollment
- enrollment_applications
- enrollment_requirements
- enrollment_requirement_files
- enrollment_status_history
- enrollments

### Academic Records
- attendance
- grade_periods
- grades

### Finance
- fee_types
- fee_assessments
- fee_items
- payments
- payment_items
- receipts
- financial_adjustments

### Communication
- announcements
- notifications

### Administration
- audit_logs
- backups
- system_settings

## Database Rules
- Use primary keys consistently.
- Use foreign keys where appropriate.
- Add indexes for frequent searches/filtering.
- Use timestamps.
- Avoid storing duplicate authoritative data.
- Preserve historical records.
- Use appropriate decimal precision for money.
- Never store passwords directly.
