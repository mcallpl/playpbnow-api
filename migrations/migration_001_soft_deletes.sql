-- PlayPBNow Migration 001: Add Soft Delete Support
-- Purpose: Enable safe deletion without data loss by marking records as deleted
-- Date: 2026-06-21
-- Backward Compatible: Yes (new nullable columns, no data loss)

-- Add soft delete columns to all critical tables
-- These statements will be safely executed by the migration runner
-- which ignores duplicate column errors

ALTER TABLE users ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE players ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE `groups` ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE matches ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE match_invites ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE invite_responses ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE beacons ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE beacon_lobbies ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE broadcasts ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE collab_sessions ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE collab_participants ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE pool_players ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';
ALTER TABLE broadcast_recipients ADD COLUMN _deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp';

-- Create indexes on _deleted_at columns for efficient filtering
-- Duplicate index errors will be silently handled by the migration runner
CREATE INDEX idx_users_deleted_at ON users(_deleted_at);
CREATE INDEX idx_players_deleted_at ON players(_deleted_at);
CREATE INDEX idx_groups_deleted_at ON `groups`(_deleted_at);
CREATE INDEX idx_matches_deleted_at ON matches(_deleted_at);
CREATE INDEX idx_match_invites_deleted_at ON match_invites(_deleted_at);
CREATE INDEX idx_invite_responses_deleted_at ON invite_responses(_deleted_at);
CREATE INDEX idx_beacons_deleted_at ON beacons(_deleted_at);
CREATE INDEX idx_beacon_lobbies_deleted_at ON beacon_lobbies(_deleted_at);
CREATE INDEX idx_broadcasts_deleted_at ON broadcasts(_deleted_at);
CREATE INDEX idx_collab_sessions_deleted_at ON collab_sessions(_deleted_at);
CREATE INDEX idx_collab_participants_deleted_at ON collab_participants(_deleted_at);
CREATE INDEX idx_pool_players_deleted_at ON pool_players(_deleted_at);
CREATE INDEX idx_broadcast_recipients_deleted_at ON broadcast_recipients(_deleted_at);

-- USAGE NOTES:
-- When soft deleting a record: UPDATE users SET _deleted_at = NOW() WHERE id = ? AND _deleted_at IS NULL;
-- When querying active records: SELECT * FROM users WHERE _deleted_at IS NULL;
-- When restoring a soft-deleted record: UPDATE users SET _deleted_at = NULL WHERE id = ?;
