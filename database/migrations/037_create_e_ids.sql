-- @up
-- E-ID cards with a QR code for public verification (docs/29).
--   code: 24 random hex characters in the QR address, replaced on reissue
--   staff_number: generated for staff only; a student's number is students.student_number
CREATE TABLE e_ids (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    code            CHAR(24) NOT NULL,
    staff_number    VARCHAR(20) NULL,
    issued_at       DATETIME NOT NULL,
    issued_by       BIGINT UNSIGNED NULL,
    reissue_count   INT UNSIGNED NOT NULL DEFAULT 0,
    scan_count      INT UNSIGNED NOT NULL DEFAULT 0,
    last_scanned_at DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_e_ids_user (user_id),
    UNIQUE KEY uq_e_ids_code (code),
    UNIQUE KEY uq_e_ids_staff_number (staff_number),
    CONSTRAINT fk_e_ids_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_e_ids_issued_by FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lookups of the public verification page, counted per visitor and minute, to stop code guessing.
CREATE TABLE e_id_lookups (
    ip_address VARCHAR(45) NOT NULL,
    minute     DATETIME NOT NULL,
    lookups    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (ip_address, minute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS e_id_lookups;
DROP TABLE IF EXISTS e_ids;
