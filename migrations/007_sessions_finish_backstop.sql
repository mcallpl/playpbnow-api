-- ============================================================================
-- 007_sessions_finish_backstop.sql
--
-- Audit finding #9 backstop (Fix Agent C1, 2026-09-04).
--
-- The real fix for "two devices press FINISH at the same instant and every
-- player's W/L doubles" is in save_scores.php: the collab session is now
-- CLAIMED with the write itself ('active' -> 'finishing', check affected_rows)
-- before a single row is inserted. This file is the belt-and-braces database
-- backstop for the same class of bug on the non-collaborative path — the
-- duplicate-detection SELECT on (group_id, session_date, title) has always
-- been a read-then-write with nothing stopping two concurrent requests from
-- both passing it.
--
-- SAFE TO RUN TWICE, and SAFE TO RUN ON DIRTY DATA: the ALTER is skipped
-- entirely if any duplicate (group_id, session_date, title) rows exist, so the
-- migration can never fail half-way or destroy anything. Re-run it after
-- cleaning duplicates and it will add the key then.
--
-- Verified 2026-09-04 against production: `sessions` has UNIQUE keys on
-- batch_id and share_code but NOT on (group_id, session_date, title), and a
-- duplicate check returned ZERO rows — so on the live database this adds the
-- key immediately with no cleanup required.
--
-- Run manually:  mysql playpbnow < migrations/007_sessions_finish_backstop.sql
--
-- To see whether it applied:
--   SHOW INDEX FROM `sessions` WHERE Key_name = 'uniq_session_group_date_title';
-- ============================================================================

-- Does the key already exist?
SET @has_key := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sessions'
    AND INDEX_NAME='uniq_session_group_date_title');

-- Are there any rows that would violate it? (0 on production as of 2026-09-04)
SET @dupes := (SELECT COUNT(*) FROM (
  SELECT group_id, session_date, title
  FROM `sessions`
  GROUP BY group_id, session_date, title
  HAVING COUNT(*) > 1
) d);

SET @s := IF(@has_key = 0 AND @dupes = 0,
  'ALTER TABLE `sessions` ADD UNIQUE KEY `uniq_session_group_date_title` (`group_id`, `session_date`, `title`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Report what happened (visible when run from the mysql CLI).
SELECT
  @has_key AS key_already_present,
  @dupes   AS duplicate_groups_found,
  IF(@has_key = 0 AND @dupes = 0, 'ADDED uniq_session_group_date_title',
     IF(@has_key > 0, 'SKIPPED - key already present',
        'SKIPPED - duplicates exist, clean them first then re-run')) AS result;
