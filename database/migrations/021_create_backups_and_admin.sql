-- @up
-- Administration (docs/20, docs/07).

-- Backup history. The archives themselves live in storage/backups, never under public/.
CREATE TABLE backups (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename         VARCHAR(150) NOT NULL,
    path             VARCHAR(255) NOT NULL,
    size_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- MANUAL (from the admin screen or CLI), SCHEDULED (the CLI with --scheduled),
    -- PRE_RESTORE (taken automatically just before a restore).
    type             VARCHAR(20)  NOT NULL,
    -- RUNNING, COMPLETED, FAILED, DELETED (file removed; the record stays).
    status           VARCHAR(20)  NOT NULL,
    checksum         CHAR(64)     NULL,
    includes_uploads TINYINT(1)   NOT NULL DEFAULT 1,
    table_count      INT UNSIGNED NULL,
    upload_count     INT UNSIGNED NULL,
    started_at       DATETIME     NOT NULL,
    completed_at     DATETIME     NULL,
    verified_at      DATETIME     NULL,
    verify_result    VARCHAR(255) NULL,
    created_by       BIGINT UNSIGNED NULL,
    notes            VARCHAR(255) NULL,
    error            TEXT         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_backups_filename (filename),
    KEY idx_backups_status (status, completed_at),
    CONSTRAINT fk_backups_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who issued a password link: an administrator, or NULL for a user's own request.
ALTER TABLE password_reset_tokens
    ADD COLUMN purpose    VARCHAR(20) NOT NULL DEFAULT 'RESET' AFTER token_hash,
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER requested_ip,
    ADD CONSTRAINT fk_prt_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

-- @down
ALTER TABLE password_reset_tokens
    DROP FOREIGN KEY fk_prt_created_by,
    DROP COLUMN created_by,
    DROP COLUMN purpose;
DROP TABLE IF EXISTS backups;
