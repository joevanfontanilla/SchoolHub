-- @up
-- The section the registrar places the student in when approving (docs/09).
-- The student is enlisted in it once the payment policy is met.
ALTER TABLE enrollment_applications
    ADD COLUMN section_id BIGINT UNSIGNED NULL AFTER year_level_id,
    ADD CONSTRAINT fk_applications_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT;

-- The subjects the student chose on the application: their program's curriculum
-- for the year level and term applied for. Enlistment and the fee assessment
-- follow this list.
CREATE TABLE application_subjects (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id BIGINT UNSIGNED NOT NULL,
    subject_id     BIGINT UNSIGNED NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_application_subject (application_id, subject_id),
    CONSTRAINT fk_appsub_application FOREIGN KEY (application_id) REFERENCES enrollment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_appsub_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The fee schedule the cashier maintains (docs/14).
-- basis: PER_UNIT (x total units), PER_LAB_UNIT (x laboratory units), FIXED (once per term).
CREATE TABLE fee_types (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    basis       VARCHAR(20)  NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One assessment per approved application, generated at approval. total_paid and
-- balance are maintained only by FinanceService, and payment_status is derived
-- from them (docs/26 section 3).
CREATE TABLE fee_assessments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_number VARCHAR(20) NOT NULL,
    application_id    BIGINT UNSIGNED NOT NULL,
    student_id        BIGINT UNSIGNED NOT NULL,
    term_id           BIGINT UNSIGNED NOT NULL,
    total_amount      DECIMAL(12,2) NOT NULL,
    total_paid        DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance           DECIMAL(12,2) NOT NULL,
    status            VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    assessed_by       BIGINT UNSIGNED NULL,
    assessed_at       DATETIME NOT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessments_number (assessment_number),
    UNIQUE KEY uq_assessments_application (application_id),
    KEY idx_assessments_student (student_id, term_id),
    CONSTRAINT fk_assessments_application FOREIGN KEY (application_id) REFERENCES enrollment_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assessments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assessments_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE RESTRICT,
    CONSTRAINT fk_assessments_assessed_by FOREIGN KEY (assessed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The lines of an assessment, copied from the fee schedule at that moment, so a
-- later change to the schedule never alters an issued assessment.
CREATE TABLE fee_items (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fee_assessment_id BIGINT UNSIGNED NOT NULL,
    fee_type_id       BIGINT UNSIGNED NULL,
    description       VARCHAR(150) NOT NULL,
    basis             VARCHAR(20)  NOT NULL,
    quantity          DECIMAL(6,2) NOT NULL,
    unit_amount       DECIMAL(12,2) NOT NULL,
    amount            DECIMAL(12,2) NOT NULL,
    sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_fee_items_assessment (fee_assessment_id),
    CONSTRAINT fk_fee_items_assessment FOREIGN KEY (fee_assessment_id) REFERENCES fee_assessments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_fee_items_type FOREIGN KEY (fee_type_id) REFERENCES fee_types (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student's online payment: amount, reference number, and proof, waiting for
-- the cashier. The proof file is stored outside the web root.
CREATE TABLE payment_submissions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fee_assessment_id BIGINT UNSIGNED NOT NULL,
    student_id        BIGINT UNSIGNED NOT NULL,
    amount            DECIMAL(12,2) NOT NULL,
    method            VARCHAR(30)  NOT NULL,
    reference_number  VARCHAR(100) NOT NULL,
    paid_on           DATE NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename   VARCHAR(100) NOT NULL,
    storage_path      VARCHAR(255) NOT NULL,
    mime_type         VARCHAR(100) NOT NULL,
    file_size         INT UNSIGNED NOT NULL,
    checksum          CHAR(64) NOT NULL,
    status            VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    submitted_at      DATETIME NOT NULL,
    reviewed_by       BIGINT UNSIGNED NULL,
    reviewed_at       DATETIME NULL,
    review_remarks    VARCHAR(500) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_submissions_queue (status, submitted_at),
    KEY idx_submissions_assessment (fee_assessment_id),
    CONSTRAINT fk_submissions_assessment FOREIGN KEY (fee_assessment_id) REFERENCES fee_assessments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_submissions_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT,
    CONSTRAINT fk_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A payment the cashier accepted, with its official receipt number. Never edited
-- or deleted (docs/14).
CREATE TABLE payments (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_number    VARCHAR(20) NOT NULL,
    receipt_number        VARCHAR(20) NOT NULL,
    fee_assessment_id     BIGINT UNSIGNED NOT NULL,
    student_id            BIGINT UNSIGNED NOT NULL,
    amount                DECIMAL(12,2) NOT NULL,
    payment_method        VARCHAR(30) NOT NULL,
    reference_number      VARCHAR(100) NULL,
    paid_at               DATETIME NOT NULL,
    cashier_user_id       BIGINT UNSIGNED NOT NULL,
    payment_submission_id BIGINT UNSIGNED NULL,
    remarks               VARCHAR(500) NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_transaction (transaction_number),
    UNIQUE KEY uq_payments_receipt (receipt_number),
    UNIQUE KEY uq_payments_submission (payment_submission_id),
    KEY idx_payments_assessment (fee_assessment_id),
    KEY idx_payments_paid_at (paid_at),
    CONSTRAINT fk_payments_assessment FOREIGN KEY (fee_assessment_id) REFERENCES fee_assessments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_cashier FOREIGN KEY (cashier_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_submission FOREIGN KEY (payment_submission_id) REFERENCES payment_submissions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS payment_submissions;
DROP TABLE IF EXISTS fee_items;
DROP TABLE IF EXISTS fee_assessments;
DROP TABLE IF EXISTS fee_types;
DROP TABLE IF EXISTS application_subjects;
ALTER TABLE enrollment_applications DROP FOREIGN KEY fk_applications_section;
ALTER TABLE enrollment_applications DROP COLUMN section_id;
