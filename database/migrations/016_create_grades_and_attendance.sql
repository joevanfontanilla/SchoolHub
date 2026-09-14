-- @up
-- Prelim, Midterm, Finals per term, with their weights (docs/27). The registrar
-- opens and closes them; a teacher can record grades only while one is open.
CREATE TABLE grade_periods (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    term_id    BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(30) NOT NULL,
    sequence   TINYINT UNSIGNED NOT NULL,
    weight     DECIMAL(5,2) NOT NULL,
    is_open    TINYINT(1) NOT NULL DEFAULT 0,
    opened_at  DATETIME NULL,
    opened_by  BIGINT UNSIGNED NULL,
    closed_at  DATETIME NULL,
    closed_by  BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grade_period (term_id, sequence),
    CONSTRAINT fk_gp_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gp_opened_by FOREIGN KEY (opened_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gp_closed_by FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One grade per student per class per period. Either a numeric grade on the
-- 1.00-5.00 scale, or a remark (INC, DRP) — never both. Updated in place, never
-- deleted; every change is audited with its old and new value.
CREATE TABLE grades (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_enlistment_id BIGINT UNSIGNED NOT NULL,
    grade_period_id     BIGINT UNSIGNED NOT NULL,
    grade               DECIMAL(3,2) NULL,
    remark              VARCHAR(10) NULL,
    recorded_by         BIGINT UNSIGNED NOT NULL,
    recorded_at         DATETIME NOT NULL,
    -- The last person to change an existing grade, and when (the first entry is recorded_*).
    changed_by          BIGINT UNSIGNED NULL,
    changed_at          DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grade (class_enlistment_id, grade_period_id),
    KEY idx_grade_period (grade_period_id),
    CONSTRAINT fk_grade_enlistment FOREIGN KEY (class_enlistment_id) REFERENCES class_enlistments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grade_period FOREIGN KEY (grade_period_id) REFERENCES grade_periods (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grade_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_grade_changed_by FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_enlistment_id BIGINT UNSIGNED NOT NULL,
    attendance_date     DATE NOT NULL,
    status              VARCHAR(10) NOT NULL,
    remarks             VARCHAR(255) NULL,
    recorded_by         BIGINT UNSIGNED NOT NULL,
    recorded_at         DATETIME NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance (class_enlistment_id, attendance_date),
    KEY idx_attendance_date (attendance_date),
    CONSTRAINT fk_attendance_enlistment FOREIGN KEY (class_enlistment_id) REFERENCES class_enlistments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS grades;
DROP TABLE IF EXISTS grade_periods;
