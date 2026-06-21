-- PlayPBNow Migration 002: Create Audit Logging Infrastructure
-- Purpose: Track all critical changes (INSERT/UPDATE/DELETE) for compliance and debugging
-- Date: 2026-06-21
-- Backward Compatible: Yes (new table, no changes to existing tables)

-- Create audit_log table to track all changes
CREATE TABLE audit_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Unique audit log entry ID',
  table_name VARCHAR(64) NOT NULL COMMENT 'Name of the table being audited',
  record_id INT NOT NULL COMMENT 'ID of the record being modified',
  action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL COMMENT 'Type of change: INSERT, UPDATE, or DELETE',
  old_values JSON NULL COMMENT 'Previous values as JSON object (NULL for INSERT)',
  new_values JSON NULL COMMENT 'New values as JSON object (NULL for DELETE)',
  user_id INT NULL COMMENT 'User ID that triggered the change (NULL for system changes)',
  ip_address VARCHAR(45) NULL COMMENT 'IP address of the requester (IPv4 or IPv6)',
  timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the change occurred',
  change_hash VARCHAR(64) NULL COMMENT 'SHA256 hash of changes for integrity verification',
  INDEX idx_table_name (table_name),
  INDEX idx_record_id (record_id),
  INDEX idx_user_id (user_id),
  INDEX idx_timestamp (timestamp),
  INDEX idx_action (action),
  INDEX idx_table_record (table_name, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for all critical database changes';

-- Create indexes for common audit log queries
CREATE INDEX idx_audit_recent_changes ON audit_log(timestamp DESC);
CREATE INDEX idx_audit_user_changes ON audit_log(user_id, timestamp DESC);
CREATE INDEX idx_audit_table_changes ON audit_log(table_name, action, timestamp DESC);

-- Create audit_log_archive table for long-term storage of old audit entries
CREATE TABLE audit_log_archive (
  id BIGINT NOT NULL COMMENT 'Original audit log entry ID',
  table_name VARCHAR(64) NOT NULL COMMENT 'Name of the table being audited',
  record_id INT NOT NULL COMMENT 'ID of the record being modified',
  action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL COMMENT 'Type of change',
  old_values JSON NULL COMMENT 'Previous values as JSON object',
  new_values JSON NULL COMMENT 'New values as JSON object',
  user_id INT NULL COMMENT 'User ID that triggered the change',
  ip_address VARCHAR(45) NULL COMMENT 'IP address of the requester',
  timestamp TIMESTAMP NOT NULL COMMENT 'When the change occurred',
  change_hash VARCHAR(64) NULL COMMENT 'SHA256 hash of changes',
  archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this entry was archived',
  PRIMARY KEY (id),
  INDEX idx_archived_timestamp (timestamp),
  INDEX idx_archive_table_record (table_name, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Archive of old audit log entries for compliance';

-- USAGE NOTES:
-- Insert audit log entries via trigger or application code before critical operations.
-- Example INSERT:
--   INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, user_id, ip_address)
--   VALUES ('users', 123, 'UPDATE', JSON_OBJECT('subscription_status', 'free'), JSON_OBJECT('subscription_status', 'premium'), 5, '192.168.1.1');
--
-- Archive old entries monthly (older than 90 days):
--   INSERT INTO audit_log_archive SELECT * FROM audit_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);
--   DELETE FROM audit_log WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);
