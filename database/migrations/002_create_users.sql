-- @up
CREATE TABLE users (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id               BIGINT UNSIGNED NOT NULL,
    username              VARCHAR(50)  NOT NULL,
    email                 VARCHAR(150) NOT NULL,
    -- password_hash() output. A plaintext password is never stored (docs/00).
    password_hash         VARCHAR(255) NOT NULL,
    first_name            VARCHAR(100) NOT NULL,
    middle_name           VARCHAR(100) NULL,
    last_name             VARCHAR(100) NOT NULL,
    status                VARCHAR(20)  NOT NULL DEFAULT 'Active',
    must_change_password  TINYINT(1)   NOT NULL DEFAULT 0,
    failed_login_attempts SMALLINT     NOT NULL DEFAULT 0,
    locked_until          DATETIME     NULL,
    last_login_at         DATETIME     NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS users;
