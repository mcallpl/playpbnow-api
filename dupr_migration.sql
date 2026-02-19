-- ============================================
-- DUPR Rating + Smart Duplicate Handling Migration
-- Run this on the playpbnow database
-- ============================================

-- Add DUPR rating to players table
ALTER TABLE players ADD COLUMN dupr_rating DECIMAL(3,2) DEFAULT NULL AFTER win_pct;

-- Create table to track confirmed non-duplicates
-- When user says "these two Davids are different people", store that here
CREATE TABLE IF NOT EXISTS player_not_duplicates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id_1 INT NOT NULL,
    player_id_2 INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pair (player_id_1, player_id_2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
