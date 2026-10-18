-- @up
-- The school's bank accounts that students pay into, and which one a bank payment went to (docs/14).
CREATE TABLE school_bank_accounts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_name      VARCHAR(100) NOT NULL,
    account_name   VARCHAR(150) NOT NULL,
    account_number VARCHAR(40)  NOT NULL,
    branch         VARCHAR(100) NULL,
    note           VARCHAR(255) NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order     SMALLINT     NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_account (bank_name, account_number),
    KEY idx_bank_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_submissions
    ADD COLUMN bank_account_id BIGINT UNSIGNED NULL AFTER method,
    ADD CONSTRAINT fk_submissions_bank FOREIGN KEY (bank_account_id) REFERENCES school_bank_accounts (id) ON DELETE RESTRICT;

ALTER TABLE payments
    ADD COLUMN bank_account_id BIGINT UNSIGNED NULL AFTER payment_method,
    ADD CONSTRAINT fk_payments_bank FOREIGN KEY (bank_account_id) REFERENCES school_bank_accounts (id) ON DELETE RESTRICT;

-- @down
ALTER TABLE payments DROP FOREIGN KEY fk_payments_bank, DROP COLUMN bank_account_id;
ALTER TABLE payment_submissions DROP FOREIGN KEY fk_submissions_bank, DROP COLUMN bank_account_id;
DROP TABLE IF EXISTS school_bank_accounts;