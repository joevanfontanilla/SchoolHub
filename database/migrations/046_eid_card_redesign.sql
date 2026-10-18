-- @up
-- The redesigned e-ID card (docs/29): an optional blood type on the profile, and the President's name, title, and signature in settings.
ALTER TABLE student_profiles ADD COLUMN blood_type VARCHAR(5) NULL AFTER religion;

INSERT INTO system_settings (`key`, `value`, `type`, `group`, description, is_public) VALUES
    ('president_name', 'Engr. Jaime S. Gose', 'string', 'school', 'The President named on the e-ID card', 0),
    ('president_title', 'President & CEO', 'string', 'school', 'The President''s title on the e-ID card', 0),
    ('president_signature', '', 'string', 'school', 'Stored signature image of the President (set from System Settings)', 0)
ON DUPLICATE KEY UPDATE id = id;

-- @down
DELETE FROM system_settings WHERE `key` IN ('president_name', 'president_title', 'president_signature');
ALTER TABLE student_profiles DROP COLUMN blood_type;