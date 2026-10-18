-- @up
-- One row per signed-in browser, so an account open on one device can be told when someone else signs in (docs/07 "One device at a time").
CREATE TABLE user_sessions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    status        VARCHAR(10)  NOT NULL DEFAULT 'ACTIVE',
    device_label  VARCHAR(120) NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    end_reason    VARCHAR(30)  NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME     NOT NULL,
    ended_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_sessions_token (token_hash),
    KEY idx_user_sessions_user (user_id, status, last_seen_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS user_sessions;
