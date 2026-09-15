-- @up
-- Indexes found necessary by the performance test (docs/22) with a school-sized dataset:
-- the newest-first lists and date-range reports sorted or filtered on a time column
-- scanned the whole table.

-- Audit log: newest first, optionally narrowed by action prefix.
ALTER TABLE audit_logs
    ADD KEY idx_audit_created (created_at),
    ADD KEY idx_audit_action (action, created_at);

-- Sign-in activity: newest first across all accounts.
ALTER TABLE login_logs
    ADD KEY idx_login_logs_attempted (attempted_at);

-- Collections reports and the cashier dashboard select payments by when they were recorded.
ALTER TABLE payments
    ADD KEY idx_payments_created (created_at);

-- Outstanding balances: active assessments that still owe.
ALTER TABLE fee_assessments
    ADD KEY idx_assessments_open (status, balance);

-- @down
ALTER TABLE fee_assessments DROP INDEX idx_assessments_open;
ALTER TABLE payments DROP INDEX idx_payments_created;
ALTER TABLE login_logs DROP INDEX idx_login_logs_attempted;
ALTER TABLE audit_logs DROP INDEX idx_audit_action, DROP INDEX idx_audit_created;
