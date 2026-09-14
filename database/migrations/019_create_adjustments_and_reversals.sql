-- @up
-- Corrections to money records (docs/14): nothing is edited or deleted.

-- Discounts, scholarships, waivers, and extra charges, as signed entries against an
-- assessment (negative lowers what is due). A reason is always required.
CREATE TABLE financial_adjustments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    adjustment_number VARCHAR(20) NOT NULL,
    fee_assessment_id BIGINT UNSIGNED NOT NULL,
    type              VARCHAR(30) NOT NULL,
    amount            DECIMAL(12,2) NOT NULL,
    reason            VARCHAR(500) NOT NULL,
    created_by        BIGINT UNSIGNED NOT NULL,
    created_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_adjustments_number (adjustment_number),
    KEY idx_adjustments_assessment (fee_assessment_id),
    CONSTRAINT fk_adjustments_assessment FOREIGN KEY (fee_assessment_id) REFERENCES fee_assessments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_adjustments_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The assessment keeps its original total; adjustments are summed separately so the
-- statement shows both. Amount due = total_amount + total_adjustments.
ALTER TABLE fee_assessments
    ADD COLUMN total_adjustments DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount;

-- A reversal is a new payments row with a negative amount that points at the payment
-- it cancels. The original row is never changed. A reversal issues no receipt.
ALTER TABLE payments
    MODIFY COLUMN receipt_number VARCHAR(20) NULL,
    ADD COLUMN reversed_payment_id BIGINT UNSIGNED NULL AFTER payment_submission_id,
    ADD UNIQUE KEY uq_payments_reversal (reversed_payment_id),
    ADD CONSTRAINT fk_payments_reversal FOREIGN KEY (reversed_payment_id) REFERENCES payments (id) ON DELETE RESTRICT;

-- @down
-- Refuses while reversal rows (with no receipt number) exist: rolling back must not
-- silently drop corrections.
ALTER TABLE payments DROP FOREIGN KEY fk_payments_reversal;
ALTER TABLE payments DROP INDEX uq_payments_reversal;
ALTER TABLE payments DROP COLUMN reversed_payment_id;
ALTER TABLE payments MODIFY COLUMN receipt_number VARCHAR(20) NOT NULL;
ALTER TABLE fee_assessments DROP COLUMN total_adjustments;
DROP TABLE IF EXISTS financial_adjustments;
