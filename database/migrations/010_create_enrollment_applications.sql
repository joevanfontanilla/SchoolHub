-- @up
-- Three independent status axes (docs/26). They are separate columns on
-- purpose: an application can be APPROVED and PARTIALLY_PAID at once.
-- Status columns are written ONLY by EnrollmentStatusService (and, for
-- payment_status, FinanceService in Phase 5).
CREATE TABLE enrollment_applications (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_number VARCHAR(20) NOT NULL,
    student_id         BIGINT UNSIGNED NOT NULL,
    academic_year_id   BIGINT UNSIGNED NOT NULL,
    term_id            BIGINT UNSIGNED NOT NULL,
    program_id         BIGINT UNSIGNED NOT NULL,
    year_level_id      BIGINT UNSIGNED NOT NULL,
    student_type       VARCHAR(20)  NOT NULL,
    previous_school    VARCHAR(200) NULL,
    last_school_year   VARCHAR(20)  NULL,
    application_status VARCHAR(30)  NOT NULL DEFAULT 'DRAFT',
    payment_status     VARCHAR(20)  NOT NULL DEFAULT 'NOT_ASSESSED',
    enrollment_state   VARCHAR(20)  NOT NULL DEFAULT 'NOT_ENROLLED',
    submitted_at       DATETIME NULL,
    reviewed_at        DATETIME NULL,
    decided_at         DATETIME NULL,
    reviewed_by        BIGINT UNSIGNED NULL,
    remarks            TEXT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_applications_number (application_number),
    KEY idx_applications_queue (application_status, term_id),
    KEY idx_applications_term_program (term_id, program_id),
    KEY idx_applications_student (student_id, term_id),
    CONSTRAINT fk_applications_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT,
    CONSTRAINT fk_applications_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT,
    CONSTRAINT fk_applications_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE RESTRICT,
    CONSTRAINT fk_applications_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_applications_year_level FOREIGN KEY (year_level_id) REFERENCES year_levels (id) ON DELETE RESTRICT,
    CONSTRAINT fk_applications_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per status change, on any axis (docs/26 section 5).
CREATE TABLE enrollment_status_history (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    axis           VARCHAR(20) NOT NULL,
    previous_value VARCHAR(30) NULL,
    new_value      VARCHAR(30) NOT NULL,
    actor_user_id  BIGINT UNSIGNED NULL,
    actor_role     VARCHAR(30) NOT NULL,
    remarks        TEXT NULL,
    created_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_history_application (application_id, created_at),
    CONSTRAINT fk_history_application FOREIGN KEY (application_id) REFERENCES enrollment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS enrollment_status_history;
DROP TABLE IF EXISTS enrollment_applications;
