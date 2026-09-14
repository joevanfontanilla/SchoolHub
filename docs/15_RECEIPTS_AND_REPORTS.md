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
the balance after that payment, and the cashier. The class schedule lists every class with
its days, times, room, section, and **teacher**, and the week at a glance.
