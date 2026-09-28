-- @up
-- An optional name suffix (Jr., Sr., II, III, …). Nullable: most people have none.
ALTER TABLE users ADD COLUMN suffix VARCHAR(20) NULL AFTER last_name;

-- @down
ALTER TABLE users DROP COLUMN suffix;
