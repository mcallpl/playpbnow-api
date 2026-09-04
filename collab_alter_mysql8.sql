-- ============================================================================
-- collab_alter_mysql8.sql
-- MySQL-8-safe, idempotent version of the collab schema fixes that previously
-- lived only in the server-side one-shot `run_collab_alter.php` (removed
-- 2026-07-08: it was publicly reachable, ran ALTERs on any hit, dumped session
-- data, and used MariaDB-only `ADD COLUMN IF NOT EXISTS` which fatals on MySQL 8).
--
-- These columns already exist in production as of 2026-07-08; this file is the
-- committed record of the change and is safe to re-run (each guard is a no-op
-- when the column is already present).
-- Run manually:  mysql playpbnow < collab_alter_mysql8.sql
-- ============================================================================

-- status enum widen (idempotent — MODIFY is safe to repeat)
ALTER TABLE `collab_sessions`
  MODIFY COLUMN `status` enum('active','finished','expired','closed')
  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active';

UPDATE `collab_sessions` SET `status` = 'finished' WHERE `status` = '';

-- Guarded ADD COLUMNs (MySQL 8 has no ADD COLUMN IF NOT EXISTS)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_score_updates' AND COLUMN_NAME='s1_str');
SET @s := IF(@c=0, "ALTER TABLE `collab_score_updates` ADD COLUMN `s1_str` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''", 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_score_updates' AND COLUMN_NAME='s2_str');
SET @s := IF(@c=0, "ALTER TABLE `collab_score_updates` ADD COLUMN `s2_str` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''", 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_sessions' AND COLUMN_NAME='creator_user_id');
SET @s := IF(@c=0, "ALTER TABLE `collab_sessions` ADD COLUMN `creator_user_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''", 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
