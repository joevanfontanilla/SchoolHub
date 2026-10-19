-- @up
-- The Super Admin chooses the order of the public Downloads page (docs/06): the smallest number first, newest first when equal.
ALTER TABLE downloads ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0 AFTER is_public;

-- @down
ALTER TABLE downloads DROP COLUMN sort_order;
