-- @up
CREATE TABLE system_settings (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`       VARCHAR(100) NOT NULL,
    `value`     TEXT NULL,
    `type`      VARCHAR(20)  NOT NULL DEFAULT 'string',
    `group`     VARCHAR(50)  NOT NULL DEFAULT 'general',
    description VARCHAR(255) NULL,
    is_public   TINYINT(1)   NOT NULL DEFAULT 0,
    updated_by  BIGINT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (`key`),
    KEY idx_settings_group (`group`),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_sequences (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`          VARCHAR(50) NOT NULL,
    prefix         VARCHAR(10) NOT NULL DEFAULT '',
    period         VARCHAR(10) NOT NULL DEFAULT '',
    current_number INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One counter per key per period. Numbers are issued with SELECT ... FOR
    -- UPDATE inside the creating transaction so two cashiers cannot collide.
    UNIQUE KEY uq_sequence (`key`, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS document_sequences;
DROP TABLE IF EXISTS system_settings;
