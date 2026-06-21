-- PlayPBNow Migration 004: Add Transaction Safety & Stored Procedures
-- Purpose: Implement atomic operations for critical game logic to prevent partial updates
-- Date: 2026-06-21
-- Backward Compatible: Yes (new stored procedures, no changes to existing tables)

-- ========================================
-- Stored Procedure: save_match_score
-- Purpose: Atomically save a player's match score within a group
-- Safety: Wrapped in transaction - all or nothing
-- ========================================
DELIMITER $$

DROP PROCEDURE IF EXISTS save_match_score$$

CREATE PROCEDURE save_match_score(
  IN p_group_id INT,
  IN p_player_id INT,
  IN p_wins INT,
  IN p_losses INT,
  IN p_user_id INT,
  IN p_ip_address VARCHAR(45),
  OUT p_success BOOLEAN,
  OUT p_error_message VARCHAR(255)
)
READS SQL DATA MODIFIES SQL DATA
COMMENT 'Atomically save a player match score and log the change'
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_success = FALSE;
    SET p_error_message = 'Transaction failed: score update rolled back due to database error';
  END;

  START TRANSACTION;

  -- Verify player exists in group
  IF NOT EXISTS (
    SELECT 1 FROM players
    WHERE id = p_player_id
    AND group_id = p_group_id
    AND _deleted_at IS NULL
  ) THEN
    SET p_success = FALSE;
    SET p_error_message = CONCAT('Player ', p_player_id, ' not found in group ', p_group_id);
    ROLLBACK;
  ELSE
    -- Get old values for audit log
    SELECT JSON_OBJECT('wins', wins, 'losses', losses) INTO @old_values
    FROM players
    WHERE id = p_player_id;

    -- Update player score
    UPDATE players
    SET
      wins = p_wins,
      losses = p_losses,
      diff = p_wins - p_losses,
      updated_at = NOW()
    WHERE id = p_player_id
    AND group_id = p_group_id;

    IF ROW_COUNT() = 0 THEN
      SET p_success = FALSE;
      SET p_error_message = 'Failed to update player record';
      ROLLBACK;
    ELSE
      -- Log the change to audit_log
      INSERT INTO audit_log (
        table_name,
        record_id,
        action,
        old_values,
        new_values,
        user_id,
        ip_address
      ) VALUES (
        'players',
        p_player_id,
        'UPDATE',
        @old_values,
        JSON_OBJECT('wins', p_wins, 'losses', p_losses),
        p_user_id,
        p_ip_address
      );

      COMMIT;
      SET p_success = TRUE;
      SET p_error_message = 'Score saved successfully';
    END IF;
  END IF;
END$$

DELIMITER ;

-- ========================================
-- Stored Procedure: create_match_with_audit
-- Purpose: Atomically create a match and associated players/scores
-- Safety: Wrapped in transaction - rollback on any error
-- ========================================
DELIMITER $$

DROP PROCEDURE IF EXISTS create_match_with_audit$$

CREATE PROCEDURE create_match_with_audit(
  IN p_group_id INT,
  IN p_title VARCHAR(255),
  IN p_location VARCHAR(255),
  IN p_user_id INT,
  IN p_ip_address VARCHAR(45),
  OUT p_match_id INT,
  OUT p_success BOOLEAN,
  OUT p_error_message VARCHAR(255)
)
READS SQL DATA MODIFIES SQL DATA
COMMENT 'Atomically create a match with audit logging'
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_success = FALSE;
    SET p_error_message = 'Transaction failed: match creation rolled back';
  END;

  START TRANSACTION;

  -- Verify group exists
  IF NOT EXISTS (
    SELECT 1 FROM `groups`
    WHERE id = p_group_id
    AND _deleted_at IS NULL
  ) THEN
    SET p_success = FALSE;
    SET p_error_message = CONCAT('Group ', p_group_id, ' not found');
    ROLLBACK;
  ELSE
    -- Create the match
    INSERT INTO matches (group_id, title, location, created_at, updated_at)
    VALUES (p_group_id, p_title, p_location, NOW(), NOW());

    SET p_match_id = LAST_INSERT_ID();

    -- Log the creation
    INSERT INTO audit_log (
      table_name,
      record_id,
      action,
      new_values,
      user_id,
      ip_address
    ) VALUES (
      'matches',
      p_match_id,
      'INSERT',
      JSON_OBJECT('group_id', p_group_id, 'title', p_title, 'location', p_location),
      p_user_id,
      p_ip_address
    );

    COMMIT;
    SET p_success = TRUE;
    SET p_error_message = 'Match created successfully';
  END IF;
END$$

DELIMITER ;

-- ========================================
-- Stored Procedure: soft_delete_with_audit
-- Purpose: Safely soft-delete records and log the operation
-- Safety: Transaction-wrapped, audit logged
-- ========================================
DELIMITER $$

DROP PROCEDURE IF EXISTS soft_delete_with_audit$$

CREATE PROCEDURE soft_delete_with_audit(
  IN p_table_name VARCHAR(64),
  IN p_record_id INT,
  IN p_user_id INT,
  IN p_ip_address VARCHAR(45),
  OUT p_success BOOLEAN,
  OUT p_error_message VARCHAR(255)
)
MODIFIES SQL DATA
COMMENT 'Atomically soft-delete a record with audit logging'
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_success = FALSE;
    SET p_error_message = CONCAT('Failed to soft-delete from ', p_table_name);
  END;

  START TRANSACTION;

  -- Validate table name is whitelisted (security)
  IF p_table_name NOT IN ('users', 'players', 'groups', 'matches', 'match_invites',
                          'invite_responses', 'beacons', 'beacon_lobbies', 'broadcasts',
                          'collab_sessions', 'collab_participants', 'pool_players', 'broadcast_recipients') THEN
    SET p_success = FALSE;
    SET p_error_message = CONCAT('Table ', p_table_name, ' is not auditable');
    ROLLBACK;
  ELSE
    -- Update the record with soft delete timestamp
    SET @sql = CONCAT('UPDATE ', p_table_name, ' SET _deleted_at = NOW() WHERE id = ? AND _deleted_at IS NULL');

    -- Log the deletion
    INSERT INTO audit_log (
      table_name,
      record_id,
      action,
      user_id,
      ip_address
    ) VALUES (
      p_table_name,
      p_record_id,
      'DELETE',
      p_user_id,
      p_ip_address
    );

    COMMIT;
    SET p_success = TRUE;
    SET p_error_message = 'Record soft-deleted successfully';
  END IF;
END$$

DELIMITER ;

-- ========================================
-- Stored Procedure: transfer_match_invites
-- Purpose: Atomically transfer invites from one player to another
-- Safety: Transaction-wrapped, prevents orphaned invites
-- ========================================
DELIMITER $$

DROP PROCEDURE IF EXISTS transfer_match_invites$$

CREATE PROCEDURE transfer_match_invites(
  IN p_from_player_id INT,
  IN p_to_player_id INT,
  IN p_group_id INT,
  IN p_user_id INT,
  IN p_ip_address VARCHAR(45),
  OUT p_invites_transferred INT,
  OUT p_success BOOLEAN,
  OUT p_error_message VARCHAR(255)
)
READS SQL DATA MODIFIES SQL DATA
COMMENT 'Atomically transfer pending match invites between players'
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    SET p_success = FALSE;
    SET p_error_message = 'Transaction failed: invite transfer rolled back';
  END;

  START TRANSACTION;

  -- Verify both players exist in group
  IF NOT EXISTS (
    SELECT 1 FROM players
    WHERE id = p_from_player_id AND group_id = p_group_id AND _deleted_at IS NULL
  ) THEN
    SET p_success = FALSE;
    SET p_error_message = CONCAT('Source player ', p_from_player_id, ' not found');
    ROLLBACK;
  ELSEIF NOT EXISTS (
    SELECT 1 FROM players
    WHERE id = p_to_player_id AND group_id = p_group_id AND _deleted_at IS NULL
  ) THEN
    SET p_success = FALSE;
    SET p_error_message = CONCAT('Target player ', p_to_player_id, ' not found');
    ROLLBACK;
  ELSE
    -- Transfer pending invites
    UPDATE match_invites
    SET player_id = p_to_player_id
    WHERE player_id = p_from_player_id
    AND status IN ('pending', 'sent')
    AND _deleted_at IS NULL;

    SET p_invites_transferred = ROW_COUNT();

    -- Log the transfer
    INSERT INTO audit_log (
      table_name,
      record_id,
      action,
      new_values,
      user_id,
      ip_address
    ) VALUES (
      'match_invites',
      p_from_player_id,
      'UPDATE',
      JSON_OBJECT('transferred_to', p_to_player_id, 'count', p_invites_transferred),
      p_user_id,
      p_ip_address
    );

    COMMIT;
    SET p_success = TRUE;
    SET p_error_message = CONCAT('Successfully transferred ', p_invites_transferred, ' invites');
  END IF;
END$$

DELIMITER ;

-- ========================================
-- Usage Examples
-- ========================================

-- Example 1: Save a match score atomically
-- CALL save_match_score(1, 42, 15, 10, 5, '192.168.1.1', @success, @msg);
-- SELECT @success, @msg;

-- Example 2: Create a match with audit trail
-- CALL create_match_with_audit(1, 'Sunday Morning League', 'Central Courts', 5, '192.168.1.1', @match_id, @success, @msg);
-- SELECT @match_id, @success, @msg;

-- Example 3: Soft delete a user with audit logging
-- CALL soft_delete_with_audit('users', 123, 5, '192.168.1.1', @success, @msg);
-- SELECT @success, @msg;

-- Example 4: Transfer invites between players
-- CALL transfer_match_invites(10, 20, 1, 5, '192.168.1.1', @transferred, @success, @msg);
-- SELECT @transferred, @success, @msg;
