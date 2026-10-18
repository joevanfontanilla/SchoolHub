# Grading Policy

The original specification required grades but never defined a grading system. This is
the system SchoolHub implements, confirmed for Lyceum of the East Aurora (college). All
grade arithmetic lives in one place, `App\Services\GradeCalculator`, and is covered by
`tests/grade_calculator_test.php`.

## Scale: the Official Grading System

This is the Registrar's **Official Grading System** sheet (Lyceum of the East - Aurora, Inc., Office of the Registrar). It is the single
source for every grade in the system: `GradeCalculator::TABLE`.

A **percentage** becomes the equivalent of the **highest row whose percentage is at or below it**: 92.0 is 1.44, 91.9 is 1.45, 75.0 to
75.9 is 3.00, and **below 75 is 5.00 (failed)**. 100 is the highest; above 100 is refused.

| Equivalent | Lowest percentage | Descriptor |
|---|---|---|
| 1.00 | 100 | Excellent |
| 1.01 | 99.8 | Excellent |
| 1.02 | 99.7 | Excellent |
| 1.03 | 99.6 | Excellent |
| 1.04 | 99.5 | Excellent |
| 1.05 | 99.4 | Excellent |
| 1.06 | 99.3 | Excellent |
| 1.07 | 99.2 | Excellent |
| 1.08 | 99.1 | Excellent |
| 1.09 | 99.0 | Excellent |
| 1.10 | 98.4 | Excellent |
| 1.11 | 98.3 | Excellent |
| 1.12 | 98.2 | Excellent |
| 1.13 | 98.1 | Excellent |
| 1.14 | 98.0 | Excellent |
| 1.15 | 97.4 | Excellent |
| 1.16 | 97.3 | Excellent |
| 1.17 | 97.2 | Excellent |
| 1.18 | 97.1 | Excellent |
| 1.19 | 97.0 | Excellent |
| 1.20 | 96.4 | Excellent |
| 1.21 | 96.3 | Excellent |
| 1.22 | 96.2 | Excellent |
| 1.23 | 96.1 | Excellent |
| 1.24 | 96.0 | Excellent |
| 1.25 | 95.4 | Excellent |
| 1.26 | 95.3 | Excellent |
| 1.27 | 95.2 | Excellent |
| 1.28 | 95.1 | Excellent |
| 1.29 | 95.0 | Excellent |
| 1.30 | 94.4 | Excellent |
| 1.31 | 94.3 | Excellent |
| 1.32 | 94.2 | Excellent |
| 1.33 | 94.1 | Excellent |
| 1.34 | 94.0 | Excellent |
| 1.35 | 93.4 | Excellent |
| 1.36 | 93.3 | Excellent |
| 1.37 | 93.2 | Excellent |
| 1.38 | 93.1 | Excellent |
| 1.39 | 93.0 | Excellent |
| 1.40 | 92.4 | Excellent |
| 1.41 | 92.3 | Excellent |
| 1.42 | 92.2 | Excellent |
| 1.43 | 92.1 | Excellent |
| 1.44 | 92.0 | Excellent |
| 1.45 | 91.4 | Excellent |
| 1.46 | 91.3 | Excellent |
| 1.47 | 91.2 | Excellent |
| 1.48 | 91.1 | Excellent |
| 1.49 | 91.0 | Excellent |
| 1.50 | 90 | Very good |
| 1.60 | 89 | Very good |
| 1.70 | 88 | Very good |
| 1.80 | 87 | Very good |
| 1.90 | 86 | Very good |
| 2.00 | 85 | Good |
| 2.10 | 84 | Good |
| 2.20 | 83 | Good |
| 2.30 | 82 | Good |
| 2.40 | 81 | Good |
| 2.50 | 80 | Satisfactorily |
| 2.60 | 79 | Satisfactorily |
| 2.70 | 78 | Satisfactorily |
| 2.80 | 77 | Satisfactorily |
| 2.90 | 76 | Satisfactorily |
| 3.00 | 75 | Passed |
| 5.00 | below 75 | Failed |

| Mark | Meaning |
|---|---|
| `INC` | Incomplete |
| `OW` | Officially withdrawn |
| `UW` | Unofficially withdrawn |
| `NGA` | No grade due to absences |

### What the teacher types
A **text box**, not a dropdown. The teacher may type:
- a **percentage**, 6 to 100 with up to two decimals (`92.4`): the equivalent (1.40) appears beside the box as they type;
- an **equivalent** from the table (`1.40`, `2.10`, `3.00`, `5.00`): it is stored with the table's percentage (1.40 is 92.4). A typed `5.00`
  carries no percentage, so it counts as **74** in the weighted average (the highest failing percentage);
- a **mark**: `INC`, `OW`, `UW`, or `NGA`.

Anything else (2.30 is fine, 2.35 is not; 101; 3.5) is refused and named. A grade is stored as its equivalent (`grades.grade`) and
its percentage (`grades.percentage`); a mark is stored alone (`grades.remark`).

## Periods and weights

Each term has four grading periods, created by `database/seed.php`, **closed**:

| Period | Default weight |
|---|---|
| Prelim | 20% |
| Midterm | 20% |
| Semi-Finals | 20% |
| Finals | 40% |

The registrar may change a term's weights on **Registrar → Grading periods**. They must
total exactly 100, and they are **fixed once any grade has been recorded in that term**.
The number of periods is not hardcoded — `GradeCalculator` weights whatever periods a term
has — but every term seeded from `GradingService::DEFAULT_PERIODS` gets these four. A term
that already had a complete final recorded before Semi-Finals was introduced keeps its original
three-period scheme, so its past finals never shift (migration 026).

**Every term should have Semi-Finals.** A term without it (Prelim, Midterm, Finals only) gets it by migration 041, and the registrar can
add it on **Registrar → Grading periods** ("Add Semi-Finals period", `GradingService::addSemiFinals`):
- It goes between Midterm and Finals, starts **closed**, and the weights become **20 / 20 / 20 / 40**.
- It is refused when any student's class in that term already has every existing period graded, because that final would change.
  Grades already entered are never touched; a final simply reads "In progress" until Semi-Finals is graded.
- It is refused for a term whose periods are not exactly Prelim, Midterm, and Finals (set those by hand).
- It is audited as `grading.period_added`, with the old and new weights. The teacher's grade sheet shows the new column at once.

## The final grade

The final grade is **computed, never stored**, so a corrected period grade can never leave a stale final behind.

1. If any period is a mark, the final is that mark. The order of precedence is **OW, then UW, then NGA, then INC**.
2. Otherwise, if any period has no grade yet, the final is blank: **In progress**.
3. Otherwise it is the **weighted average of the period percentages**, rounded to **one decimal** (half up), then looked up in the table
   above: that gives the final equivalent, and its percentage is shown with it. An equivalent of 3.00 or better is **Passed**; 5.00 is
   **Failed**.

Example: Prelim 92.4, Midterm 88, Semi-Finals 90, Finals 85 with weights 20/20/20/40 gives
0.20 x 92.4 + 0.20 x 88 + 0.20 x 90 + 0.40 x 85 = 88.08, which is 88.1 to one decimal, so the final is **1.70 (88.1%), Passed**.

A grade saved before percentages existed has none: the table's percentage for its equivalent is used (migration 042 also fills it in).

A class the registrar dropped shows **OW** as its final, whatever grades it had.

"Passed" for prerequisites and curriculum progress (docs/28) means a final of Passed. A Failed, mark, or in-progress final does not count.

## Who does what

| Action | Who |
|---|---|
| Enter or change a grade | The **class's assigned teacher**, only while that period is **open**, only for students **enlisted** in that class. |
| Open or close a period, set weights | The **registrar** (`grade_periods.manage`). |
| Change a grade directly | **Nobody else.** The registrar cannot enter or change grades (docs/04). |

A teacher opening another teacher's class, or a class that does not exist, gets **404**. A
registrar, cashier, or Super Admin calling the grade service gets **403**.

## Releasing grades: submit, then confirm

A student sees a grade only after the Registrar has confirmed it. The unit is **one class's grades for one period** (a *grade submission*,
`grade_submissions`, `GradeSubmissionService`):

| Status | Meaning | Who can act |
|---|---|---|
| **Draft** | The teacher is still entering grades ("Save grades"). Students see nothing. | Teacher edits, while the period is open |
| **Submitted** | The teacher pressed **Submit to Registrar**. Locked for the teacher. Students see "Pending release". | Registrar confirms or returns |
| **Confirmed** | The Registrar confirmed it: **released** to students. Locked. | Registrar can return it |
| **Returned** | The Registrar sent it back with a reason. Hidden from students again. | Teacher edits (even if the period has closed) and resubmits |

- **Submit** needs the period open (unless the sheet was returned) and **a grade or a mark for every enlisted student** in that period. It
  notifies the registrars (`grades.submitted`).
- **Confirm and release** (one sheet, or several at once) notifies the teacher (`grades.confirmed`) and each student who has a grade in it
  (`grades.released`, "[Period] grades are available"). **Return to teacher** needs a reason (5+ characters) and notifies the teacher
  (`grades.returned`); it works on a Submitted or a Confirmed sheet.
- Until a sheet is Confirmed its grades are **left out of everything a student sees and of every rule that depends on a pass**: the period
  columns, the final, the GWA, the academic history, the curriculum checklist, prerequisites and curriculum progress. The Registrar's student
  record shows the grades as entered. A grade with no submission at all (anything saved before this step existed) counts as released;
  migration 043 recorded those as Confirmed.
- Closing a period **no longer releases anything**: only confirmation does. Opening and closing a period stay the Registrar's, and a closed
  period still locks a Draft.
- Audit: `grades.submitted`, `grades.confirmed`, `grades.returned`, each with the class, period, and (for a return) the reason.

## Locking and corrections

- Closing a period **locks** its grades. A teacher's save for a closed period is refused
  and changes nothing.
- Students are notified when a sheet is **confirmed** (`grades.released`), not when a period closes.
- **To correct a released grade:** the registrar uses **Return to teacher** on that class's period (audited as `grades.returned`); the grades
  are hidden from students again, the teacher makes the change and resubmits, and the registrar confirms. Reopening the whole period is no
  longer needed.
- A save is **all or nothing**: if any entry on the sheet is invalid, or belongs to a
  student not enlisted in the class, nothing is saved and each bad entry is named.
- A blank entry leaves that grade unchanged. A grade is never deleted.

## Audit

| Event | Audit action | Recorded |
|---|---|---|
| First grade for a student and period | `grade.recorded` | enlistment, period, equivalent, percentage, or mark |
| A grade changed | `grade.changed` | **old and new** equivalent, percentage, and mark |
| Period opened or closed | `grading.period_opened` / `grading.period_closed` | period, term |
| Weights changed | `grading.weights_changed` | old and new weights |

An unchanged re-save writes nothing.

## Not yet implemented

- A deadline for completing an `INC`, and automatic conversion of an unresolved INC to
  5.00. The registrar tracks these manually.
- Honors computation (the GWA itself is below).
- Printable grade sheets and report cards (reporting phase).

## General weighted average (GWA)
Shown to the student per term and cumulatively (Academic Records). **School policy to confirm.**

- GWA = Σ(final grade × units) ÷ Σ(units), rounded half up to two decimals.
- Counted: classes with a numeric final (the equivalent) — passing grades and 5.00 failures.
- Not counted: INC, OW, UW, NGA, and classes still in progress; and **NSTP** (including CWTS, LTS,
  ROTC) and **physical education** (PATH-FIT, PE, PED), which by common Philippine college
  practice carry no weight in the GWA. `GradeCalculator::countsTowardGwa()` holds that rule.

## Official Grading System: how it came in (migration 042)
- `grades.percentage` DECIMAL(5,2) is new. The old remark `DRP` became `OW`.
- Grades saved on the earlier 0.25 scale got a percentage: 1.00, 1.25, 1.50, 2.00, 2.50, 3.00 and 5.00 take the table's (5.00 takes 74); the three
  that are not official equivalents take the midpoint of their neighbours and are moved to the equivalent that percentage falls in:
  1.75 became 1.80 (87.5), 2.25 became 2.30 (82.5), 2.75 became 2.80 (77.5). Nothing else changed.
- The final of a class is now computed from percentages, so a final computed before and after can differ by a step.
