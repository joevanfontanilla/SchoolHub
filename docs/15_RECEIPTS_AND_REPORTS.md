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

- **Collections** are dated by when the cashier recorded or verified the payment
  (`payments.created_at`). A reversal is a negative line on the day it was made, so a
  period's net always agrees with the accounts. The report shows net, gross, reversals, a
  column chart (one bar per day for up to 31 days, otherwise per month), totals by payment
  method, and every entry. This covers daily and monthly collections and payment history.
- **Outstanding balances** lists the amount due, paid, balance, payment status, last payment,
  and whether an enrolled student is below the payment policy, largest balance first.
- The cashier dashboard shows net collections for each of the last 7 days.
- **CSV export** uses native `fputcsv` with a UTF-8 BOM. Any text cell starting with `=`,
  `+`, `-`, `@`, a tab, or a carriage return is prefixed with an apostrophe so a spreadsheet
  never runs it as a formula (`CsvExport::cell`); plain numbers, including negative amounts,
  are left as numbers. Every export is audited (`reports.exported`, with the filters and row
  count).
- Dates that are invalid, reversed, or span more than a year fall back to this month, with a
  message.

Registrar and teacher reports are not built yet.
