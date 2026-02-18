-- ============================================
-- PlayPBNow Subscription System Migration
-- Run this on the playpbnow database
-- ============================================

-- Ensure users table has subscription columns
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS subscription_status VARCHAR(20) DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS subscription_tier VARCHAR(20) DEFAULT 'free',
  ADD COLUMN IF NOT EXISTS subscription_end_date DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS trial_start_date DATETIME DEFAULT NULL;

-- Create feature_access table if it doesn't exist
CREATE TABLE IF NOT EXISTS feature_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  can_create_matches TINYINT(1) DEFAULT 1,
  can_edit_matches TINYINT(1) DEFAULT 0,
  can_delete_matches TINYINT(1) DEFAULT 0,
  can_generate_reports TINYINT(1) DEFAULT 0,
  can_create_groups TINYINT(1) DEFAULT 1,
  max_groups INT DEFAULT 2,
  max_collab_sessions INT DEFAULT 1,
  max_players_per_group INT DEFAULT 100,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user (user_id)
);

-- Add max_collab_sessions column if it doesn't exist
ALTER TABLE feature_access
  ADD COLUMN IF NOT EXISTS max_collab_sessions INT DEFAULT 1;

-- Update existing free users to have correct defaults
UPDATE feature_access
SET max_groups = 2, max_collab_sessions = 1, can_generate_reports = 0
WHERE user_id IN (
  SELECT id FROM users WHERE subscription_tier = 'free' OR subscription_tier IS NULL
);

-- Grant 30-day Pro trial to existing users who don't have a subscription set
UPDATE users
SET subscription_status = 'trial',
    subscription_tier = 'pro',
    trial_start_date = NOW(),
    subscription_end_date = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE (subscription_status IS NULL OR subscription_status = 'none' OR subscription_status = '')
  AND (subscription_tier IS NULL OR subscription_tier = 'free' OR subscription_tier = '');

-- Create feature_access rows for existing users who don't have one (Pro trial access)
INSERT IGNORE INTO feature_access
  (user_id, can_create_matches, can_edit_matches, can_delete_matches, can_generate_reports, can_create_groups, max_groups, max_collab_sessions, max_players_per_group)
SELECT id, 1, 1, 1, 1, 1, 999, 999, 999
FROM users
WHERE id NOT IN (SELECT user_id FROM feature_access);
