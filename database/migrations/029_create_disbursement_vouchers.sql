-- @up
-- Cash disbursements (docs/14): money paid OUT by the cashier, each on a numbered voucher.
-- The receipts side already exists (payments + official receipts); this is the disbursement
-- side of the accounting cycle. Like payments, vouchers are append-only — never edited or
-- deleted; a mistake is corrected by a reversing voucher.
CREATE TABLE disbursement_vouchers (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    voucher_number   VARCHAR(20) NOT NULL,
    payee            VARCHAR(150) NOT NULL,
    particulars      VARCHAR(255) NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    payment_method   VARCHAR(30) NOT NULL,
    reference_number VARCHAR(100) NULL,
    disbursed_at     DATETIME NOT NULL,
    recorded_by      BIGINT UNSIGNED NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voucher_number (voucher_number),
    KEY idx_disbursed_at (disbursed_at),
    CONSTRAINT fk_dv_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS disbursement_vouchers;
