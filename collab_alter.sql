-- ============================================
-- PlayPBNow Collaboration Schema Fixes
-- Run this in phpMyAdmin on the playpbnow database
-- ============================================

-- 1. Add 'finished' to the status enum (save_scores.php sets this value)
ALTER TABLE `collab_sessions`
    MODIFY COLUMN `status` enum('active','finished','expired','closed')
        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active';

-- 2. Add s1_str and s2_str columns to collab_score_updates (PHP code uses these)
ALTER TABLE `collab_score_updates`
    ADD COLUMN IF NOT EXISTS `s1_str` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS `s2_str` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '';

-- 3. Fix any rows that have status='' (corrupted by the missing-enum bug) back to 'finished'
UPDATE `collab_sessions` SET `status` = 'finished' WHERE `status` = '';
