-- @up
-- Created only when enrollment_state becomes ENROLLED, by
-- EnrollmentConfirmationService (docs/26 section 4).
CREATE TABLE enrollments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    student_id     BIGINT UNSIGNED NOT NULL,
    term_id        BIGINT UNSIGNED NOT NULL,
    section_id     BIGINT UNSIGNED NOT NULL,
    -- Filled in when the student is enlisted in classes (Phase 4).
    total_units    DECIMAL(4,1) NOT NULL DEFAULT 0,
    enrolled_at    DATETIME NOT NULL,
    enrolled_by    BIGINT UNSIGNED NOT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'ENROLLED',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrollments_application (application_id),
    UNIQUE KEY uq_enrollments_student_term (student_id, term_id),
    KEY idx_enrollments_section (section_id, status),
    CONSTRAINT fk_enrollments_application FOREIGN KEY (application_id) REFERENCES enrollment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrollments_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrollments_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_enrollments_enrolled_by FOREIGN KEY (enrolled_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS enrollments;
