-- PlayPBNow Migration 003: Add Performance Indexes
-- Purpose: Accelerate frequently-used queries by adding strategic indexes
-- Date: 2026-06-21
-- Backward Compatible: Yes (indexes only, no data changes)

-- ===== pool_players table: optimize search/filter queries =====
-- Used for talent pool browsing with filters: play_level, cities_to_play, gender
CREATE INDEX idx_pool_players_search ON pool_players(play_level, gender, created_at);

-- Optimize queries filtering by play level alone
CREATE INDEX idx_pool_players_play_level ON pool_players(play_level, created_at DESC);

-- Optimize geographic searches if cities stored as single field
CREATE INDEX idx_pool_players_cities ON pool_players(cities_to_play);

-- ===== match_invites table: optimize invite queries =====
-- Most common: find invites for a user, filter by status
CREATE INDEX idx_match_invites_user_status ON match_invites(user_id, status, created_at DESC);

-- Optimize background tasks finding pending invites
CREATE INDEX idx_match_invites_status_created ON match_invites(status, created_at);

-- ===== sessions table: optimize session lookups =====
-- Find active sessions for a user
CREATE INDEX idx_sessions_user_created ON sessions(user_id, created_at DESC);

-- Optimize cleanup queries for expired sessions
CREATE INDEX idx_sessions_created_at ON sessions(created_at);

-- ===== players table: optimize group-based queries =====
-- Find all players in a group (core scoring query)
CREATE INDEX idx_players_group_key ON players(group_id, player_key, _deleted_at);

-- Optimize lookups by device
CREATE INDEX idx_players_device_group ON players(device_id, group_id, _deleted_at);

-- Optimize win/loss calculations
CREATE INDEX idx_players_group_wins_losses ON players(group_id, wins DESC, losses DESC);

-- ===== matches table: optimize match queries =====
-- Find all matches in a group, sorted by recency
CREATE INDEX idx_matches_group_created ON matches(group_id, created_at DESC, _deleted_at);

-- Optimize match searches by date range
CREATE INDEX idx_matches_created_at ON matches(created_at DESC);

-- ===== beacon_lobbies table: optimize beacon queries =====
-- Find all lobbies for a beacon
CREATE INDEX idx_beacon_lobbies_beacon_created ON beacon_lobbies(beacon_id, created_at DESC, _deleted_at);

-- Optimize status queries
CREATE INDEX idx_beacon_lobbies_status ON beacon_lobbies(status, created_at DESC);

-- ===== invite_responses table: optimize response queries =====
-- Find all responses to an invite
CREATE INDEX idx_invite_responses_invite_id ON invite_responses(match_invite_id, created_at DESC, _deleted_at);

-- Track user responses across all invites
CREATE INDEX idx_invite_responses_user_created ON invite_responses(user_id, created_at DESC);

-- ===== broadcasts table: optimize broadcast queries =====
-- Find broadcasts by group
CREATE INDEX idx_broadcasts_group_created ON broadcasts(group_id, created_at DESC, _deleted_at);

-- ===== collab_sessions table: optimize collaboration queries =====
-- Find active collaboration sessions
CREATE INDEX idx_collab_sessions_user_created ON collab_sessions(user_id, created_at DESC, _deleted_at);

-- Find sessions in progress
CREATE INDEX idx_collab_sessions_status ON collab_sessions(status, created_at DESC);

-- ===== collab_participants table: optimize participant lookups =====
-- Find all participants in a session
CREATE INDEX idx_collab_participants_session ON collab_participants(collab_session_id, _deleted_at);

-- ===== payment_transactions table: optimize payment queries =====
-- Find transactions by user for billing history
CREATE INDEX idx_payment_transactions_user ON payment_transactions(user_id, created_at DESC);

-- Find transactions by status (pending, completed, failed)
CREATE INDEX idx_payment_transactions_status ON payment_transactions(status, created_at DESC);

-- ===== groups table: optimize group queries =====
-- Find groups by owner/admin
CREATE INDEX idx_groups_created_by ON `groups`(created_by_user_id, created_at DESC, _deleted_at);

-- ===== users table: optimize user queries =====
-- Optimize verification status queries (finding unverified users)
CREATE INDEX idx_users_phone_verified ON users(phone_verified, created_at DESC);

-- Optimize subscription queries
CREATE INDEX idx_users_subscription_status ON users(subscription_status, subscription_end_date);

-- ===== sms_credits table: optimize SMS credit queries =====
-- Find user's current SMS credit balance
CREATE INDEX idx_sms_credits_user ON sms_credits(user_id, updated_at DESC);

-- ===== sms_credit_log table: optimize SMS audit trail =====
-- Track SMS credit usage history
CREATE INDEX idx_sms_credit_log_user ON sms_credit_log(user_id, created_at DESC);

-- Track changes by transaction type
CREATE INDEX idx_sms_credit_log_type ON sms_credit_log(transaction_type, created_at DESC);

-- NOTES:
-- These indexes are designed to cover the most frequent queries without creating excessive overhead.
-- Each index targets specific query patterns used by the application.
-- Compound indexes (multi-column) are ordered by selectivity: most selective columns first.
-- _deleted_at is included in indexes where soft deletes are used for efficient filtering.
