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

**The registrar sets these dates** (as built) on the Academic Calendar
(`/registrar/calendar`, `academic.manage`, `AcademicCalendarService`): each semester's start
and end, and its enrollment window. The window is what actually opens and closes enrollment
for the term, so it is set by the school, not fixed by the installer. The registrar also adds
the next academic year there — its 1st and 2nd semesters (and an optional summer), each with
its own dates; the standard closed grading periods are created for every new term. Every
change is audited (`academic.term_dates_set`, `academic.year_added`).

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

## School academic calendar (as built)

Separate from the enrollment window above, the school publishes a human-readable
**academic calendar** — the printed "Collegiate Academic Calendar" of activities
(class start, enrolment days, examination periods, Foundation Day) and a "Dates of No
Classes" list of holidays, grouped by section under an academic year (e.g. A.Y. 2026-2027).

- **Data (`calendar_events`).** `academic_year`, `category` (First Semester, Second
  Semester, Summer, Dates of No Classes, or any custom section), `date_label` (free text,
  because printed calendars use ranges like "Aug 17,18,20 & 22, 2026"), `sort_date` (the
  primary date, used only for ordering), and `activity`.
- **Reading.** `CalendarEvent` orders categories First Semester → Second Semester → Summer →
  everything else, then by `sort_date`. Shown on the public **`/calendar`** page and the
  portal **`/portal/calendar`** page, both rendering the shared `partials/calendar-view.php`.
- **Editing.** `SchoolCalendarService` (permission `calendar.manage`, held by the **registrar
  and the Super Admin** by default) creates, updates, and deletes entries at
  **`/manage/calendar`**; every change is audited (`calendar.event_*`). This is not the same
  as `AcademicCalendarService`, which sets a term's dates and enrollment window.

## Walk-in enrollment (as built)

An applicant who comes to the registrar's office is enrolled **by the registrar on their
behalf**, through the same application, review, approval, assessment, payment, and
confirmation steps as an online applicant. Only the way in differs.

- **Who.** The Registrar, with permission `enrollment.walkin`, at **Registrar → Walk-in
  registration** (`/registrar/walk-in`). Super Admin is not admitted (docs/04).
- **Step 1, intake form.** One form collects the applicant's name and email, the profile
  fields a submission requires (see Profile Completeness), one guardian marked as the
  emergency contact, and the term, program, year level, and student type. Submitting it, in
  one transaction, creates the **student account** (status `Pending`, an unusable random
  password), the student record and profile, and a `DRAFT` application with `source =
  walk_in` and `created_by` = the registrar. The enrollment window applies exactly as it does
  online: there is no override for a closed term.
  **Every mistake is reported in one pass**: the account, the profile, the guardian, and the enrollment
  choices are all checked before anything is written, so the registrar fixes the form once. The guardian
  error is attached to the guardian's fields and says "the applicant's emergency contact". The form also
  **refuses a program, year level, and term that has no section** (with a link to Sections), instead of
  leaving a draft that cannot be approved, and **warns when the name and date of birth match a student already
  registered** (the registrar can enroll that student instead, or tick that it is a different person).
- **The applicant's login.** The registrar is shown a **one-time set-up link** (the same
  mechanism as a new staff account, docs/07) to hand over or email, and the applicant chooses
  their own password. No password is ever shown, stored, or emailed. A lost link can be
  re-issued, from step 2 or later from the application's **Applicant** card, for as long as the
  account is still `Pending`; the old link stops working. Only walk-in applicants, never online
  ones. The link is shown on its own page (`/registrar/walk-in/{id}/link`) reached by a redirect, never
  as the reply to the form, so a refresh or the Back button cannot resubmit anything; it is read from the
  session once and then gone, and opening the page again explains that it was shown once.
- **Step 2, documents.** The registrar ticks each document **received in person**. A ticked
  document becomes `VERIFIED` at once, with the registrar and time recorded and
  `received_in_person = 1`; it has no uploaded file and is shown as "Received in person".
  The registrar sees the originals, so no separate review is needed. Unticked documents stay
  `NOT_SUBMITTED`; the applicant can upload them later once they can sign in.
  The registrar can also **upload the applicant's photo** here; it is saved as their profile photo, so the
  e-ID card (docs/29) works straight away.
- **Submit.** Step 2 then moves the application `DRAFT` to `SUBMITTED` (docs/26 rule 2) with
  the registrar as actor, under the same preconditions (complete profile, subjects chosen,
  every required document in), and **straight on to `UNDER_REVIEW`**: the registrar already verified the
  documents at the counter, so there is no separate "Start review" click. Both moves are in the status
  history. The registrar lands on the application page to choose the section and approve. A required
  document that is missing refuses the submit and keeps the ticks; "Save and finish later" keeps the walk-in
  under "Walk-ins not yet submitted". The registrar who created the walk-in is **not** sent the "New
  application" alert for it; the other registrars are.
- **A student who is already registered** (Continuing or Returning, or anyone with an account) is found with
  **Find an existing student** on the intake page, by name, student number, or email. The registrar then
  enters only the enrollment choices; the application is created on the **existing account** (no new account,
  no set-up link), as a walk-in draft, and continues at step 2.
- **Cancel.** A walk-in left as a `DRAFT` can be cancelled by the registrar (docs/26 rule 10).
- **Data.** `enrollment_applications.source` (`online` | `walk_in`, default `online`) and
  `created_by`; `enrollment_requirements.received_in_person`. The application queue shows a
  "Walk-in" badge.
- **Audit.** `walkin.registered` (no personal data), `walkin.document_received`, and the
  usual `enrollment.*` status entries.
