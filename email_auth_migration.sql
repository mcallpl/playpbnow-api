-- ============================================================
-- Email/Password Auth Migration
-- Run this on your PlayPBNow database
-- ============================================================

-- Add password_hash column (email column already exists)
ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL;

-- NOTE: After running this migration, set your account's password
-- by registering through the app, or use the run_migration.php helper.
