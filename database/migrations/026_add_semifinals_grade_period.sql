-- @up
-- Add a Semi-Finals grading period between Midterm and Finals (docs/27). Only terms
-- that have no grades recorded yet are changed, so past finals never shift; a term that
-- already has grades keeps its original three-period scheme. The new default weights are
-- Prelim 20, Midterm 20, Semi-Finals 20, Finals 40 (still totalling 100).
--
-- The eligible term ids are collected into a temporary table first: MySQL forbids a
-- statement from selecting the same table it updates, and the runner keeps one
-- connection for the whole migration, so a TEMPORARY TABLE persists across statements.
CREATE TEMPORARY TABLE tmp_semis_terms (term_id BIGINT UNSIGNED NOT NULL PRIMARY KEY);

INSERT INTO tmp_semis_terms (term_id)
SELECT gp.term_id
  FROM grade_periods gp
 WHERE gp.name = 'Finals' AND gp.sequence = 3
   AND gp.term_id NOT IN (SELECT term_id FROM grade_periods WHERE name = 'Semi-Finals')
   AND gp.term_id NOT IN (
        SELECT gp2.term_id FROM grades g JOIN grade_periods gp2 ON gp2.id = g.grade_period_id
   );

UPDATE grade_periods SET sequence = 4
 WHERE name = 'Finals' AND sequence = 3
   AND term_id IN (SELECT term_id FROM tmp_semis_terms);

INSERT INTO grade_periods (term_id, name, sequence, weight, is_open)
SELECT term_id, 'Semi-Finals', 3, 20.00, 0 FROM tmp_semis_terms;

UPDATE grade_periods SET weight = 20.00
 WHERE name IN ('Prelim', 'Midterm', 'Semi-Finals')
   AND term_id IN (SELECT term_id FROM tmp_semis_terms);

UPDATE grade_periods SET weight = 40.00
 WHERE name = 'Finals'
   AND term_id IN (SELECT term_id FROM tmp_semis_terms);

DROP TEMPORARY TABLE tmp_semis_terms;

-- @down
-- Reverse the upgrade: remove the Semi-Finals period (only where it carries no grades),
-- move Finals back to sequence 3, and restore the 30/30/40 weights — for the terms whose
-- Semi-Finals is actually removed.
CREATE TEMPORARY TABLE tmp_semis_terms (term_id BIGINT UNSIGNED NOT NULL PRIMARY KEY);

INSERT INTO tmp_semis_terms (term_id)
SELECT DISTINCT term_id FROM grade_periods WHERE name = 'Semi-Finals';

DELETE FROM grade_periods
 WHERE name = 'Semi-Finals'
   AND term_id IN (SELECT term_id FROM tmp_semis_terms)
   AND id NOT IN (SELECT grade_period_id FROM grades);

UPDATE grade_periods SET sequence = 3
 WHERE name = 'Finals' AND sequence = 4
   AND term_id IN (SELECT term_id FROM tmp_semis_terms)
   AND term_id NOT IN (SELECT term_id FROM grade_periods WHERE name = 'Semi-Finals');

UPDATE grade_periods SET weight = 30.00
 WHERE name IN ('Prelim', 'Midterm')
   AND term_id IN (SELECT term_id FROM tmp_semis_terms)
   AND term_id NOT IN (SELECT term_id FROM grade_periods WHERE name = 'Semi-Finals');

UPDATE grade_periods SET weight = 40.00
 WHERE name = 'Finals'
   AND term_id IN (SELECT term_id FROM tmp_semis_terms)
   AND term_id NOT IN (SELECT term_id FROM grade_periods WHERE name = 'Semi-Finals');

DROP TEMPORARY TABLE tmp_semis_terms;
