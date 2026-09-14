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
Every section has a capacity. Enlistment stops when the section is full. The check runs
inside the enlistment transaction with the section row locked, so two simultaneous
enlistments cannot both claim the last seat.

## Load Limits
A student's total enlisted units for a term may not exceed the `max_units_per_term`
system setting. A registrar may override this; the override is audited.

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
- Enlisting enrolled students into class schedules, with the conflict, prerequisite, and
  load checks above, arrives with class assignment.
