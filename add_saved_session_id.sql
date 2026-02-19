-- Add saved_session_id column to collab_sessions table
-- This links a collab session to the saved match session so collaborators
-- can navigate directly to the correct leaderboard view
ALTER TABLE collab_sessions ADD COLUMN saved_session_id INT DEFAULT NULL AFTER status;
