-- @up
-- The configurable document list (docs/10).
CREATE TABLE requirement_types (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code               VARCHAR(40)  NOT NULL,
    name               VARCHAR(150) NOT NULL,
    description        VARCHAR(255) NULL,
    is_required        TINYINT(1)   NOT NULL DEFAULT 1,
    -- Comma-separated student types this document is asked of, or 'All'
    -- (e.g. 'New,Transferee'). Matched with FIND_IN_SET.
    applies_to         VARCHAR(60)  NOT NULL DEFAULT 'All',
    max_file_size_kb   INT UNSIGNED NOT NULL DEFAULT 5120,
    allowed_extensions VARCHAR(100) NOT NULL DEFAULT 'pdf,jpg,jpeg,png',
    sort_order         SMALLINT     NOT NULL DEFAULT 0,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_requirement_types_code (code),
    KEY idx_requirement_types_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-term override of whether a document is required (docs/10: each enrollment
-- period can have its own list).
CREATE TABLE term_requirements (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    term_id             BIGINT UNSIGNED NOT NULL,
    requirement_type_id BIGINT UNSIGNED NOT NULL,
    is_required         TINYINT(1) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_term_requirement (term_id, requirement_type_id),
    CONSTRAINT fk_tr_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE,
    CONSTRAINT fk_tr_requirement FOREIGN KEY (requirement_type_id) REFERENCES requirement_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS term_requirements;
DROP TABLE IF EXISTS requirement_types;
