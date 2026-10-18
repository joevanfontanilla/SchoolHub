-- @up
-- Every term gets a Semi-Finals period (docs/27). Migration 026 skipped any term that already had a grade; now only a term where a
-- student already has a COMPLETE final (every one of its three periods graded) is skipped, because that final would change. Grades
-- already entered are never touched. A skipped term can be handled by hand on Registrar -> Grading periods once it is safe.
-- (The eligible terms go into a temporary table first: MySQL forbids selecting from the table an UPDATE changes.)
CREATE TEMPORARY TABLE tmp_semis_terms (term_id BIGINT UNSIGNED NOT NULL PRIMARY KEY);

INSERT INTO tmp_semis_terms (term_id)
SELECT gp.term_id
  FROM grade_periods gp
 WHERE gp.name = 'Finals' AND gp.sequence = 3
   AND gp.term_id NOT IN (SELECT term_id FROM grade_periods WHERE name = 'Semi-Finals')
   AND gp.term_id NOT IN (
        SELECT done.term_id FROM (
            SELECT cs.term_id AS term_id
              FROM grades g
              JOIN class_enlistments ce ON ce.id = g.class_enlistment_id
              JOIN class_schedules cs   ON cs.id = ce.class_schedule_id
             GROUP BY cs.term_id, ce.id
            HAVING COUNT(*) >= 3
        ) done
   );

UPDATE grade_periods SET sequence = 4, weight = 40.00
 WHERE name = 'Finals' AND sequence = 3
   AND term_id IN (SELECT term_id FROM tmp_semis_terms);

INSERT INTO grade_periods (term_id, name, sequence, weight, is_open)
SELECT term_id, 'Semi-Finals', 3, 20.00, 0 FROM tmp_semis_terms;

UPDATE grade_periods SET weight = 20.00
 WHERE name IN ('Prelim', 'Midterm')
   AND term_id IN (SELECT term_id FROM tmp_semis_terms);

DROP TEMPORARY TABLE tmp_semis_terms;

-- @down
-- Not reversible: afterwards there is no telling which terms had Semi-Finals before. Remove a period by hand if one ever has to go.
DO 0;