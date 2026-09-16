-- @up
-- Subjects a student is credited with without taking them here: transfer credit, or
-- subjects earned before the school moved onto this system (docs/28). The registrar
-- records them per student; they count as passed, so they satisfy prerequisites and
-- curriculum progress the same way an earned final grade does. One credit per subject
-- per student; the units are snapshotted so a later change to the subject does not
-- rewrite history.
CREATE TABLE credited_subjects (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id  BIGINT UNSIGNED NOT NULL,
    subject_id  BIGINT UNSIGNED NOT NULL,
    units       DECIMAL(3,1) NOT NULL,
    -- A recorded grade if known (e.g. "1.75"), or null when only credit is granted.
    grade       VARCHAR(10) NULL,
    -- Where the credit comes from, shown on the record (e.g. "Transferred from ABC College").
    source      VARCHAR(150) NULL,
    remarks     VARCHAR(255) NULL,
    credited_by BIGINT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credited_student_subject (student_id, subject_id),
    KEY idx_credited_student (student_id),
    CONSTRAINT fk_credited_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT fk_credited_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credited_by FOREIGN KEY (credited_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS credited_subjects;
