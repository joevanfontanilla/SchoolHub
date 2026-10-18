-- @up
-- A teacher submits one class's grades for one period; the registrar confirms them before students can see them (docs/27).
CREATE TABLE grade_submissions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_schedule_id BIGINT UNSIGNED NOT NULL,
    grade_period_id   BIGINT UNSIGNED NOT NULL,
    -- DRAFT: the teacher is entering. SUBMITTED: waiting for the registrar. CONFIRMED: released to students. RETURNED: sent back to the teacher.
    status            VARCHAR(12)  NOT NULL DEFAULT 'DRAFT',
    submitted_by      BIGINT UNSIGNED NULL,
    submitted_at      DATETIME     NULL,
    confirmed_by      BIGINT UNSIGNED NULL,
    confirmed_at      DATETIME     NULL,
    returned_by       BIGINT UNSIGNED NULL,
    returned_at       DATETIME     NULL,
    return_reason     VARCHAR(500) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_grade_submission (class_schedule_id, grade_period_id),
    KEY idx_grade_submission_status (status, submitted_at),
    CONSTRAINT fk_gsub_class FOREIGN KEY (class_schedule_id) REFERENCES class_schedules (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gsub_period FOREIGN KEY (grade_period_id) REFERENCES grade_periods (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gsub_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gsub_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gsub_returned_by FOREIGN KEY (returned_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every grade that already exists was visible to students, so it counts as confirmed: nothing disappears when this goes live.
INSERT INTO grade_submissions (class_schedule_id, grade_period_id, status, confirmed_at)
SELECT DISTINCT ce.class_schedule_id, g.grade_period_id, 'CONFIRMED', NOW()
  FROM grades g JOIN class_enlistments ce ON ce.id = g.class_enlistment_id;

-- @down
DROP TABLE IF EXISTS grade_submissions;
