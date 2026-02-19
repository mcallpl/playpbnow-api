-- ============================================
-- PlayPBNow: Duplicate Players Cleanup Migration
--
-- Run this AFTER backing up your database!
-- Run in phpMyAdmin one section at a time.
-- ============================================

-- ──────────────────────────────────────────────
-- STEP 1: Drop the UNIQUE constraint on cell_phone
-- This is too strict — it prevents assigning a phone
-- to a player if another player (even a duplicate)
-- already has it. Multiple players can share a phone.
-- ──────────────────────────────────────────────

-- First, find the constraint name:
SHOW INDEX FROM players WHERE Column_name = 'cell_phone' AND Non_unique = 0;

-- Then drop it (replace 'cell_phone' with the actual key name from above if different):
-- ALTER TABLE players DROP INDEX cell_phone;

-- ──────────────────────────────────────────────
-- STEP 2: Find all duplicate players
-- Run this SELECT to see what duplicates exist.
-- Review before merging — some may be legitimately
-- different people with the same first name.
-- ──────────────────────────────────────────────

SELECT
    pgm.group_id,
    g.group_name,
    LOWER(TRIM(p.first_name)) AS player_name,
    GROUP_CONCAT(p.id ORDER BY p.id) AS player_ids,
    GROUP_CONCAT(IFNULL(p.cell_phone, 'no phone') ORDER BY p.id) AS phones,
    GROUP_CONCAT(CONCAT(p.wins, 'W/', p.losses, 'L') ORDER BY p.id SEPARATOR ' | ') AS stats,
    COUNT(*) AS copies
FROM players p
INNER JOIN player_group_memberships pgm ON p.id = pgm.player_id
INNER JOIN `groups` g ON pgm.group_id = g.id
GROUP BY pgm.group_id, LOWER(TRIM(p.first_name))
HAVING COUNT(*) > 1
ORDER BY copies DESC;

-- ──────────────────────────────────────────────
-- STEP 3: Merge duplicates using the app's merge UI
--
-- After reviewing the results above, use the
-- "Merge Duplicates" feature on the Players tab
-- in the app to merge them interactively.
--
-- OR call merge_players.php manually for each pair:
--   POST /merge_players.php
--   { "keep_id": <lowest_id>, "merge_id": <duplicate_id> }
-- ──────────────────────────────────────────────
