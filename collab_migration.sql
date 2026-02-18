-- ============================================
-- PlayPBNow Collaboration Tables
-- Run this in phpMyAdmin on your playpbnow database
-- ============================================

-- 1. Collab Sessions: stores the share code + schedule snapshot
CREATE TABLE IF NOT EXISTS `collab_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `batch_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `group_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `share_code` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL,
    `schedule_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Full schedule snapshot for Unit B',
    `scores_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT '{}' COMMENT 'Latest scores snapshot',
    `status` enum('active','expired','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_share_code_active` (`share_code`, `status`),
    KEY `idx_batch_id` (`batch_id`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Collab Participants: tracks who is connected
CREATE TABLE IF NOT EXISTS `collab_participants` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` int(11) NOT NULL,
    `user_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
    `role` enum('host','collaborator') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'collaborator',
    `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `last_seen` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_session_user` (`session_id`, `user_id`),
    KEY `idx_last_seen` (`last_seen`),
    CONSTRAINT `fk_collab_participants_session` FOREIGN KEY (`session_id`) 
        REFERENCES `collab_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Collab Score Updates: individual score changes (used for polling)
CREATE TABLE IF NOT EXISTS `collab_score_updates` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` int(11) NOT NULL,
    `round_idx` int(11) NOT NULL,
    `game_idx` int(11) NOT NULL,
    `s1` int(11) NOT NULL DEFAULT 0,
    `s2` int(11) NOT NULL DEFAULT 0,
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_session_round_game` (`session_id`, `round_idx`, `game_idx`),
    KEY `idx_updated` (`updated_at`),
    CONSTRAINT `fk_collab_updates_session` FOREIGN KEY (`session_id`) 
        REFERENCES `collab_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Also add share_code to existing sessions table (if not already there)
-- This was already in your schema, so this is just a safety check
-- ============================================
-- ALTER TABLE `sessions` ADD COLUMN IF NOT EXISTS `share_code` varchar(6) DEFAULT NULL;
