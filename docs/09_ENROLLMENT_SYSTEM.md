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
