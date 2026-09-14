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

## As built (Phase 5, first part)

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

### Window payments (`/cashier/accounts/{id}`)
The cashier can record a payment received in person (cash, check, and so on), with the same
receipt, balance, and enrollment effects.

### Rules
- `FinanceService::recalculate()` is the only writer of `total_paid`, `balance`, and the
  application's `payment_status`.
- Payments are never edited or deleted. Reversals and adjustments (discounts, scholarships)
  are not built yet.
- Every assessment, payment, verification, rejection, fee change, and proof view is audited.

### Not yet built
Reversals and financial adjustments, installment schedules, collection reports, and a real
payment gateway (which needs a merchant account and the school's authorization to add an
external service).
