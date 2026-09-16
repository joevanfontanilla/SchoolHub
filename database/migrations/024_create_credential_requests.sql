-- @up
-- Students request official documents from the registrar — transcript of records, certificate of
-- good moral character, certificate of enrollment, a copy of grades (docs/08). The registrar
-- processes each request through its statuses and releases it. History is kept; a request is never
-- deleted, only cancelled or rejected.
CREATE TABLE credential_requests (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id   BIGINT UNSIGNED NOT NULL,
    type         VARCHAR(20)  NOT NULL,
    purpose      VARCHAR(200) NULL,
    copies       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- REQUESTED -> PROCESSING -> READY -> RELEASED, or REJECTED / CANCELLED (terminal).
    status       VARCHAR(20)  NOT NULL DEFAULT 'REQUESTED',
    -- A fee the registrar notes for the student to pay at the cashier; informational (docs/14).
    fee_amount   DECIMAL(12,2) NULL,
    remarks      VARCHAR(255) NULL,
    requested_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by   BIGINT UNSIGNED NULL,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    released_at  DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_credreq_student (student_id, status),
    KEY idx_credreq_status (status, requested_at),
    CONSTRAINT fk_credreq_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT fk_credreq_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS credential_requests;
