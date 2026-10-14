-- @up
-- Every user's e-signature (docs/08): an image of their signature, stored under storage/uploads/signatures,
-- never web-reachable, shown to its owner on My Account and embedded in the printouts they prepare.
ALTER TABLE users ADD COLUMN signature_path VARCHAR(255) NULL AFTER avatar_path;

-- @down
ALTER TABLE users DROP COLUMN signature_path;
