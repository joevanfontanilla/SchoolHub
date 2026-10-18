-- @up
-- The Registrar's name (and an optional signature image) printed on the Certificate of Registration and the other forms the registrar signs (docs/15).
INSERT INTO system_settings (`key`, `value`, `type`, `group`, description, is_public) VALUES
    ('registrar_name', 'Jury Grace Dela Cruz-Nortez', 'string', 'school', 'The Registrar named on the Certificate of Registration and the other forms the registrar signs', 0),
    ('registrar_signature', '', 'string', 'school', 'Stored signature image of the Registrar (set from System Settings)', 0)
ON DUPLICATE KEY UPDATE id = id;

-- @down
DELETE FROM system_settings WHERE `key` IN ('registrar_name', 'registrar_signature');