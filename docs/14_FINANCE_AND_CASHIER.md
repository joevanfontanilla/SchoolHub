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
