-- @up
-- A curriculum is one program's approved list of subjects for students who
-- entered under it (docs/13, docs/28). Programs revise their curricula, and a
-- student keeps the one they were admitted under, so a program can have several.
CREATE TABLE curricula (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id            BIGINT UNSIGNED NOT NULL,
    code                  VARCHAR(30)  NOT NULL,
    name                  VARCHAR(150) NOT NULL,
    -- As printed on the curriculum, e.g. 2024-2025.
    effective_school_year CHAR(9)      NOT NULL,
    notes                 VARCHAR(255) NULL,
    is_active             TINYINT(1)   NOT NULL DEFAULT 1,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_curricula_code (code),
    KEY idx_curricula_program (program_id, is_active, effective_school_year),
    CONSTRAINT fk_curricula_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subject codes are the school's own and are reused across programs with
-- different meanings (CORE 1 is Financial Accounting in BSA but Basic Accounting
-- in BPA), so a code is unique within its curriculum, not school-wide.
ALTER TABLE subjects
    ADD COLUMN curriculum_id BIGINT UNSIGNED NULL AFTER program_id,
    ADD COLUMN lab_units DECIMAL(3,1) NOT NULL DEFAULT 0 AFTER units,
    ADD COLUMN min_progress_percent TINYINT UNSIGNED NULL AFTER term_sequence;

ALTER TABLE subjects DROP INDEX uq_subjects_code;

ALTER TABLE subjects
    ADD UNIQUE KEY uq_subjects_curriculum_code (curriculum_id, code),
    ADD CONSTRAINT fk_subjects_curriculum FOREIGN KEY (curriculum_id) REFERENCES curricula (id) ON DELETE RESTRICT;

-- Subjects created before curricula existed move into one curriculum per program.
INSERT INTO curricula (program_id, code, name, effective_school_year, notes)
SELECT p.id, CONCAT(p.code, '-EARLIER'), p.name,
       CONCAT(YEAR(CURDATE()), '-', YEAR(CURDATE()) + 1),
       'Subjects entered before curricula were introduced.'
  FROM programs p
 WHERE EXISTS (SELECT 1 FROM subjects s WHERE s.program_id = p.id);

UPDATE subjects s
  JOIN programs p  ON p.id = s.program_id
  JOIN curricula c ON c.code = CONCAT(p.code, '-EARLIER')
   SET s.curriculum_id = c.id;

ALTER TABLE students
    ADD COLUMN curriculum_id BIGINT UNSIGNED NULL AFTER program_id,
    ADD CONSTRAINT fk_students_curriculum FOREIGN KEY (curriculum_id) REFERENCES curricula (id) ON DELETE RESTRICT;

UPDATE students st
  JOIN programs p  ON p.id = st.program_id
  JOIN curricula c ON c.code = CONCAT(p.code, '-EARLIER')
   SET st.curriculum_id = c.id;

-- @down
-- Restoring the school-wide unique code fails while two curricula share a code;
-- that is intentional — rolling back must not silently merge different subjects.
ALTER TABLE students DROP FOREIGN KEY fk_students_curriculum;
ALTER TABLE students DROP COLUMN curriculum_id;
ALTER TABLE subjects DROP FOREIGN KEY fk_subjects_curriculum;
ALTER TABLE subjects DROP INDEX uq_subjects_curriculum_code;
ALTER TABLE subjects ADD UNIQUE KEY uq_subjects_code (code);
ALTER TABLE subjects DROP COLUMN min_progress_percent;
ALTER TABLE subjects DROP COLUMN lab_units;
ALTER TABLE subjects DROP COLUMN curriculum_id;
DROP TABLE IF EXISTS curricula;
