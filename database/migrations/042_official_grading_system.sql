-- @up
-- The Official Grading System (docs/27): a percentage and its equivalent are both stored, and the marks are INC, OW, UW and NGA.
ALTER TABLE grades ADD COLUMN percentage DECIMAL(5,2) NULL AFTER grade;

-- DRP (dropped) is now OW (officially withdrawn).
UPDATE grades SET remark = 'OW' WHERE remark = 'DRP';

-- Grades saved on the earlier 0.25 scale get the percentage the official table gives their equivalent. 1.75, 2.25 and 2.75 are not
-- official equivalents, so they take the midpoint of their two neighbours (87.5, 82.5, 77.5); 5.00 takes 74, the highest failing percentage.
UPDATE grades SET percentage = CASE grade
        WHEN 1.00 THEN 100.00 WHEN 1.25 THEN 95.40 WHEN 1.50 THEN 90.00 WHEN 1.75 THEN 87.50 WHEN 2.00 THEN 85.00
        WHEN 2.25 THEN 82.50 WHEN 2.50 THEN 80.00 WHEN 2.75 THEN 77.50 WHEN 3.00 THEN 75.00 WHEN 5.00 THEN 74.00
        ELSE NULL END
 WHERE grade IS NOT NULL AND percentage IS NULL;

-- ... and those three move to the official equivalent that percentage falls in (the worse neighbour, as the old rule broke ties).
UPDATE grades SET grade = CASE percentage WHEN 87.50 THEN 1.80 WHEN 82.50 THEN 2.30 WHEN 77.50 THEN 2.80 END
 WHERE grade IN (1.75, 2.25, 2.75) AND percentage IN (87.50, 82.50, 77.50);

-- @down
-- The three moved equivalents cannot be told from grades that were entered as 1.80, 2.30 or 2.80, so they stay as they are.
UPDATE grades SET remark = 'DRP' WHERE remark = 'OW';
ALTER TABLE grades DROP COLUMN percentage;
