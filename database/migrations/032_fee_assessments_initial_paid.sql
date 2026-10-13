-- @up
-- The money paid by the moment the student was enrolled (docs/14, installments). Frozen when
-- enrollment is confirmed; NULL until then, so a not-yet-enrolled student has no installment plan.
ALTER TABLE fee_assessments ADD COLUMN initial_paid DECIMAL(12,2) NULL AFTER total_paid;

-- Students already enrolled: what they had paid up to their enrollment.
UPDATE fee_assessments fa
  JOIN enrollment_applications ea ON ea.id = fa.application_id
  JOIN enrollments en ON en.application_id = ea.id
   SET fa.initial_paid = (
       SELECT COALESCE(SUM(p.amount), 0) FROM payments p
        WHERE p.fee_assessment_id = fa.id AND p.paid_at <= en.enrolled_at
   )
 WHERE ea.enrollment_state = 'ENROLLED';

-- @down
ALTER TABLE fee_assessments DROP COLUMN initial_paid;
