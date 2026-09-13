-- @up
CREATE TABLE announcements (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title          VARCHAR(200) NOT NULL,
    slug           VARCHAR(220) NOT NULL,
    content        TEXT NOT NULL,
    category       VARCHAR(50)  NULL,
    image_path     VARCHAR(255) NULL,
    audience       VARCHAR(20)  NOT NULL DEFAULT 'EVERYONE',
    is_public      TINYINT(1)   NOT NULL DEFAULT 0,
    status         VARCHAR(20)  NOT NULL DEFAULT 'DRAFT',
    published_at   DATETIME NULL,
    expires_at     DATETIME NULL,
    author_user_id BIGINT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_announcements_slug (slug),
    KEY idx_announcements_visibility (status, published_at, expires_at),
    KEY idx_announcements_public (is_public, status),
    CONSTRAINT fk_announcements_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Targeting is multi-valued (role, program, year level), so it is a pivot.
-- target_id is polymorphic and therefore carries no foreign key.
CREATE TABLE announcement_targets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    target_type     VARCHAR(20) NOT NULL,
    target_id       BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_announcement_target (announcement_id, target_type, target_id),
    CONSTRAINT fk_at_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE downloads (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title             VARCHAR(200) NOT NULL,
    description       TEXT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename   VARCHAR(255) NOT NULL,
    file_size         INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type         VARCHAR(100) NULL,
    download_count    INT UNSIGNED NOT NULL DEFAULT 0,
    is_public         TINYINT(1)   NOT NULL DEFAULT 1,
    uploaded_by       BIGINT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_downloads_public (is_public),
    CONSTRAINT fk_downloads_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    `type`     VARCHAR(50)  NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT NULL,
    -- Stored as a RELATIVE path and passed through url() when rendered.
    link_url   VARCHAR(255) NULL,
    state      VARCHAR(10)  NOT NULL DEFAULT 'UNREAD',
    read_at    DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_inbox (user_id, state, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS downloads;
DROP TABLE IF EXISTS announcement_targets;
DROP TABLE IF EXISTS announcements;
