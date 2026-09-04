-- ============================================================================
-- 006_collab_hardening.sql
--
-- Connected / collaborative scoring hardening (Fix Agent C1, 2026-09-04).
-- Covers audit findings #2 (write ordering), #8 (atomic shuffle reset),
-- #9 (simultaneous finish), #14 (participant duplicates / roster).
--
-- SAFE TO RUN TWICE. Every ADD COLUMN / ADD KEY is guarded by an
-- information_schema check, and every MODIFY is naturally idempotent.
-- MySQL 8 has no `ADD COLUMN IF NOT EXISTS` (that is MariaDB syntax and
-- fatals), so the guarded-prepared-statement pattern from
-- collab_alter_mysql8.sql is used throughout.
--
-- Run manually:  mysql playpbnow < migrations/006_collab_hardening.sql
--
-- Every column added here is OPTIONAL to the PHP: collab_auth.php
-- feature-detects each one and falls back to the pre-migration behaviour,
-- so the code works whether or not this file has been applied.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. collab_sessions.seq_counter  (#2)
--    Server-issued monotonic sequence for this session. Phones' clocks skew,
--    so the client's `updated_at: Date.now()` cannot order writes. Each
--    accepted write takes the next value of this counter while the session
--    row is held under SELECT ... FOR UPDATE.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_sessions' AND COLUMN_NAME='seq_counter');
SET @s := IF(@c=0,
  'ALTER TABLE `collab_sessions` ADD COLUMN `seq_counter` BIGINT NOT NULL DEFAULT 0',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 2. collab_sessions.schedule_version  (#8)
--    Incremented on EVERY schedule change (shuffle, swap, adopt-new-schedule).
--    A client that sees this number move knows its pairings are stale.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_sessions' AND COLUMN_NAME='schedule_version');
SET @s := IF(@c=0,
  'ALTER TABLE `collab_sessions` ADD COLUMN `schedule_version` BIGINT NOT NULL DEFAULT 0',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 3. collab_sessions.scores_reset_at  (#8)
--    Set ONLY when a schedule change also wiped the scores. Microsecond
--    precision so a client can tell "my write was before the reset".
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_sessions' AND COLUMN_NAME='scores_reset_at');
SET @s := IF(@c=0,
  'ALTER TABLE `collab_sessions` ADD COLUMN `scores_reset_at` DATETIME(6) NULL DEFAULT NULL',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 4. collab_sessions.status gains 'finishing'  (#9)
--    save_scores.php claims the match by moving 'active' -> 'finishing' with
--    the UPDATE itself and checking affected_rows, which closes the TOCTOU
--    that let two devices both save and double every player's W/L.
--
--    NOTE: MODIFY on an enum only ADDS a member here; the existing members
--    are all preserved, so already-stored values are untouched.
--    NOTE: save_scores.php checks information_schema for this member before
--    ever writing it — on a database where this migration has NOT run it
--    silently keeps the old read-then-write guard rather than storing an
--    invalid enum value (which is what once corrupted rows to status='').
-- ---------------------------------------------------------------------------
ALTER TABLE `collab_sessions`
  MODIFY COLUMN `status` enum('active','finishing','finished','expired','closed')
  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active';

-- Repair guard, same as collab_alter_mysql8.sql: any row corrupted to '' by
-- the historic missing-enum bug is a finished match.
UPDATE `collab_sessions` SET `status` = 'finished' WHERE `status` = '';


-- ---------------------------------------------------------------------------
-- 5. collab_score_updates: per-field sequence + writing device  (#2)
--    s1_seq / s2_seq  = seq_counter value at which that FIELD was last written
--    s1_device / s2_device = which device wrote it
--
--    Accept rule (implemented in pbnow_collab_should_accept_field()):
--      reject a field ONLY when the stored value came from a DIFFERENT device
--      AND its seq is newer than the caller's last_seen_seq.
--    A caller that sends no last_seen_seq is always accepted, so older app
--    builds behave exactly as they do today.
-- ---------------------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_score_updates' AND COLUMN_NAME='s1_seq');
SET @s := IF(@c=0,
  'ALTER TABLE `collab_score_updates` ADD COLUMN `s1_seq` BIGINT NOT NULL DEFAULT 0',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_score_updates' AND COLUMN_NAME='s2_seq');
SET @s := IF(@c=0,
  'ALTER TABLE `collab_score_updates` ADD COLUMN `s2_seq` BIGINT NOT NULL DEFAULT 0',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_score_updates' AND COLUMN_NAME='s1_device');
SET @s := IF(@c=0,
  "ALTER TABLE `collab_score_updates` ADD COLUMN `s1_device` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_score_updates' AND COLUMN_NAME='s2_device');
SET @s := IF(@c=0,
  "ALTER TABLE `collab_score_updates` ADD COLUMN `s2_device` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 6. collab_participants: device_id + de-duplicate + UNIQUE key  (#14)
--
--    The table only had a NON-unique KEY (session_id, user_id) and the PHP
--    did SELECT-then-INSERT with no transaction, so two near-simultaneous
--    polls from the same person inserted two rows and inflated
--    "connected_users" permanently.
--
--    Order matters: de-duplicate on the key that is about to become unique,
--    THEN add the column, THEN add the key.
-- ---------------------------------------------------------------------------

-- 6a. De-duplicate. If device_id does not exist yet the effective key is
--     (session_id, user_id); if it already exists (re-run) dedupe on the full
--     three-column key so genuinely distinct devices are NOT deleted.
--     Keeps the OLDEST row (MIN(id)) so joined_at is preserved, and carries
--     the newest last_seen forward is not needed — the next heartbeat sets it.
SET @has_device := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_participants' AND COLUMN_NAME='device_id');

SET @s := IF(@has_device=0,
  'DELETE p FROM `collab_participants` p JOIN (SELECT session_id, user_id, MIN(id) AS keep_id FROM `collab_participants` GROUP BY session_id, user_id) k ON p.session_id = k.session_id AND p.user_id = k.user_id AND p.id > k.keep_id',
  'DELETE p FROM `collab_participants` p JOIN (SELECT session_id, user_id, device_id, MIN(id) AS keep_id FROM `collab_participants` GROUP BY session_id, user_id, device_id) k ON p.session_id = k.session_id AND p.user_id = k.user_id AND p.device_id = k.device_id AND p.id > k.keep_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 6b. device_id column.
SET @s := IF(@has_device=0,
  "ALTER TABLE `collab_participants` ADD COLUMN `device_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 6c. UNIQUE KEY (session_id, user_id, device_id) so the heartbeat can be a
--     single INSERT ... ON DUPLICATE KEY UPDATE last_seen = NOW() with no
--     read-then-write race. The old non-unique idx_session_user is LEFT IN
--     PLACE (it still serves the lookups; dropping it is not required).
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_participants'
    AND INDEX_NAME='uniq_collab_participant');
SET @s := IF(@c=0,
  'ALTER TABLE `collab_participants` ADD UNIQUE KEY `uniq_collab_participant` (`session_id`, `user_id`, `device_id`)',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
