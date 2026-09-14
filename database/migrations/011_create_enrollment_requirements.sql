-- @up
-- One row per requested document per application.
-- is_required is a snapshot taken when the application is created, so a later
-- change to the requirement list does not rewrite an application in progress.
CREATE TABLE enrollment_requirements (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id      BIGINT UNSIGNED NOT NULL,
    requirement_type_id BIGINT UNSIGNED NOT NULL,
    is_required         TINYINT(1)  NOT NULL DEFAULT 1,
    status              VARCHAR(30) NOT NULL DEFAULT 'NOT_SUBMITTED',
    verified_by         BIGINT UNSIGNED NULL,
    verified_at         DATETIME NULL,
    remarks             TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_application_requirement (application_id, requirement_type_id),
    KEY idx_er_status (status),
    CONSTRAINT fk_er_application FOREIGN KEY (application_id) REFERENCES enrollment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_er_type FOREIGN KEY (requirement_type_id) REFERENCES requirement_types (id) ON DELETE RESTRICT,
    CONSTRAINT fk_er_verifier FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The uploaded files. A re-upload supersedes the previous file (is_current = 0)
-- instead of overwriting or deleting it, so history survives (docs/10).
CREATE TABLE enrollment_requirement_files (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrollment_requirement_id BIGINT UNSIGNED NOT NULL,
    -- Display only. Never used to build a path.
    original_filename         VARCHAR(255) NOT NULL,
    -- Generated. Never the uploaded name.
    stored_filename           VARCHAR(255) NOT NULL,
    -- Relative to storage/.
    storage_path              VARCHAR(255) NOT NULL,
    mime_type                 VARCHAR(100) NOT NULL,
    file_size                 INT UNSIGNED NOT NULL,
    checksum                  CHAR(64)     NOT NULL,
    uploaded_by               BIGINT UNSIGNED NOT NULL,
    uploaded_at               DATETIME     NOT NULL,
    is_current                TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_erf_stored (stored_filename),
    KEY idx_erf_requirement (enrollment_requirement_id, is_current),
    CONSTRAINT fk_erf_requirement FOREIGN KEY (enrollment_requirement_id) REFERENCES enrollment_requirements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_erf_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS enrollment_requirement_files;
DROP TABLE IF EXISTS enrollment_requirements;
