# Database Design Specification

Engine: **MySQL / MariaDB**, InnoDB, `utf8mb4_unicode_ci`.
Model: **tertiary (college)** — Program → Year Level → Semester, subjects carry units
and prerequisites.

Conventions used throughout:

- Every table has `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` unless stated.
- Every table has `created_at` and `updated_at` (`TIMESTAMP`) unless stated.
- Foreign keys are named `<singular>_id` and are real `FOREIGN KEY` constraints.
- Money is `DECIMAL(12,2)` — **never** `FLOAT` or `DOUBLE`.
- Deletions that would destroy history use `ON DELETE RESTRICT`. Nothing financial or
  academic is ever hard-deleted.
- Fixed value sets are stored as `VARCHAR` validated in the application rather than as
  MySQL `ENUM`, so adding a value never requires an `ALTER TABLE` on a large table.

---

## 1. Authentication and Access

### `roles`
`name` (unique — `Student`, `Teacher`, `Registrar`, `Cashier`, `Super Admin`),
`slug` (unique), `description`.
Exactly five seeded rows. **One role per user.**

### `permissions`
`key` (unique, e.g. `enrollment.approve`), `module`, `description`.

### `role_permissions`
`role_id`, `permission_id` — composite primary key, both FK, `ON DELETE CASCADE`.
Super Admin implicitly holds every permission and needs no rows here.

### `users`
| Column | Type | Notes |
|---|---|---|
| `role_id` | FK → roles | RESTRICT |
| `username` | VARCHAR(50) | unique |
| `email` | VARCHAR(150) | unique |
| `password_hash` | VARCHAR(255) | `password_hash()` output — **never** plaintext |
| `first_name`, `middle_name`, `last_name` | VARCHAR(100) | middle nullable |
| `suffix` | VARCHAR(20) | nullable — optional name suffix (Jr., Sr., III, …); shown after the last name in `User::fullName()` |
| `status` | VARCHAR(20) | `Pending`, `Active`, `Disabled`, `Locked` |
| `must_change_password` | TINYINT(1) | default 0 |
| `avatar_path` | VARCHAR(255) | nullable — a non-student's profile photo, under `storage/`, never web-reachable (a student's photo is on `student_profiles.photo_path`); see `AvatarService`, docs/08 |
| `failed_login_attempts` | SMALLINT | default 0 |
| `locked_until` | DATETIME | nullable |
| `last_login_at` | DATETIME | nullable |
| `privacy_consented_at` | DATETIME | nullable — when a self-registered student accepted the data privacy notice (RA 10173) |

Index `(status)`. Users are **disabled, never deleted**.

Self-registered students do not choose a username: it is generated from the local part of
their email, with a numeric suffix on collision. They may sign in with their email.

### `sessions`
`id` VARCHAR(128) **primary key** (no auto-increment), `user_id` FK nullable,
`ip_address` VARCHAR(45), `user_agent` VARCHAR(255), `payload` TEXT,
`last_activity` INT. Indexes `(last_activity)`, `(user_id)`.

### `login_logs`
`user_id` FK nullable (a failed attempt may match no user),
`username_attempted` VARCHAR(150), `ip_address` VARCHAR(45), `user_agent` VARCHAR(255),
`result` VARCHAR(20) (`SUCCESS`, `FAILED`, `LOCKED_OUT`, `DISABLED`),
`attempted_at` DATETIME.

Indexes `(username_attempted, attempted_at)` and `(ip_address, attempted_at)` — these
two drive login rate limiting and are required, not optional.

### `password_reset_tokens`
`user_id` FK, `token_hash` VARCHAR(255) (**a hash of the token, not the token**),
`expires_at` DATETIME, `used_at` DATETIME nullable, `requested_ip` VARCHAR(45).
Single-use. Index `(token_hash)`.

---

## 2. Students

### `students`
`user_id` FK unique (one student per user account),
`student_number` VARCHAR(20) **NULL** unique (format in section 9),
`program_id` FK **NULL**, `curriculum_id` FK **NULL** (docs/28), `year_level_id` FK **NULL**,
`student_type` VARCHAR(20) (`New`, `Transferee`, `Continuing`, `Returning`),
`status` VARCHAR(20) (`Applicant`, `Enrolled`, `Inactive`, `Graduated`),
`admitted_at` DATE nullable.

A student row exists from registration, as an `Applicant`. `student_number`, `program_id`,
and `year_level_id` stay NULL until enrollment is **confirmed** (Phase 3), when the student
number is issued through `document_sequences`. `curriculum_id` is set at the same moment:
the program's latest active curriculum on first enrollment or a change of program; kept
otherwise. Applicants are identified by their
application number, so a rejected or abandoned applicant never consumes a student number.

### `student_profiles`
`student_id` FK unique, `birthdate` DATE, `sex` VARCHAR(10),
`civil_status` VARCHAR(20), `mobile` VARCHAR(20), `telephone` VARCHAR(20),
`street`, `barangay`, `city_municipality`, `province`, `postal_code`,
`nationality`, `religion`,
`photo_path` VARCHAR(255) — stored under `storage/`, **never** web-reachable.

### `guardians`
`student_id` FK, `full_name`, `relationship`, `mobile`, `email`, `address`,
`is_emergency_contact` TINYINT(1). A student may have several.

---

## 3. Academic Structure

### `academic_years`
`code` VARCHAR(20) unique (`2026-2027`), `start_date`, `end_date`,
`is_active` TINYINT(1). Only one active at a time, enforced in the service layer.

### `terms`
`academic_year_id` FK, `name` (`1st Semester`, `2nd Semester`, `Summer`),
`sequence` TINYINT, `start_date`, `end_date`,
`enrollment_opens_at` DATETIME, `enrollment_closes_at` DATETIME,
`is_active` TINYINT(1). Unique `(academic_year_id, sequence)`.

### `programs`
`code` VARCHAR(20) unique (`BSA`), `name`, `description`,
`degree_level` VARCHAR(50), `years_duration` TINYINT, `is_active`.

### `year_levels`
`name` (`First Year` … `Fourth Year`), `sequence` TINYINT unique.

### `curricula`
*(Migration 017; docs/28.)* `program_id` FK, `code` VARCHAR(30) unique (`BSA-2024`), `name`,
`effective_school_year` CHAR(9) (`2024-2025`), `notes` nullable, `is_active`.
A program can have several; a student keeps the one they entered under.

### `subjects`
`code` VARCHAR(20) (`CORE 4`), `title`, `description`,
`units` DECIMAL(3,1), `lab_units` DECIMAL(3,1) (the share of `units` that is laboratory),
`lecture_hours` TINYINT, `lab_hours` TINYINT,
`curriculum_id` FK **nullable** (null = general subject),
`program_id` FK nullable, kept equal to the curriculum's program,
`year_level_id` FK nullable,
`term_sequence` TINYINT nullable (1, 2, or 3 = summer),
`min_progress_percent` TINYINT nullable (a progress prerequisite, e.g. 80),
`is_active`.

**Unique `(curriculum_id, code)`, not `code` alone** (migration 017): the school reuses codes
across programs with different meanings. Codes may contain spaces and are stored
upper-case with single spaces.

### `subject_prerequisites`
`subject_id`, `prerequisite_subject_id` — composite primary key, both FK → `subjects`.
Self-referencing. The service layer must reject cycles.

### `rooms`
`code` unique, `name`, `building`, `capacity` SMALLINT, `type`, `is_active`.

### `sections`
*(Built in migration 012.)* `code` VARCHAR(20) (`BSIT-2A`), `program_id` FK, `year_level_id` FK, `term_id` FK,
`capacity` SMALLINT **NOT NULL**, `adviser_user_id` FK nullable, `is_active`.
Unique `(code, term_id)`. Capacity is enforced on enlistment — see section 8.

### `class_schedules`
**Amended in Phase 4 (migration 015).** The original design made this table one *meeting
block*, so a subject meeting Monday and Wednesday would have been two classes with two
rosters, two grade records, and two attendance sheets. It is instead **one class**: one
subject taught to one section in one term, whose weekly times live in `class_meetings`.

`term_id` FK, `section_id` FK, `subject_id` FK,
`teacher_user_id` FK nullable (unassigned until the registrar assigns one),
`status` VARCHAR(20) (`ACTIVE`, `CLOSED`).
Unique `(section_id, subject_id)`. Index `(term_id, teacher_user_id)`.

### `class_meetings`
`class_schedule_id` FK (cascade), `day_of_week` TINYINT (1 = Monday … 7 = Sunday),
`start_time` TIME, `end_time` TIME, `room_id` FK nullable.
Indexes `(room_id, day_of_week)` and `(day_of_week, start_time)`.
Meetings are a class's configuration and are replaced as a set when the class is saved.

**Time-overlap conflicts cannot be expressed as a UNIQUE index** — teacher, room, section,
and student double-booking checks run in `ClassScheduleService`. See section 8.

### `teacher_profiles`
`user_id` FK unique, `employee_number` unique, `department`, `position`,
`specialization`, `is_active`.
Added for symmetry with students; the original specification had no teacher table.

---

## 4. Enrollment

### `enrollment_applications`
| Column | Type | Notes |
|---|---|---|
| `application_number` | VARCHAR(20) | unique, see section 9 |
| `student_id` | FK | RESTRICT |
| `academic_year_id`, `term_id` | FK | the period applied for |
| `program_id`, `year_level_id` | FK | what the student applied **for** |
| `student_type` | VARCHAR(20) | New, Transferee, Continuing, Returning — fixed once the draft exists |
| `previous_school`, `last_school_year` | VARCHAR | nullable; `previous_school` required for transferees |
| `application_status` | VARCHAR(30) | axis 1 — see `docs/26` |
| `payment_status` | VARCHAR(20) | axis 2 — **derived**, see `docs/26` section 3 |
| `enrollment_state` | VARCHAR(20) | axis 3 — see `docs/26` section 4 |
| `submitted_at`, `reviewed_at`, `decided_at` | DATETIME | nullable |
| `reviewed_by` | FK → users | nullable |
| `remarks` | TEXT | nullable |

A student may hold only one **non-terminal** application per term. MySQL cannot express
that as a partial unique index, so it is enforced in the service layer.
Indexes `(application_status, term_id)`, `(term_id, program_id)`.

### `requirement_types`
The configurable document list from `docs/10`.
`code` VARCHAR(40) unique (stable key for seeding), `name`, `description`,
`is_required` TINYINT(1),
`applies_to` VARCHAR(60) — `All`, or a comma-separated list of student types such as
`New,Transferee`, matched with `FIND_IN_SET`,
`max_file_size_kb` INT, `allowed_extensions` VARCHAR(100), `sort_order`, `is_active`.

### `term_requirements`
Per-period override, because `docs/10` gives each enrollment period its own list.
`term_id` FK, `requirement_type_id` FK, `is_required` TINYINT(1).
Unique `(term_id, requirement_type_id)`.

### `enrollment_requirements`
One row per requested document per application, created with the draft.
`application_id` FK, `requirement_type_id` FK,
`is_required` TINYINT(1) — a **snapshot** of whether the document was required when the
application was created, so later changes to the requirement list never rewrite an
application already in progress,
`status` VARCHAR(30) (`NOT_SUBMITTED`, `SUBMITTED`, `UNDER_REVIEW`, `VERIFIED`,
`REJECTED`, `CORRECTION_REQUIRED`),
`verified_by` FK nullable, `verified_at` DATETIME nullable, `remarks` TEXT.
Unique `(application_id, requirement_type_id)`.

### `enrollment_requirement_files`
The uploaded file itself. This replaces the previously listed `student_documents`,
which duplicated it.

`enrollment_requirement_id` FK,
`original_filename` VARCHAR(255) — **display only, never used as a path**,
`stored_filename` VARCHAR(255) — **generated, never the uploaded name**,
`storage_path` VARCHAR(255) — relative, under `storage/uploads/`,
`mime_type` VARCHAR(100), `file_size` INT, `checksum` CHAR(64),
`uploaded_by` FK, `uploaded_at` DATETIME,
`is_current` TINYINT(1) — a re-upload supersedes rather than overwrites, so history
survives.

### `enrollment_status_history`
Written on **every** status transition (`docs/26` section 5).
`application_id` FK, `axis` VARCHAR(20) (`application`, `payment`, `enrollment`),
`previous_value` VARCHAR(30) nullable, `new_value` VARCHAR(30),
`actor_user_id` FK nullable, `actor_role` VARCHAR(30), `remarks` TEXT,
`created_at` DATETIME. Index `(application_id, created_at)`.

### `enrollments`
Created only when `enrollment_state` becomes `ENROLLED`, and only by `EnrollmentConfirmationService`
(migration 013). `total_units` stays 0 until class enlistment.
`application_id` FK unique, `student_id` FK, `term_id` FK, `section_id` FK,
`total_units` DECIMAL(4,1), `enrolled_at` DATETIME, `enrolled_by` FK,
`status` VARCHAR(20). Unique `(student_id, term_id)`.

### `class_enlistments`
**The link the original specification was missing** — without it, neither a class list
nor a student schedule can be produced.

`enrollment_id` FK, `class_schedule_id` FK,
`status` VARCHAR(20) (`ENLISTED`, `DROPPED`), `override_reason` VARCHAR(500) nullable,
`enlisted_at`, `enlisted_by` FK, `dropped_at` nullable, `dropped_by` FK nullable,
`drop_reason` VARCHAR(500) nullable.
Unique `(enrollment_id, class_schedule_id)`. A drop keeps the row; re-adding revives it.
Written only by `EnlistmentService` (migration 015).
Index `(class_schedule_id, status)` — drives the teacher's class list.

---

## 5. Academic Records

### `grade_periods`
*(Migration 016.)* `term_id` FK, `name` (`Prelim`, `Midterm`, `Finals`), `sequence` TINYINT,
`weight` DECIMAL(5,2), `is_open` TINYINT(1), `opened_at`, `opened_by` FK, `closed_at`,
`closed_by` FK. Unique `(term_id, sequence)`.

Periods are opened and closed by hand rather than by `opens_at`/`closes_at` dates: the
registrar reopens a period to allow a correction, which a fixed window cannot express.
A teacher may submit grades **only while the period is open** (docs/12, docs/27). Only the
registrar opens and closes periods.

### `grades`
`class_enlistment_id` FK, `grade_period_id` FK,
`grade` DECIMAL(3,2) nullable, `remark` VARCHAR(10) nullable (`INC`, `DRP`) — exactly one of
the two is set, `recorded_by` FK, `recorded_at` DATETIME,
`changed_by` FK nullable, `changed_at` DATETIME nullable.
The final grade is **not stored**; it is computed from the period grades (docs/27).
Unique `(class_enlistment_id, grade_period_id)`.

Grades are **updated in place, never deleted**; every change writes an `audit_logs` row
carrying the old and new value (`docs/12`, `docs/19`).

### `attendance`
`class_enlistment_id` FK, `attendance_date` DATE,
`status` VARCHAR(10) (`PRESENT`, `ABSENT`, `LATE`, `EXCUSED`), `remarks` VARCHAR(255),
`recorded_by` FK, `recorded_at` DATETIME.
Unique `(class_enlistment_id, attendance_date)` — one record per student per class day.
No `class_schedule_id` column: the enlistment already names the class, and a second copy
could disagree with it.

---

## 6. Finance

### `fee_types`
`code` unique, `name`, `description`,
`is_per_unit` TINYINT(1) (tuition is typically charged per unit),
`default_amount` DECIMAL(12,2), `is_active`.

### `fee_assessments`
`assessment_number` VARCHAR(20) unique, `application_id` FK, `student_id` FK,
`term_id` FK,
`total_amount` DECIMAL(12,2), `total_adjustments` DECIMAL(12,2) default 0,
`total_paid` DECIMAL(12,2) default 0, `balance` DECIMAL(12,2),
`status` VARCHAR(20), `assessed_by` FK, `assessed_at` DATETIME.

`total_paid` and `balance` are **maintained by `FinanceService` inside the payment
transaction** and are the source of the derived `payment_status` (`docs/26` section 3).

### `fee_items`
`fee_assessment_id` FK, `fee_type_id` FK, `description`,
`quantity` DECIMAL(6,2) (units, when per-unit), `unit_amount` DECIMAL(12,2),
`amount` DECIMAL(12,2).

### `payments`
`transaction_number` VARCHAR(20) unique, `fee_assessment_id` FK, `student_id` FK,
`amount` DECIMAL(12,2), `payment_method` VARCHAR(30) (`Cash`, `Bank`, `Online`, `Other`),
`reference_number` VARCHAR(100) nullable, `paid_at` DATETIME,
`cashier_user_id` FK, `remarks` TEXT,
`is_reversed` TINYINT(1) default 0, `reversed_by_payment_id` FK nullable.

**Payments are never deleted or edited** (`docs/14`). A mistake is corrected by a
reversing entry that points back via `reversed_by_payment_id`.

> **As built (migrations 018, 019).** The receipt number lives on the payment:
> `receipt_number` VARCHAR(20) unique, **null for a reversal**. `payment_submission_id` FK
> unique nullable links a verified online payment. A reversal is its own row with a
> **negative `amount`** and `reversed_payment_id` FK unique nullable pointing at the payment it
> cancels; the original row is never updated, so there is no `is_reversed` flag (a payment is
> reversed when a row points at it). Methods are the labels in `FinanceService`.

### `payment_items`
`payment_id` FK, `fee_item_id` FK, `amount` DECIMAL(12,2) — allocates a payment across
the assessed items.

### `receipts`
`receipt_number` VARCHAR(20) unique, `payment_id` FK unique,
`issued_by` FK, `issued_at` DATETIME, `print_count` INT default 0.

> **As built:** not a separate table. A receipt is one-to-one with a non-reversal payment,
> so its number is `payments.receipt_number`, issued by the same cashier in the same
> transaction. `payment_items` is not built either: a payment applies to the assessment as a
> whole.

### `financial_adjustments`
`fee_assessment_id` FK,
`type` VARCHAR(20) (`DISCOUNT`, `SCHOLARSHIP`, `WAIVER`, `CORRECTION`, `REVERSAL`),
`amount` DECIMAL(12,2) **signed** (negative reduces the balance),
`reason` TEXT **required**, `created_by` FK, `approved_by` FK nullable.

> **As built (migration 019):** `adjustment_number` VARCHAR(20) unique (`ADJ-YYYY-NNNNN`),
> `fee_assessment_id` FK, `type` VARCHAR(30) (`DISCOUNT`, `SCHOLARSHIP`, `WAIVER`,
> `ADDITIONAL_CHARGE`, `CORRECTION`), `amount` DECIMAL(12,2) signed, `reason` VARCHAR(500)
> NOT NULL, `created_by` FK, `created_at`. There is no `REVERSAL` type (reversals are
> payments rows) and no `approved_by` (adjustments take effect immediately; see docs/14).
> `fee_assessments.total_adjustments` holds their sum, maintained by `FinanceService`.

### `document_sequences`
Gapless, collision-free numbering for receipts, transactions, assessments, application
numbers, and student numbers.

`key` VARCHAR(50) unique (`receipt`, `payment`, `assessment`, `adjustment`, `application`, `student`),
`prefix` VARCHAR(10), `period` VARCHAR(10) (usually the year), `current_number` INT.

Numbers are issued by `SELECT ... FOR UPDATE` on this row **inside the same transaction**
as the document being created. Two cashiers clicking at the same instant must never
receive the same receipt number.

---

## 7. Communication and Administration

### `announcements`
`title`, `slug` unique, `content` TEXT, `category` VARCHAR(50),
`image_path` VARCHAR(255) nullable,
`audience` VARCHAR(20) (`EVERYONE`, `TARGETED`),
`is_public` TINYINT(1) — shows on the public website (`docs/06`),
`status` VARCHAR(20) (`DRAFT`, `PUBLISHED`, `ARCHIVED`),
`published_at` DATETIME nullable, `expires_at` DATETIME nullable,
`author_user_id` FK.
Index `(status, published_at, expires_at)`.

> **As built (migrations 007, 020):** also `is_urgent` TINYINT(1), `image_mime`, `image_alt`
> (required with an image), `published_by` FK nullable, `notified_at` DATETIME nullable (set once,
> when the audience was notified), and `recipient_count`. Index `(status, notified_at, published_at)`
> for delivering scheduled announcements. `announcement_targets` (`target_type` `ROLE`, `PROGRAM`,
> or `YEAR_LEVEL`) holds the chosen groups; see docs/16 for how they combine.
> `announcement_dismissals` (`announcement_id`, `user_id`, `dismissed_at`; primary key on the pair)
> records who hid an urgent banner.

### `announcement_targets`
Targeting is multi-valued (`docs/16` allows role, program, and year level), so it is a
pivot, not a column.
`announcement_id` FK, `target_type` VARCHAR(20) (`ROLE`, `PROGRAM`, `YEAR_LEVEL`),
`target_id` BIGINT. Unique `(announcement_id, target_type, target_id)`.

### `notifications`
`user_id` FK, `type` VARCHAR(50), `title`, `body` TEXT,
`link_url` VARCHAR(255) nullable (stored as a **relative** path — see `CLAUDE.md`),
`state` VARCHAR(10) (`UNREAD`, `READ`, `ARCHIVED`), `read_at` DATETIME nullable.
Index `(user_id, state, created_at)`.

### `downloads`
Public documents published by administrators (`docs/06`).
`title`, `description`, `original_filename`, `stored_filename`, `file_size`,
`mime_type`, `download_count` INT, `is_public` TINYINT(1), `uploaded_by` FK.

### `audit_logs`
`user_id` FK nullable, `actor_role` VARCHAR(30), `action` VARCHAR(100),
`module` VARCHAR(50), `target_type` VARCHAR(100) nullable, `target_id` BIGINT nullable,
`old_values` JSON nullable, `new_values` JSON nullable,
`ip_address` VARCHAR(45), `user_agent` VARCHAR(255),
`result` VARCHAR(20) (`SUCCESS`, `FAILURE`), `remarks` TEXT, `created_at` DATETIME.

**Append-only — including for Super Admin.** No `UPDATE` or `DELETE` path exists in the
application. `old_values` / `new_values` must never contain a password, token, or
document content.
Indexes `(user_id, created_at)`, `(module, created_at)`, `(target_type, target_id)`.

### `system_settings`
`key` VARCHAR(100) unique, `value` TEXT, `type` VARCHAR(20)
(`string`, `int`, `bool`, `json`), `group` VARCHAR(50), `description`,
`is_public` TINYINT(1), `updated_by` FK nullable.

### `backups`
`filename`, `path`, `size_bytes` BIGINT, `type` VARCHAR(20) (`Manual`, `Scheduled`),
`status` VARCHAR(20) (`Running`, `Completed`, `Failed`), `checksum` CHAR(64),
`started_at`, `completed_at`, `created_by` FK nullable, `notes` TEXT.

> **As built (migration 021):** `type` is `MANUAL`, `SCHEDULED`, or `PRE_RESTORE`; `status` is
> `RUNNING`, `COMPLETED`, `FAILED`, or `DELETED` (the file was removed, the record kept). Also
> `includes_uploads`, `table_count`, `upload_count`, `verified_at`, `verify_result`, and `error`.
> `filename` is unique. `password_reset_tokens` gained `purpose` (`SETUP` or `RESET`) and
> `created_by` (the administrator who issued the link); `token_hash` is a SHA-256 hex digest.

### `migrations`
`filename` VARCHAR(255) unique, `batch` INT, `applied_at` DATETIME.
Used by `database/migrate.php`. No `updated_at`.

---

## 8. Integrity rules the schema cannot express

These are enforced in the service layer and must be covered by tests:

1. **Section capacity** — confirming an enrollment when `COUNT(enrollments WHERE
   status='ENROLLED')` has reached `sections.capacity` is rejected. Checked inside the
   confirmation transaction with the section row locked, so two simultaneous
   confirmations cannot both take the last seat.
2. **Teacher double-booking** — no teacher may hold two classes with meetings that
   overlap on the same day within the same term.
3. **Room double-booking** — the same, per room.
4. **Student schedule conflict** — a student may not be enlisted in two classes with
   overlapping meetings, and one section's classes may not overlap each other. Never
   overridable.
5. **Prerequisites** — a student may not enlist in a subject whose prerequisites are not
   passed, unless a registrar records an explicit override (written to `audit_logs`).
6. **Maximum units per term** — the larger of the `max_units_per_term` setting and the
   curriculum's regular load for that year and term (docs/28); overrides are
   registrar-only, need a reason, and are audited.
7. **One active academic year** and one active term at a time.
8. **Class must belong to the enrollment context** (`docs/13`) — a `class_enlistment`'s
   `class_schedule.term_id` must equal the `enrollment.term_id`.
9. **One non-terminal application per student per term.**
10. **`payment_status` is derived** and is written only by
    `FinanceService::recalculatePaymentStatus()`.

## 9. Identifier formats

| Identifier | Format | Example |
|---|---|---|
| Student number | `YYYY-NNNNN` (admission year + sequence) | `2026-00184` |
| Application number | `APP-YYYY-NNNNN` | `APP-2026-00291` |
| Assessment number | `ASM-YYYY-NNNNN` | `ASM-2026-00188` |
| Transaction number | `TXN-YYYY-NNNNNN` | `TXN-2026-001043` |
| Receipt number | `OR-YYYY-NNNNNN` | `OR-2026-000517` |

All are issued through `document_sequences` (section 6), never by `MAX(id) + 1`.

## 10. Data protection

The database holds birth certificates, guardian contacts, addresses, and birthdates, so
RA 10173 (Data Privacy Act of 2012) applies.

- Uploaded documents live under `storage/`, are never web-reachable, and are served only
  through a controller route that authorizes the requester and streams the file.
- A student's documents are visible to that student, the Registrar, and Super Admin.
  **Teachers and Cashiers never have access.**
- Every view or download of a student document writes an `audit_logs` row.
- `audit_logs` and backups may contain personal data — backups are stored outside the
  web root with restricted permissions (`docs/20`).
- Retention: student academic records are permanent (registrar requirement). Uploaded
  requirement files are retained for **five years** after the last enrolled term, then
  purged by an administrative action that is itself audited.
- Password hashes, tokens, and document contents are **never** written to
  `audit_logs`, `login_logs`, or application logs.

## 11. General rules

- Primary keys on every table; foreign keys wherever a relationship exists.
- Index every column used for frequent search or filtering, especially the login-log
  and application-queue indexes called out above.
- Store no duplicate authoritative data — derive it, or join.
- Preserve historical records: supersede rows, never overwrite history.
- Use `DECIMAL` for money, appropriate precision everywhere.
- Never store a password in any form other than a hash.
