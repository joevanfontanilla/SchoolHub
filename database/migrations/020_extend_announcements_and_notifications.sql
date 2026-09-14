-- @up
-- Communication module (docs/16): urgent announcements, publishing metadata, one-time
-- delivery of announcement notifications, and per-user dismissal of urgent banners.

ALTER TABLE announcements
    -- Urgent announcements also show as a banner across the portal until they expire.
    ADD COLUMN is_urgent       TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public,
    -- The image as uploaded is kept only as a display name; the file is stored under a
    -- random name outside the web root and served through an authorizing route.
    ADD COLUMN image_mime      VARCHAR(100) NULL AFTER image_path,
    ADD COLUMN image_alt       VARCHAR(200) NULL AFTER image_mime,
    ADD COLUMN published_by    BIGINT UNSIGNED NULL AFTER author_user_id,
    -- Set once, when the audience was notified. A scheduled announcement is notified
    -- the first time a request finds it due; this column makes that happen only once.
    ADD COLUMN notified_at     DATETIME NULL AFTER expires_at,
    ADD COLUMN recipient_count INT UNSIGNED NULL AFTER notified_at,
    ADD KEY idx_announcements_dispatch (status, notified_at, published_at),
    ADD CONSTRAINT fk_announcements_published_by FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE announcement_dismissals (
    announcement_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    dismissed_at    DATETIME NOT NULL,
    PRIMARY KEY (announcement_id, user_id),
    KEY idx_dismissals_user (user_id),
    CONSTRAINT fk_dismissals_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
    CONSTRAINT fk_dismissals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE announcement_targets
    ADD KEY idx_announcement_targets_lookup (target_type, target_id);

-- @down
ALTER TABLE announcement_targets DROP INDEX idx_announcement_targets_lookup;
DROP TABLE IF EXISTS announcement_dismissals;
ALTER TABLE announcements
    DROP FOREIGN KEY fk_announcements_published_by,
    DROP INDEX idx_announcements_dispatch,
    DROP COLUMN recipient_count,
    DROP COLUMN notified_at,
    DROP COLUMN published_by,
    DROP COLUMN image_alt,
    DROP COLUMN image_mime,
    DROP COLUMN is_urgent;
