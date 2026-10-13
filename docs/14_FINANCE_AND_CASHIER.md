# Finance and Cashier Module

## Fee Types
Examples:
- Tuition
- Registration
- Laboratory
- Library
- Technology
- Miscellaneous
- Other approved school fees

## Student Account
Should show:
- Assessment
- Charges
- Payments
- Adjustments where authorized
- Balance

## Payment
Record:
- Transaction number
- Student
- Date/time
- Amount
- Payment method
- Reference number when applicable
- Cashier
- Remarks

## Payment Methods
- Cash
- Bank
- Online/manual reference
- Other configured method

## Financial Rules
- Never delete financial transactions as a normal workflow.
- Corrections should use controlled reversal/adjustment procedures.
- Payment creation and adjustment must be audited.

---

## As built (Phase 5)

### Fee schedule (`/cashier/fees`, `fees.manage`)
Each fee is charged **per unit** (all units), **per laboratory unit**, or **fixed** once per
term. The seeder adds six fees with **sample amounts, labelled as samples**; the cashier must
replace them with the school's real fees. A change applies to assessments issued afterwards;
an issued assessment keeps the amounts it was issued with (they are copied into its lines).
Fees are deactivated, never deleted.

### Assessment (`FinanceService::assess`)
Generated once, when the registrar approves the application (docs/26 rule 8):
`units × per-unit fees + lab units × per-lab-unit fees + fixed fees`, from the subjects the
student chose. Number format `ASM-YYYY-NNNNN`. Money is computed in whole centavos.

### Online payment (`/student/account`)
The student sends the amount, method (GCash, Maya, bank deposit, bank transfer, other),
reference number, date paid (within the last 60 days, not in the future), and a proof file
(JPG, PNG, or PDF, at most 5 MB, checked by extension, MIME type, and magic bytes like every
upload, stored outside the web root under a random name). The amount may not exceed the
balance less the payments still waiting for verification. Where to pay is the
`payment_instructions` setting (**sample details, to be replaced**).

### Verification (`/cashier/payments`, `payments.record`)
The cashier sees the proof next to the account and either:
- **verifies** it: a `payments` row with a transaction number (`PAY-YYYY-NNNNN`) and an
  official receipt number (`OR-YYYY-NNNNN`) is created, the balance recomputed, the student
  notified, and the student enrolled if the payment policy is now met; or
- **rejects** it with a reason the student sees. Nothing is recorded.

### Student accounts (`/cashier/accounts`)
Every assessment, newest first, searchable by name or by student, application, or assessment
number, and filterable by balance (owing or paid), 50 per page.

### Window payments (`/cashier/accounts/{id}`)
The cashier can record a payment received in person (cash, check, and so on), with the same
receipt, balance, and enrollment effects.

### Adjustments (`/cashier/accounts/{id}`, `fees.manage`)
The cashier applies a **discount, scholarship, waiver, additional charge, or correction**
(`FinanceService::adjust`). Every adjustment:
- has a positive peso amount (at most two decimals) and a **reason of 5 to 500 characters**;
  a correction also says whether it increases or decreases what is owed;
- is stored as a signed row in `financial_adjustments` (`ADJ-YYYY-NNNNN`); discounts,
  scholarships, waivers, and decreasing corrections are negative, charges and increasing
  corrections positive;
- takes effect at once, with no second approval, and is audited (`finance.adjusted`, reason in
  the remarks) and notified to the student;
- may **not** bring the amount due below what has already been paid, because refunds are not
  built. Bringing it to exactly the amount paid is allowed.

The assessment's `total_amount` is never rewritten. **Amount due = `total_amount` +
`total_adjustments`**, and the balance, `payment_status`, and payment policy all use the
amount due. A reduction can meet the policy and enroll the student; a full scholarship
enrolls without any payment.

### Reversals (`/cashier/accounts/{id}`, `payments.record`)
A payment recorded by mistake (wrong account, bounced cheque, duplicate) is reversed with a
**required reason** (`FinanceService::reversePayment`):
- a **new `payments` row** is written with the negative amount, a new transaction number,
  **no receipt number**, and `reversed_payment_id` pointing at the original;
- the original payment and its receipt are left exactly as they were. The receipt, when
  printed, carries a **REVERSED** notice with the date and reason;
- a payment can be reversed only once (a unique key backs this), and a reversal cannot
  itself be reversed. To undo a mistaken reversal, record the payment again;
- it is audited (`finance.payment_reversed`) and notified to the student.

**If the student is already enrolled**, the enrollment stands. When the amount paid is now
below the payment policy, the account shows a **"Below payment policy"** warning (account
page and outstanding-balances report), and every active Registrar and Cashier is notified
(`finance.below_policy`). Following up with the student is a manual step.

### Rules
- `FinanceService::recalculate()` is the only writer of `total_adjustments`, `total_paid`,
  `balance`, and the application's `payment_status`. It sums payments **with their sign**, so
  reversals subtract.
- Payments and adjustments are never edited or deleted.
- Every assessment, payment, verification, rejection, adjustment, reversal, fee change,
  proof view, and report export is audited.

### Not yet built
Refunds (money paid beyond the amount due), installment schedules, a second approval for
large adjustments, and a real payment gateway (which needs a merchant account and the
school's authorization to add an external service).

## Cash disbursements and the journals (as built)

The receipts side of the accounting cycle already existed: a student pays, the cashier records
the payment and an **Official Receipt** is issued. Two additions complete the cashier's books.

- **Cash Receipts Journal (CRJ).** A read-only report (`/cashier/reports/receipts-journal`) that
  lists official receipts over a date range in journal form — Date, OR No., Payor, Particulars,
  Method, Amount — with a printable page and an audited CSV export. It reads the same payment data
  as the Collections report.
- **Cash disbursements.** Money paid out is recorded on a numbered **voucher** through
  `DisbursementService::record()` (permission `disbursements.manage`, cashier) at
  `/cashier/disbursements`: payee, particulars, amount (whole centavos), method, optional reference,
  and date. A voucher number (`DV-YYYY-NNNNN`) is issued from `SequenceService`, and the action is
  audited (`disbursement.recorded`). Vouchers are **append-only** — a mistake is corrected with a
  reversing voucher, never an edit — and each prints on a voucher form with Prepared/Approved/Received
  signature lines.
- **Cash Disbursements Journal (CDJ).** A read-only report (`/cashier/reports/disbursements-journal`)
  listing vouchers over a date range — Date, Voucher No., Payee, Particulars, Method, Amount — printable
  and exportable.

There is **no general ledger and no double-entry posting**: SchoolHub records the two cash journals
(CRJ, CDJ), not a full chart of accounts. `DisbursementService` is the only writer of vouchers;
`DisbursementVoucher` holds the read queries, including `journal()` for the CDJ.

## Walk-in payments (as built)

A counter workspace for payments received in person, at **Cashier → Walk-in Payments**
(`/cashier/walk-in-payments`), right below **Online Payments**. Permission `payments.record`
(the Cashier role only; Super Admin is not admitted).

- **Who can pay.** Any student with an active fee assessment that still has a balance, however
  they enrolled: "walk-in" describes how they pay, not how they registered. Search by name,
  student number, application number, or assessment number.
- **Recording.** Choose the account, enter the amount, method (Cash by default; Check, GCash,
  and the other `METHODS_CASHIER`), an optional reference and remarks. It calls the same
  `FinanceService::recordPayment` as the form on the account page, so the receipt, balance,
  `payment_status`, enrollment effects, notification, and audit entry are identical. There is no
  new money logic. For a student not yet enrolled, the amount is prefilled with what is still
  needed to meet the payment policy.
- **After recording.** The page shows the official receipt number and a **Print receipt** link
  (`/receipts/{id}`), and says when the payment met the policy and enrolled the student.
- **Today's counter payments.** A list of the payments recorded at the window today (not online
  verifications, not reversals), with the receipt, payor, method, and amount, and the day's total.
- **Unchanged.** The payment form on `/cashier/accounts/{id}`, reversals, and adjustments.

## Fee structure, percentage discounts, and the ledger card (as built)

Modeled on the cashier's own spreadsheet ledger (one card per student: an assessment block
and a Date / Ref / Particulars / Debit / Credit / Balance ledger).

### Fee lines by program and year level
- The fee schedule (`/cashier/fees`) lists the school's real lines: **Tuition per unit** and the
  miscellaneous lines (Registration, Guidance Counseling, Medical/Dental, Laboratory, Student ID,
  Library, Audio Visual, Student Org. & RLA, Insurance, Computer Lab/Internet/LMS, School
  Publication, Cultural, Athletic; NSTP is added by the cashier where it applies).
- A fee line may be limited to a **program** and/or a **year level** (`fee_types.program_id`,
  `year_level_id`; blank means everyone). A scoped line can **replace** a general line for that
  program or year (for example a different tuition rate per unit): lines share a `fee_key`, and
  for each key the **most specific applicable line wins** (program + year, then program, then
  year, then general). A scoped line that replaces nothing is simply an extra charge for that
  program or year.
- `FinanceService::computeFees()` receives the application's program and year level and applies
  that rule. An issued assessment keeps the lines it was issued with, as before.
- **The seeded amounts come from the A.Y. 2026-2027 1st Semester ledger (Public Administration,
  3rd year) and are school-wide defaults to verify.** Laboratory, Computer Lab, and NSTP in
  particular probably vary by program or year: scope them on the fee schedule.

### Percentage discounts
A discount or scholarship can be entered as a **percentage of tuition** instead of pesos. The
tuition is the assessment's per-unit lines only (miscellaneous fees are not discounted), the
peso amount is rounded to the centavo, and the stored adjustment carries the percentage in its
reason. Everything else about adjustments is unchanged (docs/14 "Adjustments").

### The ledger card (`/statements/{id}/ledger`)
A printable card in the school's layout: name, student number, course, year, school year, the
assessment block (units, tuition per unit, discount, miscellaneous total, and the itemized
breakdown), then the ledger. Rows, oldest first:
- **ACCOUNT**: debit of the assessment total (tuition plus miscellaneous);
- each **adjustment**: a credit for a discount, scholarship, waiver, or decreasing correction, a
  debit for a charge or increasing correction, with its date and number;
- each **payment**: a credit, dated, with the official receipt number as its Ref;
- each **reversal**: a debit pointing at the receipt it cancels.
The balance column runs down the rows. Open it from the cashier's account page. The student,
cashier, and registrar may open it, with the same access rules as the statement of account.

### Not yet built
Exam-period installments (Prelims, Midterm, Semi-Final, Finals), Bridging / OJT / Old Account
charges, and a per-program and year account list.
