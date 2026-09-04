-- PlayPBNow Migration 005: session metadata (fixed teams / tournament placements)
-- Date: 2026-09-04 (UAT Audit C M5 / Audit E H4)
-- Backward compatible: yes (nullable / defaulted columns, no data change).
--
-- The audit verified these columns ALREADY EXIST on the live `sessions` table,
-- so on production this file is a no-op safety net. It is here so a fresh
-- environment (or a restore from an older dump) gets the same shape.
-- save_scores.php writes both columns inside a try/catch and
-- get_leaderboard.php reads them inside a try/catch, so a missing column can
-- never break saving or rankings — it just drops the badge.
--
-- Run (MySQL 8+ and MariaDB; safe to re-run):
--   mysql playpbnow < playpbnow-api/migrations/005_session_meta.sql

DELIMITER $$
DROP PROCEDURE IF EXISTS pbn_migration_005$$
CREATE PROCEDURE pbn_migration_005()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'is_fixed_teams'
  ) THEN
    ALTER TABLE sessions ADD COLUMN is_fixed_teams TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Fixed-partner (team) session';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'tournament_placements'
  ) THEN
    ALTER TABLE sessions ADD COLUMN tournament_placements TEXT NULL COMMENT 'JSON array of final placements (tournament sessions)';
  END IF;
END$$
DELIMITER ;

CALL pbn_migration_005();
DROP PROCEDURE IF EXISTS pbn_migration_005;
