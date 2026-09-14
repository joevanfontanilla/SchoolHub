# Enrollment System

## Enrollment Workflow
1. Student creates account.
2. Student completes profile.
3. Student selects academic year and semester.
4. Student selects program/course and year level.
5. Student completes enrollment form.
6. Student uploads requirements.
7. Student reviews information.
8. Student submits application.
9. Registrar reviews.
10. Registrar verifies requirements.
11. Registrar approves, rejects, or requests correction.
12. Approved application receives fee assessment.
13. Student pays according to school policy.
14. Payment status is recorded.
15. Enrollment is confirmed.
16. Student is assigned to section/classes.

## Application Statuses
- DRAFT
- SUBMITTED
- UNDER_REVIEW
- REQUIREMENTS_INCOMPLETE
- CORRECTION_REQUIRED
- APPROVED
- REJECTED
- PAYMENT_PENDING
- PARTIALLY_PAID
- FULLY_PAID
- ENROLLED
- CANCELLED

## Status Rule
Only valid status transitions may be performed. Every transition should record who performed it, when, previous status, new status, and optional remarks.

## Registration (implemented)
- Open self-registration: first, middle, and last name; email; password; and a required
  data privacy consent (RA 10173). The account is `Active` immediately and signed in.
- Protections in place of email verification (planned for when outgoing mail is set up):
  a per-IP limit of 5 sign-ups per hour (`registration_limit_per_hour` setting) and a hidden
  honeypot field.

## Student Number
An applicant is identified by an **application number** (`APP-YYYY-NNNNN`). The official
**student number** is issued only when enrollment is confirmed, so rejected or abandoned
applicants never consume one.

## Enrollment Window
A student may apply only for a term whose `enrollment_opens_at` … `enrollment_closes_at`
window contains the current time, and may hold only one non-terminal application per term.
A cancelled or rejected application does not block a new one; it stays on record.

## Profile Completeness
Submitting requires a complete profile: date of birth, sex, civil status, mobile number,
barangay, city or municipality, province, nationality, and at least one guardian marked as
emergency contact. The legal name can be edited only until an application is submitted.

## Student Type
The student type decides which documents are required, so it is fixed once the draft is
created. Program, year level, and previous school remain editable while the application is
`DRAFT` or `CORRECTION_REQUIRED`.

---

## Enrollment flow as built

| # | Who | What happens |
|---|---|---|
| 1 | Student | Registers, completes the profile. |
| 2 | Student | Starts an application: term, program, year level, student type. |
| 3 | System | **Pre-checks the regular load**: every subject of the program's curriculum for that year level and term that the student can take. |
| 4 | Student | Reviews the subjects, unticks any not taken this term, uploads the required documents, submits. |
| 5 | Registrar | Reviews, verifies the documents, and **approves with a section**, or returns or rejects the application. |
| 6 | System | Issues the **fee assessment** from the chosen subjects and the fee schedule (docs/14). The student is notified. |
| 7 | Student | Reviews the statement of account under **Payments & Accounts** and **pays online**: sends the amount, reference number, and proof (screenshot or PDF). |
| 8 | Cashier | Checks the proof, then **verifies** it (a payment and an official receipt are created) or **rejects** it with a reason. |
| 9 | System | Once the verified payments meet the payment policy (default: a 20% down payment), the student is **officially enrolled**: student number issued, placed in the section, enlisted in the classes for the chosen subjects. |
| 10 | Student | Sees the subjects, schedule (with each class's teacher and room), receipts, and balance, and can **print the class schedule** and the statement of account. |

### Subject selection (`SubjectSelectionService`)
- Only the student's **own program curriculum** subjects for the **year level and term** of
  the application are offered (docs/28). The curriculum is the student's own when it is for
  the same program, otherwise the program's current curriculum.
- A subject the student has **already passed** is not offered again.
- A subject whose **prerequisite is not passed yet** is shown but locked, with the reason
  ("Requires CORE 1 first."). **First-year, first-semester subjects have no prerequisites in
  any of the six curricula**, so a new student can take the whole term. First-year,
  second-semester subjects such as CORE 4, NSTP 02, and PATH-FIT 2 require the first-semester
  subject; the registrar can still enlist a student with an audited override (docs/13).
- A progress prerequisite (an internship's "80% of the course") locks a subject the same way.
- The total may not exceed the unit limit (the setting, or the curriculum's regular load).
- Choosing at least one subject is required to submit (docs/26 rules 2 and 7). Subjects can
  be changed only while the form is editable (`DRAFT`, `CORRECTION_REQUIRED`); changing the
  program or year level re-selects the regular load.

### Section and enlistment
- The registrar picks the section when approving; approval is refused without one, or with a
  section of another program, year level, or term, an inactive one, or a full one.
- On enrollment the student is enlisted **only in the section's classes for the chosen
  subjects**. A chosen subject the section has no class for is simply not enlisted; the
  registrar can add a class from another section. Enrollments made before subject selection
  existed keep block enlistment (every class of the section).
- Every class has a teacher (docs/13), so the printed schedule names one for each subject.

### Automatic enrollment
`EnrollmentConfirmationService::confirmIfReady()` runs after approval (so a `NONE` policy
enrolls at once) and after every payment. It enrolls only when the application is approved,
has a section, and meets the payment policy, checking everything **before** writing. If the
section filled up in the meantime, nothing is written and the registrar is notified to
confirm into another section by hand (Registrar → application → Confirm enrollment).
