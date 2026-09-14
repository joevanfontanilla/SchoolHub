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
