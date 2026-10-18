-- @up
-- A document asked only of students with a given civil status (docs/10): the PSA Marriage Certificate, asked of married students.
ALTER TABLE requirement_types ADD COLUMN applies_to_civil_status VARCHAR(60) NULL AFTER applies_to;

INSERT INTO requirement_types (code, name, description, is_required, applies_to, applies_to_civil_status, sort_order)
VALUES ('marriage_certificate', 'PSA Marriage Certificate', 'Clear copy of your PSA-issued marriage certificate. Asked of married students.', 1, 'All', 'Married', 25)
ON DUPLICATE KEY UPDATE applies_to_civil_status = COALESCE(applies_to_civil_status, 'Married');

-- A married student with an application still open gets it on that application now, as the profile will do from here on.
INSERT INTO enrollment_requirements (application_id, requirement_type_id, is_required, status)
SELECT ea.id, rt.id, COALESCE(tr.is_required, rt.is_required), 'NOT_SUBMITTED'
  FROM enrollment_applications ea
  JOIN student_profiles sp ON sp.student_id = ea.student_id
  JOIN requirement_types rt ON rt.code = 'marriage_certificate'
  LEFT JOIN term_requirements tr ON tr.requirement_type_id = rt.id AND tr.term_id = ea.term_id
 WHERE sp.civil_status = 'Married'
   AND ea.application_status IN ('DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'REQUIREMENTS_INCOMPLETE', 'CORRECTION_REQUIRED')
   AND NOT EXISTS (SELECT 1 FROM enrollment_requirements er WHERE er.application_id = ea.id AND er.requirement_type_id = rt.id);

-- @down
DELETE er FROM enrollment_requirements er JOIN requirement_types rt ON rt.id = er.requirement_type_id
 WHERE rt.code = 'marriage_certificate' AND er.status = 'NOT_SUBMITTED'
   AND NOT EXISTS (SELECT 1 FROM enrollment_requirement_files f WHERE f.enrollment_requirement_id = er.id);
UPDATE requirement_types SET is_active = 0 WHERE code = 'marriage_certificate';
ALTER TABLE requirement_types DROP COLUMN applies_to_civil_status;