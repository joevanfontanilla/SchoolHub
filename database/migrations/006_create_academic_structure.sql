-- @up
CREATE TABLE academic_years (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ay_code (code),
    KEY idx_ay_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE terms (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_year_id     BIGINT UNSIGNED NOT NULL,
    name                 VARCHAR(50) NOT NULL,
    sequence             TINYINT UNSIGNED NOT NULL,
    start_date           DATE NOT NULL,
    end_date             DATE NOT NULL,
    enrollment_opens_at  DATETIME NULL,
    enrollment_closes_at DATETIME NULL,
    is_active            TINYINT(1) NOT NULL DEFAULT 0,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_terms_year_sequence (academic_year_id, sequence),
    KEY idx_terms_active (is_active),
    CONSTRAINT fk_terms_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code           VARCHAR(20)  NOT NULL,
    name           VARCHAR(150) NOT NULL,
    description    TEXT NULL,
    degree_level   VARCHAR(50)  NULL,
    years_duration TINYINT UNSIGNED NOT NULL DEFAULT 4,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_programs_code (code),
    KEY idx_programs_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE year_levels (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50) NOT NULL,
    sequence   TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_year_levels_sequence (sequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS year_levels;
DROP TABLE IF EXISTS programs;
DROP TABLE IF EXISTS terms;
DROP TABLE IF EXISTS academic_years;
