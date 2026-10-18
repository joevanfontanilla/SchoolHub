-- @up
-- The upload checklist follows the school's printed list (docs/10): the 2x2 photo and valid ID are no longer asked, the medical document is the clinic clearance
-- (optional to submit, due within 30 days). Nothing is deleted: documents already uploaded keep their place.
UPDATE requirement_types SET is_active = 0 WHERE code IN ('id_photo', 'valid_id');
UPDATE requirement_types
   SET name = 'Medical Clearance from Accredited Medical Clinic', description = 'Submit within 30 days after enrollment.', is_required = 0
 WHERE code = 'medical_certificate';

-- The public Requirements page (docs/06), editable by the Super Admin in System Settings; starts with the school's flyer.
INSERT INTO system_settings (`key`, `value`, `type`, `group`, description, is_public) VALUES
    ('public_requirements', '[{"title":"For Basic Education","items":["Report Card","PSA Birth Certificate"]},{"title":"For Senior High School (Grade 11 & 12)","items":["PSA Birth Certificate","Grade 10 Report Card (with Learner Reference Number or LRN)","Certificate of Junior High School Completion","Certificate of Good Moral Character","ESC Certificate from Junior High School Principal format available through the ESC IMS (ESC Grantees Only)","QVR Certificate (Qualified Voucher Receipt) for ESC only","Medical from Accredited Medical Clinic (submit after 30 days)"]},{"title":"For College: First Year College","items":["Form 138/Report Card from Senior High School","PSA Birth Certificate (Photocopy)","Certificate of Good Moral Character","Medical Clearance from Accredited Medical Clinic (submit after 30 days)"]},{"title":"For College: Transferees","items":["Honorable Dismissal","Transcript of Records (Certified Photocopy)","PSA Birth Certificate","Certificate of Good Moral Character","Medical Clearance from Accredited Medical Clinic (submit after 30 days)"]},{"title":"If Married","items":["Marriage Contract"]}]', 'json', 'enrollment', 'Admission requirements shown on the public Requirements page (edited in System Settings > Admission requirements)', 0)
ON DUPLICATE KEY UPDATE id = id;

-- @down
DELETE FROM system_settings WHERE `key` = 'public_requirements';
UPDATE requirement_types SET is_active = 1 WHERE code IN ('id_photo', 'valid_id');
UPDATE requirement_types SET name = 'Medical Certificate', description = 'Issued by a licensed physician within the last six months.' WHERE code = 'medical_certificate';
