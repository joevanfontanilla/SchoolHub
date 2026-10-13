-- @up
-- The chart of accounts for the cashier's general ledger (docs/14). The ledger itself is computed from
-- assessments, adjustments, payments, and vouchers, so this table holds only the accounts.
--   type: ASSET | EQUITY | REVENUE | CONTRA_REVENUE | EXPENSE
--   fee_key: ties a revenue account to a fee line of the fee schedule (fee_types.fee_key)
--   is_system: accounts the ledger itself depends on, or that follow the fee schedule; never switched off
CREATE TABLE ledger_accounts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(10)  NOT NULL,
    name       VARCHAR(100) NOT NULL,
    type       VARCHAR(20)  NOT NULL,
    fee_key    VARCHAR(30)  NULL,
    is_system  TINYINT(1)   NOT NULL DEFAULT 0,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ledger_accounts_code (code),
    UNIQUE KEY uq_ledger_accounts_fee_key (fee_key),
    KEY idx_ledger_accounts_type (type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a voucher was spent on. NULL for vouchers recorded before this existed: the ledger posts them to
-- "Uncategorized Expense" until the cashier categorizes them (once, and never changed afterwards).
ALTER TABLE disbursement_vouchers
    ADD COLUMN expense_account_id BIGINT UNSIGNED NULL AFTER particulars,
    ADD CONSTRAINT fk_dv_expense_account FOREIGN KEY (expense_account_id) REFERENCES ledger_accounts (id) ON DELETE RESTRICT;

-- @down
ALTER TABLE disbursement_vouchers DROP FOREIGN KEY fk_dv_expense_account;
ALTER TABLE disbursement_vouchers DROP COLUMN expense_account_id;
DROP TABLE IF EXISTS ledger_accounts;
