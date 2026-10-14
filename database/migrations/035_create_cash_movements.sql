-- @up
-- Opening cash balances and bank deposits/withdrawals (docs/14, The Cash Book). The cash book itself is
-- computed from payments, vouchers, and these entries, so only these entries are stored.
--   movement_type: OPENING | DEPOSIT | WITHDRAWAL
--   opening_key:   CASH | BANK for an opening balance (one per account), NULL otherwise
CREATE TABLE cash_movements (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    movement_number  VARCHAR(20) NOT NULL,
    movement_type    VARCHAR(12) NOT NULL,
    opening_key      VARCHAR(4) NULL,
    entered_at       DATETIME NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    reference_number VARCHAR(100) NULL,
    remarks          VARCHAR(500) NULL,
    recorded_by      BIGINT UNSIGNED NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cash_movement_number (movement_number),
    UNIQUE KEY uq_cash_movement_opening (opening_key),
    KEY idx_cash_movement_entered (entered_at),
    CONSTRAINT fk_cm_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The ledger account that takes the other side of an opening cash balance.
INSERT IGNORE INTO ledger_accounts (code, name, type, is_system, is_active)
VALUES ('3020', 'Opening Cash Balances', 'EQUITY', 1, 1);

-- @down
DELETE FROM ledger_accounts WHERE code = '3020';
DROP TABLE IF EXISTS cash_movements;
