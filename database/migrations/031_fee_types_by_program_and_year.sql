-- @up
-- Fee lines scoped to a program and/or year level (docs/14). `fee_key` is the logical fee a line
-- belongs to: for each key, the most specific applicable line wins when an assessment is made.
ALTER TABLE fee_types
    ADD COLUMN fee_key VARCHAR(30) NULL AFTER code,
    ADD COLUMN program_id BIGINT UNSIGNED NULL AFTER basis,
    ADD COLUMN year_level_id BIGINT UNSIGNED NULL AFTER program_id,
    ADD CONSTRAINT fk_fee_types_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fee_types_year_level FOREIGN KEY (year_level_id) REFERENCES year_levels (id) ON DELETE RESTRICT;

UPDATE fee_types SET fee_key = code;

ALTER TABLE fee_types MODIFY COLUMN fee_key VARCHAR(30) NOT NULL;

-- @down
ALTER TABLE fee_types
    DROP FOREIGN KEY fk_fee_types_program,
    DROP FOREIGN KEY fk_fee_types_year_level;
ALTER TABLE fee_types DROP COLUMN year_level_id, DROP COLUMN program_id, DROP COLUMN fee_key;
