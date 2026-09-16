-- @up
-- A profile photo for every user. Students already keep theirs on student_profiles.photo_path
-- (their record photo, which the registrar can also set); staff and Super Admin have no student
-- record, so their avatar lives here. AvatarService reads whichever applies. Stored under
-- storage/uploads/avatars, never web-reachable, served only through /account/photo.
ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER must_change_password;

-- @down
ALTER TABLE users DROP COLUMN avatar_path;
