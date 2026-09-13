-- @up
CREATE TABLE sessions (
    id            VARCHAR(128) NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    payload       TEXT         NULL,
    last_activity INT          NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_activity (last_activity),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_logs (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            BIGINT UNSIGNED NULL,
    username_attempted VARCHAR(150) NOT NULL,
    ip_address         VARCHAR(45)  NOT NULL,
    user_agent         VARCHAR(255) NULL,
    result             VARCHAR(20)  NOT NULL,
    attempted_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    -- These two indexes drive login rate limiting. They are required (docs/17).
    KEY idx_login_logs_username (username_attempted, attempted_at),
    KEY idx_login_logs_ip (ip_address, attempted_at),
    CONSTRAINT fk_login_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    -- A hash of the token, never the token itself.
    token_hash   VARCHAR(255) NOT NULL,
    expires_at   DATETIME     NOT NULL,
    used_at      DATETIME     NULL,
    requested_ip VARCHAR(45)  NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_prt_token (token_hash),
    KEY idx_prt_user (user_id),
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS login_logs;
DROP TABLE IF EXISTS sessions;
