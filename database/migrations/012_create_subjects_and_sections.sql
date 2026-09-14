-- @up
CREATE TABLE subjects (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(20)  NOT NULL,
    title         VARCHAR(150) NOT NULL,
    description   TEXT NULL,
    units         DECIMAL(3,1) NOT NULL,
    lecture_hours TINYINT UNSIGNED NOT NULL DEFAULT 0,
    lab_hours     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- NULL = a general subject open to every program.
    program_id    BIGINT UNSIGNED NULL,
    year_level_id BIGINT UNSIGNED NULL,
    -- The term of the year it is normally offered in (1, 2, or 3 for summer).
    term_sequence TINYINT UNSIGNED NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_code (code),
    KEY idx_subjects_program (program_id, year_level_id, term_sequence),
    CONSTRAINT fk_subjects_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_subjects_year_level FOREIGN KEY (year_level_id) REFERENCES year_levels (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Self-referencing. SubjectService rejects self-reference and cycles before saving,
-- because a cycle cannot be expressed as a constraint.
CREATE TABLE subject_prerequisites (
    subject_id              BIGINT UNSIGNED NOT NULL,
    prerequisite_subject_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (subject_id, prerequisite_subject_id),
    KEY idx_prereq_reverse (prerequisite_subject_id),
    CONSTRAINT fk_prereq_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
    CONSTRAINT fk_prereq_required FOREIGN KEY (prerequisite_subject_id) REFERENCES subjects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(20) NOT NULL,
    program_id      BIGINT UNSIGNED NOT NULL,
    year_level_id   BIGINT UNSIGNED NOT NULL,
    term_id         BIGINT UNSIGNED NOT NULL,
    -- Enforced on confirmation, with the section row locked (docs/13).
    capacity        SMALLINT UNSIGNED NOT NULL,
    adviser_user_id BIGINT UNSIGNED NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sections_code_term (code, term_id),
    KEY idx_sections_match (term_id, program_id, year_level_id, is_active),
    CONSTRAINT fk_sections_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sections_year_level FOREIGN KEY (year_level_id) REFERENCES year_levels (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sections_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sections_adviser FOREIGN KEY (adviser_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS subject_prerequisites;
DROP TABLE IF EXISTS subjects;
