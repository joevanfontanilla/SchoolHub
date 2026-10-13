-- @up
-- Walk-in enrollment (docs/09): the registrar starts an application for an applicant who comes
-- to the office. `source` tells the two ways in apart; `created_by` records the registrar.
ALTER TABLE enrollment_applications
    ADD COLUMN source ENUM('online', 'walk_in') NOT NULL DEFAULT 'online' AFTER student_type,
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER source,
    ADD CONSTRAINT fk_ea_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT;

-- A document the registrar received in person has no uploaded file; it is verified at intake.
ALTER TABLE enrollment_requirements
    ADD COLUMN received_in_person TINYINT(1) NOT NULL DEFAULT 0 AFTER verified_at;

-- @down
ALTER TABLE enrollment_requirements DROP COLUMN received_in_person;
ALTER TABLE enrollment_applications DROP FOREIGN KEY fk_ea_created_by;
ALTER TABLE enrollment_applications DROP COLUMN created_by, DROP COLUMN source;
