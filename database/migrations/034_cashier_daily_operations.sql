-- @up
-- Cashier daily operations (docs/14): day close, due-installment reminders, receipt copies.
CREATE TABLE day_closes (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    close_date     DATE NOT NULL,
    status         VARCHAR(10) NOT NULL DEFAULT 'CLOSED',
    received_cash  DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_out_cash  DECIMAL(12,2) NOT NULL DEFAULT 0,
    expected_cash  DECIMAL(12,2) NOT NULL DEFAULT 0,
    counted_cash   DECIMAL(12,2) NOT NULL DEFAULT 0,
    difference     DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_received DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_count  INT UNSIGNED NOT NULL DEFAULT 0,
    explanation    VARCHAR(500) NULL,
    closed_by      BIGINT UNSIGNED NOT NULL,
    closed_at      DATETIME NOT NULL,
    reopened_by    BIGINT UNSIGNED NULL,
    reopened_at    DATETIME NULL,
    reopen_reason  VARCHAR(500) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_day_close_date (close_date),
    CONSTRAINT fk_dc_closed_by FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dc_reopened_by FOREIGN KEY (reopened_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE installment_reminders (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fee_assessment_id BIGINT UNSIGNED NOT NULL,
    installment_key   VARCHAR(10) NOT NULL,
    sent_at           DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_installment_reminder (fee_assessment_id, installment_key),
    CONSTRAINT fk_ir_assessment FOREIGN KEY (fee_assessment_id) REFERENCES fee_assessments (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments ADD COLUMN first_printed_at DATETIME NULL AFTER remarks;

-- @down
ALTER TABLE payments DROP COLUMN first_printed_at;
DROP TABLE IF EXISTS installment_reminders;
DROP TABLE IF EXISTS day_closes;
