# Grading Policy

The original specification required grades but never defined a grading system. This is
the system SchoolHub implements, confirmed for Lyceum of the East Aurora (college). All
grade arithmetic lives in one place, `App\Services\GradeCalculator`, and is covered by
`tests/grade_calculator_test.php`.

## Scale

| Entry | Meaning |
|---|---|
| `1.00` – `3.00`, in steps of 0.25 | Passing. 1.00 is the highest grade, 3.00 the lowest pass. |
| `5.00` | Failed. |
| `INC` | Incomplete. |
| `DRP` | Dropped. |

Nothing else is accepted: 2.30, 4.00, 0.75, and 3.25 are refused. `1.5` and `2` are
accepted and stored as 1.50 and 2.00.

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
that already had grades recorded before Semi-Finals was introduced keeps its original
three-period scheme, so its past finals never shift (migration 026).

## The final grade

The final grade is **computed, never stored**, so a corrected period grade can never
leave a stale final behind.

1. If any period is `DRP`, the final is **DRP**.
2. Otherwise, if any period is `INC`, the final is **INC**.
3. Otherwise, if any period has no grade yet, the final is blank: **In progress**.
4. Otherwise it is the weighted average of the period grades:
   - an average **above 3.00 is 5.00 (Failed)**. It is never rounded down into a pass;
     3.10 fails.
   - an average of **3.00 or better** is rounded to the nearest 0.25 step. A value exactly
     halfway between two steps goes to the **higher (worse) number**: 1.625 becomes 1.75.
     The result is **Passed**.

The average is first rounded to the hundredth (so 3.004 counts as 3.00, and 3.005 as
3.01, which fails); the step rounding is then done in whole hundredths, not floating
point.

Example: Prelim 1.75, Midterm 2.00, Semi-Finals 1.75, Finals 1.50 gives
0.20 × 1.75 + 0.20 × 2.00 + 0.20 × 1.75 + 0.40 × 1.50 = 1.70, which rounds to **1.75, Passed**.

A class the registrar dropped shows **DRP** as its final, whatever grades it had.

"Passed" for prerequisites and curriculum progress (docs/28) means a final of Passed. A
Failed, INC, DRP, or in-progress final does not count.

## Who does what

| Action | Who |
|---|---|
| Enter or change a grade | The **class's assigned teacher**, only while that period is **open**, only for students **enlisted** in that class. |
| Open or close a period, set weights | The **registrar** (`grade_periods.manage`). |
| Change a grade directly | **Nobody else.** The registrar cannot enter or change grades (docs/04). |

A teacher opening another teacher's class, or a class that does not exist, gets **404**. A
registrar, cashier, or Super Admin calling the grade service gets **403**.

## Locking and corrections

- Closing a period **locks** its grades. A teacher's save for a closed period is refused
  and changes nothing.
- When a period is closed, each student who has a grade in it is notified
  (`grades.released`).
- **To correct a locked grade:** the registrar reopens the period (audited as
  `grading.period_opened`), the teacher makes the change, and the registrar closes the
  period again.
- A save is **all or nothing**: if any entry on the sheet is invalid, or belongs to a
  student not enlisted in the class, nothing is saved and each bad entry is named.
- A blank entry leaves that grade unchanged. A grade is never deleted.

## Audit

| Event | Audit action | Recorded |
|---|---|---|
| First grade for a student and period | `grade.recorded` | enlistment, period, grade or remark |
| A grade changed | `grade.changed` | **old and new** grade and remark |
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
- Counted: classes with a numeric final — passing grades and 5.00 failures.
- Not counted: INC, DRP, and classes still in progress; and **NSTP** (including CWTS, LTS,
  ROTC) and **physical education** (PATH-FIT, PE, PED), which by common Philippine college
  practice carry no weight in the GWA. `GradeCalculator::countsTowardGwa()` holds that rule.
