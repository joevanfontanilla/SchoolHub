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
Refunds (money paid beyond the amount due), a second approval for
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
  signature lines. There is no sidebar link for vouchers, to avoid repeating the journal: open the
  **Cash Disbursements Journal** and use **All vouchers** or **New voucher**.
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
- **The account at a glance.** While a student is chosen, two tables at the top of the page (above the search):
  the **Installments** table from the account page (initial payment, Prelims, Midterm, Semi-Final, Finals, each with
  due, paid, balance, and status), then **Where the payments went**, the split of docs/14 (Payment breakdown). With no
  student chosen neither is shown. Before the student is enrolled the installments say they are set after the down payment.
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

## Exam-period installments and carried-over charges (as built)

Modeled on the cashier's spreadsheet: after the initial payment, the rest of what a student owes is
paid in four installments, one per exam period.

### The plan (`FinanceService::installments`)
- **Initial payment.** The money paid by the moment the student was enrolled, frozen on the
  assessment (`fee_assessments.initial_paid`) when enrollment is confirmed. Nothing is shown before
  enrollment, only a note that the installments are set after the down payment.
- **Four installments.** The amount due, less the old account and the initial payment, is split
  into four equal parts: **Prelims, Midterm, Semi-Final, Finals**. A centavo that does not divide
  evenly goes to the earliest installments, so the four always add up exactly. The split is
  computed from the live amount due, so a later scholarship, discount, or charge changes all four.
- **Payments fill obligations in order**, never by the cashier's choice: old account, initial
  payment, then Prelims, Midterm, Semi-Final, Finals, each to the centavo. A payment that covers
  more than one carries forward. A reversal takes money back from the latest obligation first.
- **Shown, never enforced.** An unpaid installment is flagged **Due** once its grading period has
  opened (docs/27, `grade_periods.opened_at`), so the cashier can see who is not cleared for an
  exam. It does not block grades, enrollment, or exams. A term with fewer than four grading periods
  never flags the installments that have no period.
- **Where it shows.** An "Installments" block on the cashier's account page, on the student's own
  account page, and under the ledger card.

### Bridging, OJT, and Old Account
Three more charge types in the cashier's adjustment form, alongside Additional charge. Each raises
the amount due and needs a reason, like any adjustment.
- **Bridging fee** and **OJT fee** are part of the amount due, so they are split into the
  installments with everything else.
- **Old account** is a balance carried over from a previous term, entered by hand. It is **due
  right away**, is not split, and is the first thing payments go to. It shows as its own line.
- All three count toward the payment policy, since the policy is a share of the amount due.

## Account list by program and year (as built)

The spreadsheet keeps one tab per program and year (PUB AD 3, CRIM 1, …). The cashier's **Account
list** report (`/cashier/reports/account-list`, docs/15) is that view: all active accounts of a term
grouped by program and year level, each group numbered from 1 with its totals, showing units,
tuition, miscellaneous, discount, amount due, paid, balance, and the four installment balances. It
reads the same figures as the ledger card, so the two always agree. It is read-only; the CSV export
is audited like the other reports.

### Not yet built
Automatic carry-over of an unpaid balance into the next term (the old account is typed in).

## General ledger (as built)

The third step of the cashier's accounting cycle: the Cash Receipts and Cash Disbursements Journals
feed a **general ledger** and a **trial balance**, at **Cashier → General Ledger**
(`/cashier/ledger`, permission `reports.cashier`).

### Computed, not stored
The ledger is **computed from the records that already exist**: fee assessments, adjustments,
payments and their reversals, and disbursement vouchers (`LedgerService`). It stores nothing of
its own except the chart of accounts, so it cannot drift from the books and it covers past records
at once. There are no manual journal entries and no period closing; a correction stays a reversal
(a reversing payment, an adjustment), as before. Every posting is a balanced pair, so debits always
equal credits.

### Accrual basis: what posts where
| Event | Debit | Credit | Dated by |
|---|---|---|---|
| Assessment, each fee line | Student Receivables | that fee line's revenue account | when assessed |
| Discount, scholarship, waiver | Scholarships and Discounts | Student Receivables | when applied |
| Bridging fee / OJT fee | Student Receivables | Bridging Fees / OJT Fees | when applied |
| Additional charge, correction up | Student Receivables | Other Charges and Fees | when applied |
| Correction down | Other Charges and Fees | Student Receivables | when applied |
| Old account (carried over) | Student Receivables | Opening Balances (Old Accounts), not revenue | when entered |
| Payment | Cash on Hand (Cash) or Cash in Bank and E-wallets (GCash, Maya, bank, check) | Student Receivables | when recorded |
| Payment reversal | Student Receivables | the same cash account | when reversed |
| Disbursement voucher | its expense account | Cash on Hand (Cash) or Cash in Bank and E-wallets | disbursement date |

A payment is dated by when the cashier recorded it, the same as the Collections report and the
Cash Receipts Journal, so the three always agree. A disbursement is dated by its disbursement date.

### The chart of accounts (`ledger_accounts`, `/cashier/ledger/chart`)
- **Assets:** 1010 Cash on Hand, 1020 Cash in Bank and E-wallets, 1100 Student Receivables.
- **Equity:** 3010 Opening Balances (Old Accounts).
- **Revenue:** one account per fee line of the fee schedule (created automatically with the fee, by
  `fee_key`), plus 4800 Bridging Fees, 4810 OJT Fees, 4890 Other Charges and Fees.
- **Contra-revenue:** 4950 Scholarships and Discounts.
- **Expenses:** 5010 Salaries and Wages, 5020 Utilities, 5030 Office Supplies, 5040 Repairs and
  Maintenance, 5050 Communication, 5060 Transportation, 5070 Student Activities, 5900 Miscellaneous
  Expense, and 5990 Uncategorized Expense.
- The cashier (`disbursements.manage`) may **add expense accounts** (code 5000 to 5899) and switch
  them off; system accounts and fee-line revenue accounts cannot be switched off or renamed here.
  An account that has postings is never deleted.

### Expense category on vouchers
A new voucher must choose an **expense account**. Vouchers recorded before this existed post to
5990 Uncategorized Expense; the cashier can **categorize each one once** from the Disbursements page
(audited). After that it is permanent, like the rest of the voucher.

### The pages
- **General Ledger / Trial balance** (`/cashier/ledger`): for a date range (default: this year to
  date), every account's opening balance, debits, credits, and closing balance, with totals that
  must balance. Each account links to its ledger.
- **Account ledger** (`/cashier/ledger/accounts/{id}`): Date / Ref / Particulars / Debit / Credit /
  Balance for one account, with the opening balance first, like the student ledger.
- Both print, and export as CSV (audited, like every report).

### Not yet built
Manual journal entries, period closing, and a posting for voiding an assessment (nothing voids one
today; if that is ever added it must post the reversal).

## Daily operations at the window (as built)

Four pieces that make the cashier's day easier. None changes how money is computed.

### Closing the day (`/cashier/day-close`)
At the end of the day the cashier counts the drawer and closes the day.

- **What the system expects.** For a date, from what was *recorded* that day: money received by
  each method (reversals count as negative), the cash paid out on vouchers, and from those the
  **expected cash** = cash received − cash paid out. Cheque, GCash, and the other non-cash methods
  show as totals only; they are not counted.
- **The count.** The cashier types the **counted cash** as one total (no bill-by-bill breakdown). The
  **difference** (counted − expected) is stored. If it is not zero, a short **explanation** of at
  least 5 characters is required. The difference is *not* posted to the general ledger (over and
  short is settled outside the system).
- **The lock.** Once a day is closed, nothing new is recorded on it: a payment, an online
  verification, a reversal, or a voucher is refused with "Today has been closed. Ask the
  administrator to reopen it." A mistake found later is reversed *today*, on the open day, with its
  reason; the audit entry notes that the payment came from a closed day.
- **Reopening.** Only the Super Admin can reopen a closed day (`/admin/day-closes`), with a reason.
  Reopening is audited; the cashier then closes the day again with a new count. The cashier cannot
  reopen their own close.
- **Turnover slip.** A closed day prints as a turnover slip: the totals, the count, the difference,
  and signature lines.
- A day can be closed once it is today or earlier; the date picker lets the cashier catch up a missed day.

### Dashboard
Besides the existing cards, the dashboard shows today's collections **by method** (cash and
non-cash), whether today is **closed**, and the accounts with an **installment due**.

### Due installments
An installment is **due** when its exam period has been opened by the registrar (the grading
period's `opened_at`) and it is not fully paid. This is the same rule the installment table already
uses ("Due" vs "Upcoming"); there are no per-installment calendar dates, so "due within 3 days"
does not exist. Due never blocks anything.

- The **account list** has a "Due now only" filter, and its CSV has a **Due now** column.
- **Reminders.** On Outstanding Balances the cashier presses **Send reminders**: every student
  with a due installment gets one in-app notification *per installment*, once (`installment_reminders`
  remembers it). Nothing is sent automatically (no scheduler on the current host); the button is the trigger.

### Quick amounts on the walk-in payment form
Buttons fill the Amount field and change nothing else: **Required to enroll** (before enrollment),
**Pay <next unpaid installment>** (old account, initial payment, or the next exam period), and
**Full balance**. The cashier can still edit the amount before recording.

### Receipt copies
The first time staff open a receipt it is the original. Every later opening by staff, and any
opening by the student after that, shows **DUPLICATE COPY**, and each staff reprint is audited
(`finance.receipt_reprinted`). The **Print receipt** button after a payment opens the receipt with
the browser's print dialog already open.

## The Cash Book (as built)

The school's paper cash book, as a report: **Cashier → Reports → Cash Book**
(`/cashier/reports/cash-book`, permission `reports.cashier`). It is **computed** from the same
records as the general ledger (payments, reversals, vouchers, and the entries below), so it always
agrees with the Cash Receipts Journal, the Cash Disbursements Journal, and the ledger accounts 1010 and 1020.

- **Layout.** Two sides, like the school's template. **Cash Inflow** on the left and **Cash Outflow**
  on the right, each with Date, Transaction Details, and Amount. The first inflow row is
  **Balance C/F**. Below: Total Inflow, Total Outflow, then the summary.
- **A month at a time.** A month picker (default: this month) and an **Account** dropdown: *Cash on
  Hand* (1010) or *Bank and E-wallets* (1020: GCash, Maya, cheques, bank transfers). Both apply by
  themselves. Balance C/F is the previous month's closing balance for that account.
- **Inflow** is cash payments (or e-wallet, cheque, and bank payments in the Bank book), the opening
  balance, and withdrawals from the bank. A **reversed payment** is a *negative* inflow on the day
  it was reversed. **Outflow** is vouchers and deposits to the bank.
- **Summary.** Total Inflow (including Balance C/F), Total Outflow, **Closing Balance**; for the Cash
  book also **Counted at day close** (the total of the month's counts from Close the Day) and
  **Over/short** (the total of their differences), with the number of days that had cash activity
  but were not closed. Close the Day counts each day's collections, not the whole drawer, so these are
  totals of the daily counts. Nothing extra is typed.
- It prints on one landscape page and exports as CSV (audited, like every report).

### Opening balance
A one-time entry for Cash on Hand and for Bank, with a date: what the school held when it started
using the system. It is the first month's Balance C/F and posts to the ledger as **Dr the cash account,
Cr 3020 Opening Cash Balances**. It can be corrected; each change is audited with the old and new amount.
Permission `disbursements.manage`.

### Bank deposits and withdrawals
**Deposit to bank** (cash to bank) and **Withdraw from bank** (bank to cash): amount, reference
(deposit slip number), remarks, recorded at the time it is entered (`cash_movements`, numbered CM-yyyy-nnnnn).
Append-only like a voucher: a mistake is fixed with the opposite entry. A deposit is a Cash outflow and a Bank
inflow; the ledger posts **Dr 1020 / Cr 1010** (a withdrawal the reverse). Permission `disbursements.manage`.
A deposit counts as cash paid out, and a withdrawal as cash received, in **Close the Day**, so
banking the cash does not look like a shortage. A closed day refuses a new deposit or withdrawal, like any other entry.

### Prepared by on the journals and the cash book
The Cash Receipts Journal and the Cash Disbursements Journal each show a **Prepared by** column on every
row (on screen, in the print, and in the CSV): the cashier who issued the receipt or recorded the voucher,
which matters once the school has more than one cashier. Each printout, and the Cash Book printout, ends
with a signature block: **Prepared by** with the signed-in cashier's name and "Cashier" under it, and a blank
**Approved by** line.


## Payment breakdown (as built)

A table of **where the payments went**: **Cashier → Reports → Payment Breakdown**
(`/cashier/reports/payment-breakdown`, permission `reports.cashier`), and a small version on each student's account page.

- **The allocation rule.** A payment is not tied to a fee line in the books, so the report *allocates* it, for display only
  (it never changes the ledger or any balance):
  1. The **old account** is paid first, as in the installment table.
  2. The rest is shared across the other lines (each fee line, and Bridging, OJT, and other charges) **in proportion to
     each line's share of the amount charged**. Whole centavos: the leftover centavos go to the largest remainders, so the lines
     always add up to exactly what was paid.
  Discounts, scholarships, waivers, and corrections that lower the amount are shown as their own negative row; payments are
  not allocated to them.
- **A period is computed from running totals,** not payment by payment: what each line had received by the end of the period
  minus what it had received before it. So a **reversal** simply lowers the line it had counted toward (the net is shown), and
  a payment made in an earlier month is never counted twice.
- **The report.** For a date range (default this month; a payment is dated when it was recorded, like the Collections report),
  optionally one program and year level: the accounts that **paid in the period**, with, per line, the amount **assessed**,
  the amount **collected in the period**, and its **share** of the collections. Below, collections **by payment method** and
  **by day**. It prints and exports as CSV (audited, like every report).
- **On a student's account page,** "Where the payments went": each line with what was assessed, what has been paid toward it
  (the same rule applied to everything paid so far), and its share.