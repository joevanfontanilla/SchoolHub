-- @up
-- Student types are now New, Old, Transferee and Summer (docs/09). The two older types that meant "already a student here" become Old.
UPDATE enrollment_applications SET student_type = 'Old' WHERE student_type IN ('Continuing', 'Returning');
UPDATE students SET student_type = 'Old' WHERE student_type IN ('Continuing', 'Returning');
UPDATE requirement_types SET applies_to = 'Old' WHERE applies_to IN ('Continuing', 'Returning', 'Continuing,Returning', 'Returning,Continuing');

-- @down
-- Continuing and Returning cannot be told apart afterwards, so Old goes back to Continuing. Summer has no earlier equivalent and becomes New.
UPDATE enrollment_applications SET student_type = 'Continuing' WHERE student_type = 'Old';
UPDATE students SET student_type = 'Continuing' WHERE student_type = 'Old';
UPDATE enrollment_applications SET student_type = 'New' WHERE student_type = 'Summer';
UPDATE students SET student_type = 'New' WHERE student_type = 'Summer';