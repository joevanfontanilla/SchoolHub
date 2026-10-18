-- @up
-- Deleting an account (docs/07): the account is anonymised and can no longer sign in, but the payments, grades and logs
-- that point to it stay. The original username and email are kept here so an administrator can restore the account.
ALTER TABLE users
    ADD COLUMN deleted_at       DATETIME        NULL AFTER signature_path,
    ADD COLUMN deleted_by       BIGINT UNSIGNED NULL AFTER deleted_at,
    ADD COLUMN delete_reason    VARCHAR(255)    NULL AFTER deleted_by,
    ADD COLUMN deleted_username VARCHAR(50)     NULL AFTER delete_reason,
    ADD COLUMN deleted_email    VARCHAR(150)    NULL AFTER deleted_username;

-- @down
ALTER TABLE users
    DROP COLUMN deleted_email,
    DROP COLUMN deleted_username,
    DROP COLUMN delete_reason,
    DROP COLUMN deleted_by,
    DROP COLUMN deleted_at;
