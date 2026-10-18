# Academic Management

## Academic Structure
Academic Year → Semester/Term → Program → Year Level → Section → Subject → Class Schedule → Teacher → Students

## Manageable Entities
- Academic years
- Terms/semesters
- Programs
- Year levels
- Subjects
- Sections
- Rooms
- Schedules
- Class assignments

## Student Academic History
Store historical enrollment and academic records so previous years/terms remain available.

## Data Integrity
A student must not be assigned to a class that does not belong to the student's active enrollment context.

## Units and Prerequisites
Each subject carries a unit value. A subject may declare prerequisite subjects; a
student may not enlist in it until those are passed. A registrar may override a
prerequisite, and the override is recorded in the audit log.

## Scheduling Conflict Rules
The following are rejected at the time of assignment, not merely warned about:

- A teacher assigned to two class schedules whose times overlap on the same day within
  the same term.
- A room booked by two class schedules whose times overlap on the same day within the
  same term.
- A student enlisted in two class schedules whose times overlap.

## Capacity
Every section has a capacity. It is enforced when enrollment is **confirmed** into the
section (docs/26 section 4), inside the confirmation transaction with the section row
locked, so two simultaneous confirmations cannot both claim the last seat. Enlisting an
already-enrolled student in a class of another section does not take a seat there.

## Load Limits
A student's total enlisted units for a term may not exceed the larger of the
`max_units_per_term` system setting and the regular load their curriculum prescribes for
that year level and term (docs/28). A registrar may override this; the override is audited.

## Active Period
Exactly one academic year and one term may be active at a time.

## Implemented Catalog Rules
- Program and subject codes are unique and stored upper-case.
- Subject units are greater than 0 and at most 10, with at most one decimal place.
- **Prerequisites** may not include the subject itself, or any subject that already
  requires it directly or through a chain; the save is refused and names the loop.
- A **section** belongs to one program, year level, and term; its code is unique within
  the term. Capacity is 1 to 500 and can never be set below the number already enrolled.
  Once students are enrolled, its program, year level, and term cannot change.
- An adviser, when set, must be an active teacher.
- Programs, subjects, and sections are **deactivated, never deleted**.
- Subjects belong to a **curriculum**, and subject codes are unique within a curriculum,
  not school-wide. Curricula, their import, and the progress prerequisite are defined in
  **`docs/28_CURRICULA.md`**.

## Classes (Phase 4)

A **class** is one subject taught to one section in one term (`class_schedules`), with
one or more weekly **meetings** (`class_meetings`: day, start, end, room). A subject that
meets Monday and Wednesday is one class, with one roster, one grade record per student,
and one attendance sheet.

Built by the registrar on **Sections → Classes** (`academic.assign`), through
`ClassScheduleService`:

- The subject must be active and belong to the section's program (or be a general
  subject). A section has each subject at most once. Once a class exists its subject is
  fixed; its teacher and meetings can change.
- The teacher, when set, must be an active teacher account.
- Meetings run between 6:00 AM and 9:00 PM, end after they start, and must not overlap
  each other.
- **Conflicts are refused, never merely warned about**, and the message names the clashing
  class, section, day, and time. Checked on every save, including a teacher change, against
  the other active classes of the same term:
  - the same teacher;
  - the same room;
  - another class of the same section;
  - another class that an enlisted student already takes.

  Two meetings overlap when they share a day and `a.start < b.end AND b.start < a.end`,
  so back-to-back classes (8:00–9:00, then 9:00–10:00) are allowed. Saves within a term
  are serialized by locking the term row, so two saves cannot both pass the check.
- A class that has grades or attendance **cannot be closed**. An untaught class can be
  closed; its enlistments become DROPPED. Classes are never deleted.
- Rooms (`/registrar/rooms`, `academic.manage`) are deactivated, never deleted.

## Enlistment

`EnlistmentService` is the only writer of `class_enlistments`.

- **Block sections.** Confirming enrollment enlists the student in every active class of
  the section, in the same transaction. Adding a class to a section enlists its
  already-enrolled students.
- A class the student cannot take yet is **skipped and reported, never forced**:
  - a prerequisite not passed;
  - curriculum progress not reached (docs/28);
  - the unit limit exceeded;
  - a time conflict.

  The registrar sees each skipped class, and why, on the student's record.
- The registrar can **add** a class from any section of the student's term. Prerequisite,
  progress, and load problems can be overridden with a **reason**, which is stored on the
  enlistment and in the audit log. **A time conflict can never be overridden.**
- The registrar can **drop** a class, with a required reason. The enlistment is kept as
  DROPPED, its final shows OW, and re-adding the class revives the same row. Block
  enlistment never re-adds a class the registrar dropped.
- Every add, drop, and close recomputes `enrollments.total_units`.

## Student Academic History (as built)

`AcademicRecordService::history()` returns every term the student was enrolled in, newest
first, with each class, its period grades, and the final grade computed by the grading
policy (docs/27). Past terms stay readable. Students see their own at `/student/history`;
the registrar sees it on the student record.
