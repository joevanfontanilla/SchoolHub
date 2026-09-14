-- @up
-- A student record exists from registration onward. student_number, program_id,
-- and year_level_id stay NULL while the person is an applicant: the student
-- number is issued only when enrollment is confirmed (docs/08, docs/17).
CREATE TABLE students (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    student_number VARCHAR(20) NULL,
    program_id     BIGINT UNSIGNED NULL,
    year_level_id  BIGINT UNSIGNED NULL,
    student_type   VARCHAR(20) NOT NULL DEFAULT 'New',
    status         VARCHAR(20) NOT NULL DEFAULT 'Applicant',
    admitted_at    DATE NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_user (user_id),
    -- A UNIQUE index allows many NULLs, so applicants without a number coexist.
    UNIQUE KEY uq_students_number (student_number),
    KEY idx_students_status (status),
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_year_level FOREIGN KEY (year_level_id) REFERENCES year_levels (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_profiles (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id        BIGINT UNSIGNED NOT NULL,
    birthdate         DATE NULL,
    sex               VARCHAR(10) NULL,
    civil_status      VARCHAR(20) NULL,
    mobile            VARCHAR(20) NULL,
    telephone         VARCHAR(20) NULL,
    street            VARCHAR(150) NULL,
    barangay          VARCHAR(100) NULL,
    city_municipality VARCHAR(100) NULL,
    province          VARCHAR(100) NULL,
    postal_code       VARCHAR(10) NULL,
    nationality       VARCHAR(50) NULL,
    religion          VARCHAR(50) NULL,
    -- Stored under storage/, never web-reachable.
    photo_path        VARCHAR(255) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_profiles_student (student_id),
    CONSTRAINT fk_student_profiles_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guardians (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id           BIGINT UNSIGNED NOT NULL,
    full_name            VARCHAR(150) NOT NULL,
    relationship         VARCHAR(50)  NOT NULL,
    mobile               VARCHAR(20)  NOT NULL,
    email                VARCHAR(150) NULL,
    address              VARCHAR(255) NULL,
    is_emergency_contact TINYINT(1)   NOT NULL DEFAULT 0,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_guardians_student (student_id),
    CONSTRAINT fk_guardians_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When the person accepted the data privacy notice at sign-up (RA 10173).
ALTER TABLE users ADD COLUMN privacy_consented_at DATETIME NULL AFTER last_login_at;

-- @down
ALTER TABLE users DROP COLUMN privacy_consented_at;
DROP TABLE IF EXISTS guardians;
DROP TABLE IF EXISTS student_profiles;
DROP TABLE IF EXISTS students;
