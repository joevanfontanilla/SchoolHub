# Enrollment Status Transitions

This document defines the rule that `docs/09_ENROLLMENT_SYSTEM.md` requires but does
not specify: *"Only valid status transitions may be performed."* It is authoritative
for every status change in the enrollment workflow.

## 1. Why three axes instead of one enum

`docs/09` lists twelve statuses in a single list, mixing **review states** with
**payment states**. These are independent: an application can be `APPROVED` **and**
`PARTIALLY_PAID` at the same moment, which a single column cannot express.

The twelve values are therefore split across three columns on
`enrollment_applications`:

| Axis | Column | Meaning | Who changes it |
|---|---|---|---|
| Review | `application_status` | Where the application sits in registrar review | Student and Registrar, by explicit action |
| Payment | `payment_status` | How much of the assessment has been paid | **Derived** — never set by hand |
| Enrollment | `enrollment_state` | Whether the student is officially enrolled for the term | Registrar, on confirmation |

## 2. `application_status`

| Value | Meaning |
|---|---|
| `DRAFT` | Student is still completing the form. Not visible to the registrar. |
| `SUBMITTED` | Student has submitted. Waiting in the registrar queue. |
| `UNDER_REVIEW` | A registrar has opened it for review. |
| `REQUIREMENTS_INCOMPLETE` | Documents are missing or rejected. Returned to the student. |
| `CORRECTION_REQUIRED` | Form data is wrong. Returned to the student for editing. |
| `APPROVED` | Registrar approved. A fee assessment is generated. |
| `REJECTED` | Terminal. Application denied, reason recorded. |
| `CANCELLED` | Terminal. Withdrawn before approval. |

### Transition matrix

Any pair not listed here is **invalid** and must be rejected by the service layer
with a validation error — not silently ignored.

| # | From | To | Permitted actor | Preconditions | Side effects |
|---|---|---|---|---|---|
| 1 | *(none)* | `DRAFT` | Student (own) | No existing non-terminal application for the same academic year + term | Application row created |
| 2 | `DRAFT` | `SUBMITTED` | Student (own) | Profile complete; program, year level, term selected; every **required** requirement has a file `SUBMITTED` | Notify registrar queue; lock student edits |
| 3 | `SUBMITTED` | `UNDER_REVIEW` | Registrar | — | Reviewer recorded on the application |
| 4 | `UNDER_REVIEW` | `REQUIREMENTS_INCOMPLETE` | Registrar | At least one requirement is `NOT_SUBMITTED`, `REJECTED`, or `CORRECTION_REQUIRED` | Remarks required; unlock student uploads; notify student |
| 5 | `UNDER_REVIEW` | `CORRECTION_REQUIRED` | Registrar | — | Remarks required; unlock student form edits; notify student |
| 6 | `REQUIREMENTS_INCOMPLETE` | `SUBMITTED` | Student (own) | All required requirements `SUBMITTED` or `VERIFIED` | Re-queue; lock student edits |
| 7 | `CORRECTION_REQUIRED` | `SUBMITTED` | Student (own) | Form passes validation | Re-queue; lock student edits |
| 8 | `UNDER_REVIEW` | `APPROVED` | Registrar | **Every required requirement is `VERIFIED`** | Generate fee assessment; `payment_status` → `PENDING`; notify student |
| 9 | `UNDER_REVIEW` | `REJECTED` | Registrar | Reason required | Notify student. Terminal. |
| 10 | `DRAFT` | `CANCELLED` | Student (own) | — | Terminal |
| 11 | `SUBMITTED`, `UNDER_REVIEW`, `REQUIREMENTS_INCOMPLETE`, `CORRECTION_REQUIRED` | `CANCELLED` | Student (own) or Registrar | — | Notify the other party. Terminal. |
| 12 | `APPROVED` | `CANCELLED` | Registrar | **No payment recorded** (`payment_status` is `PENDING`) | Void the assessment; notify student. Terminal. |

**Terminal states:** `REJECTED` and `CANCELLED`. Nothing leaves them. A student who
needs to try again files a **new** application; the old one stays for history.

**Once `APPROVED`,** `application_status` no longer changes (except transition 12).
Progress after approval is tracked by `payment_status` and `enrollment_state`.

## 3. `payment_status` — derived, never assigned

Recomputed after every fee assessment, payment, or financial adjustment, inside the
same transaction:

| Value | Condition |
|---|---|
| `NOT_ASSESSED` | No fee assessment exists |
| `PENDING` | Assessment exists, `total_paid` = 0 |
| `PARTIAL` | `0 < total_paid < total_due` |
| `PAID` | `total_paid >= total_due` |

`total_due` is the assessment total plus adjustments. No code path may write this
column directly — it is always the output of a single
`FinanceService::recalculatePaymentStatus($applicationId)`.

## 4. `enrollment_state`

| Value | Meaning |
|---|---|
| `NOT_ENROLLED` | Default |
| `ENROLLED` | Officially enrolled for the term; `enrollments` row created |

| From | To | Actor | Preconditions | Side effects |
|---|---|---|---|---|
| `NOT_ENROLLED` | `ENROLLED` | Registrar | `application_status` = `APPROVED` **and** the payment policy is satisfied | Create `enrollments` row; assign section; create `class_enlistments`; notify student |

The payment threshold is policy-driven, read from the `enrollment_payment_policy`
system setting (`FULL` \| `MINIMUM_DOWNPAYMENT` \| `NONE`), because `docs/09` says only
"pays according to school policy". Default: `MINIMUM_DOWNPAYMENT`.

`DROPPED` / `WITHDRAWN` is deliberately **not** in v1.0 — dropping is not listed in
`docs/03_SCOPE.md`. Add it only with approval, as a new state, never by deleting the
enrollment record.

## 5. Enforcement rules

1. Transitions live in **one place** — `EnrollmentStatusService` — with the matrix
   above encoded as a `canTransition(from, to, actor)` table. Controllers never write
   a status column directly.
2. Every transition runs in a **`DB` transaction** together with its side effects.
3. Every transition writes one `enrollment_status_history` row:
   application id, axis, previous value, new value, actor user id, actor role,
   remarks, timestamp.
4. Every transition writes an `audit_logs` row (`docs/19`).
5. Preconditions are re-checked **server-side at the moment of the transition** — a
   stale form or a replayed request must not slip past a check that passed earlier.
6. An invalid transition returns a validation error and changes nothing.
7. Remarks are **mandatory** for transitions 4, 5, 9, and 12.

## 6. What each state unlocks for the student
Restated from rules 1, 4, and 5, and enforced by `EnrollmentApplication::UPLOADS_UNLOCKED`
and `EDITS_UNLOCKED`:

| State | Upload documents | Edit program / year level / previous school |
|---|---|---|
| `DRAFT` | Yes | Yes |
| `REQUIREMENTS_INCOMPLETE` | Yes | No |
| `CORRECTION_REQUIRED` | No | Yes |
| every other state | No | No |

## 7. Actors
Super Admin appears in **no** rule. It administers the system but is not a substitute
registrar (`docs/04`), so it cannot review, approve, reject, or cancel applications.

## 8. Not yet wired
- The side effect of rule 8 (generate the fee assessment, `payment_status` to `PENDING`)
  arrives with the finance module. Until then approval sets only `decided_at`.
- The `enrollment_state` transition (`NOT_ENROLLED` to `ENROLLED`) arrives with enrollment
  confirmation, which also issues the student number.
