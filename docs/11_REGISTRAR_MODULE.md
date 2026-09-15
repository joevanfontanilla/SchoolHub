# Registrar Module

## Dashboard
- New applications
- Pending review
- Incomplete requirements
- Approved applications
- Enrolled students
- Enrollment statistics

## Functions
- Application queue
- Student records
- Requirement verification
- Enrollment approval
- Enrollment correction requests
- Program management
- Subject management
- Section management
- Class assignment
- Enrollment history
- Registrar reports

## Application Review
Registrar should be able to see:
- Student information
- Academic selection
- Uploaded requirements
- Previous enrollment history
- Review timeline
- Remarks
- Current status

## Acceptance
A registrar action must update the application status and create an audit/event record.

## Implemented Behavior

### Queue
`/registrar/applications` lists every non-draft application, **oldest submission first**.
The default filter is **Needs action** (`SUBMITTED` and `UNDER_REVIEW`); other filters cover
returned, approved-awaiting-confirmation, enrolled, rejected, and cancelled, plus term,
program, and a search by name, email, or application number. Drafts are the student's
private work and never appear.

### Review
Opening an application never changes it. The registrar presses **Start review** (rule 3),
which marks every submitted document `UNDER_REVIEW`. While the application is under review,
each document can be **verified**, **rejected**, or marked **needs correction**; the last two
require a note to the student and notify them. A decision can be changed until the
application itself is decided. Documents with no upload cannot be reviewed.

### Decisions
| Action | Rule | Requires |
|---|---|---|
| Return: replace documents | 4 | a reason, and a required document missing or any document turned back |
| Return: correct details | 5 | a reason |
| Approve | 8 | every required document verified |
| Reject | 9 | a reason |
| Cancel | 11, 12 | a reason for rule 12; refused once paid or enrolled |

Every decision notifies the student and appears on their timeline with the reason.

### Enrollment confirmation
`/registrar/applications/{id}/confirm` lists only active sections for the application's
program, year level, and term, with seats taken. Confirming, in one transaction:
assigns the section (refused when full), issues the **student number** if the student has
none, marks the student `Enrolled`, creates the enrollment record, moves
`enrollment_state` to `ENROLLED`, and notifies the student. It is refused until the
**payment policy** is met (`docs/26` section 4).

### Student records
The registrar can search students and correct a record, including the legal name that
the student can no longer edit after submitting. The correction is audited by field name,
never by value.

### Access
Every registrar route requires the Registrar role **and** a specific permission
(`enrollment.review`, `enrollment.approve`, `enrollment.confirm`, `requirements.verify`,
`students.view`, `students.manage`, `academic.manage`, `reports.registrar`). Super Admin is not admitted.

### Documents
The application review lists the documents **first** while the application is submitted or
under review, each with Preview (Previous/Next through all of them) and Download. The student
record has a **Documents** section: the current files of every submitted application, official
receipts, and statements of account, each previewable. Payment proofs stay with the cashier.

### Reports
Enrollment list (students by program, year level, and section), applicants by status,
requirements status, and enrollment history, each on screen, printable, and as an audited
CSV export, at `/registrar/reports`; see docs/15.
