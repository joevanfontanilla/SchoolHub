-- @up
-- Withdrawing a student (docs/26, "Withdrawal"): the enrollment row is kept, with its status set to WITHDRAWN and who did it,
-- when, and why. Fees and payments are untouched.
ALTER TABLE enrollments
    ADD COLUMN withdrawn_at    DATETIME        NULL AFTER status,
    ADD COLUMN withdrawn_by    BIGINT UNSIGNED NULL AFTER withdrawn_at,
    ADD COLUMN withdraw_reason VARCHAR(500)    NULL AFTER withdrawn_by,
    ADD CONSTRAINT fk_enrollments_withdrawn_by FOREIGN KEY (withdrawn_by) REFERENCES users (id) ON DELETE RESTRICT;

-- @down
ALTER TABLE enrollments
    DROP FOREIGN KEY fk_enrollments_withdrawn_by,
    DROP COLUMN withdraw_reason,
    DROP COLUMN withdrawn_by,
    DROP COLUMN withdrawn_at;
