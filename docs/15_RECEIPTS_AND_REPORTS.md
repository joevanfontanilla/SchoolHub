# Receipts and Reports

## Receipt
A receipt should contain:
- School name/logo
- Receipt/transaction number
- Student
- Student number
- Date
- Payment breakdown
- Total amount
- Payment method
- Cashier
- Remarks if applicable

## Registrar Reports
- Enrollment list
- Applicants by status
- Requirements status
- Students by program/year/section
- Enrollment history

## Teacher Reports
- Class list
- Attendance
- Grades

## Cashier Reports
- Daily collections
- Monthly collections
- Payment history
- Outstanding balances
- Student account statement

## Export
Where appropriate, reports should support PDF and spreadsheet-compatible export.

---

## As built: printable documents
HTML pages with print styles, saved as PDF from the browser's print dialog (docs/00: no
PDF library). Each has the school letterhead.

| Document | URL | Who may open it |
|---|---|---|
| Official receipt | `/receipts/{id}` | The student it belongs to; cashier; registrar |
| Statement of account | `/statements/{id}` | The student it belongs to; cashier; registrar |
| Class schedule | `/student/schedule/print` | The signed-in student, for their own enrolled terms |
| Payment proof | `/payment-proofs/{id}` | The student who sent it; cashier |

Receipts, statements, and class schedules open in the **preview window** (docs/10) from every
page that lists them, with Print and Open in new tab. For that, `?embed=1` renders the printable
page without its toolbar and allows this site, and only this site, to frame it
(`Controller::printView()`, `Response::allowFramingBySelf()`); the page is authorized exactly as
when opened directly.

Another student's receipt, statement, or proof is a **404**; a role not listed is a **403**
(`FinanceAccessPolicy`). The receipt shows the receipt and transaction numbers, date,
student and student number, program, method, reference number, amount, total assessment,
adjustments made before that payment, the balance right after it, and the cashier. A
reversed payment's receipt still opens, marked **REVERSED** with the date and reason. A
reversal entry has no receipt, so `/receipts/{id}` for it is a 404. The statement of account
lists the fee lines, each adjustment with its reason, the amount due, and every payment and
reversal. The class schedule lists every class with
its days, times, room, section, and **teacher**, and the week at a glance.

## As built: cashier reports (`/cashier/reports`, `reports.cashier`)

| Report | Screen | Print | CSV |
|---|---|---|---|
| **Collections**: a date range (default: this month so far; at most one year) | `/cashier/reports/collections` | `…/collections/print` | `…/collections/export` |
| **Outstanding balances**: active assessments that still owe, optionally by term and program | `/cashier/reports/outstanding` | `…/outstanding/print` | `…/outstanding/export` |
| **Account list**: every active account in a term, grouped by program and year level like the spreadsheet tabs | `/cashier/reports/account-list` | `…/account-list/print` | `…/account-list/export` |

- **Collections** are dated by when the cashier recorded or verified the payment
  (`payments.created_at`). A reversal is a negative line on the day it was made, so a
  period's net always agrees with the accounts. The report shows net, gross, reversals, a
  column chart (one bar per day for up to 31 days, otherwise per month), totals by payment
  method, and every entry. This covers daily and monthly collections and payment history.
- **Outstanding balances** lists the amount due, paid, balance, payment status, last payment,
  and whether an enrolled student is below the payment policy, largest balance first.
- **Account list** is the spreadsheet tab as a report (docs/14, Account list): filter by term
  (default: the term most recently assessed), program, and year level. Each program and year
  level is its own table, numbered from 1, with its totals; a grand total closes the report. Per
  account: units, tuition, miscellaneous, discount, amount due, paid, balance, and the balance of
  each installment (Prelims, Midterm, Semi-Final, Finals), with a note when an old account is
  unpaid. Students who are not yet enrolled show no installments. Paid-up accounts are included,
  unlike Outstanding balances. The screen, printout, and CSV all include every row.
- On screen, the entry lists show 100 rows per page; totals, the chart, the printout, and the
  CSV export always cover every row.
- The cashier dashboard shows net collections for each of the last 7 days.
- **CSV export** uses native `fputcsv` with a UTF-8 BOM. Any text cell starting with `=`,
  `+`, `-`, `@`, a tab, or a carriage return is prefixed with an apostrophe so a spreadsheet
  never runs it as a formula (`CsvExport::cell`); plain numbers, including negative amounts,
  are left as numbers. Every export is audited (`reports.exported`, with the filters and row
  count).
- Dates that are invalid, reversed, or span more than a year fall back to this month, with a
  message.

## As built: registrar reports (`/registrar/reports`, `reports.registrar`)

Registrar role **and** `reports.registrar`. Every report opens on the term being enrolled
(else the term in session), has a Print page and an audited CSV export
(`reports.exported`, with the filters and row count), and lists 100 rows per page on
screen; print and CSV always cover every row. Draft applications are never counted.

| Report | Screen | Filters | Contents |
|---|---|---|---|
| **Enrollment list** | `/registrar/reports/enrollment` | term, program, year level, section | Officially enrolled students (`enrollments.status = ENROLLED`) with counts by program, year level, and section against capacity, units carried, and counts by student type. Covers "students by program/year/section". |
| **Applicants by status** | `/registrar/reports/applications` | term, program, status | A program × status matrix of submitted applications, and each application with required documents verified of required, payment status, and submission date. The status filter narrows the list; counts always cover every status. |
| **Requirements status** | `/registrar/reports/requirements` | term, program | For applications still in progress (not rejected or cancelled): each requirement by status, how many applications have every required document verified, and each application still missing one, naming the documents and their status. The CSV has one line per missing document. |
| **Enrollment history** | `/registrar/reports/history` | program | Every term (up to a year ahead), newest first: applicants, approved, enrolled, new students, units, and enrolled per program. A single student's history stays on their record. |

Print and export paths add `/print` and `/export` to the screen path.

## As built: teacher class reports (`/teacher/reports`, `reports.teacher`)

Teacher role **and** `reports.teacher`, for **the teacher's own active classes only**: the
class is found with `GradingService::ownedClass()`, so another teacher's class is a **404**,
as on the roster and grade pages. `/teacher/reports` lists each class with its three reports;
each opens as a printable page and as an audited CSV export (`reports.exported`, target
the class).

| Report | Page | Contents |
|---|---|---|
| **Class list** | `/teacher/classes/{id}/reports/class-list` | Enlisted students with student number, program and year, email, and a signature column. |
| **Attendance summary** | `/teacher/classes/{id}/reports/attendance` | Per student: present, late, absent, excused, and the attendance rate, then the dated record (P/L/A/E) in blocks of 12 dates. The rate counts **late as attended** and leaves **excused** dates out: (present + late) ÷ (dates − excused). The CSV has one column per date. |
| **Grade sheet** | `/teacher/classes/{id}/reports/grades` | Each period's grade with its weight, the computed final grade, and the remark (docs/27), with signature lines for the instructor and the registrar. Marked **not final** while any period is still open. |

Exports add `/export`. Every printout's tables scroll sideways on a phone and print in
full on paper.

## Administrative reports
The Super Admin's account review, sign-in summary, activity summary, and system usage reports
(`reports.admin`) are described in docs/19.
