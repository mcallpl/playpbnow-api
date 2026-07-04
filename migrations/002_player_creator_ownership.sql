-- Phase 1 (Universal Player Identity): creator ownership on player records.
-- Anchors the "only the creator can edit a player" rule. Backfilled from each
-- player's home group owner.
ALTER TABLE players ADD COLUMN created_by_user_id INT NULL DEFAULT NULL AFTER created_by_device_id;
UPDATE players p JOIN `groups` g ON g.id = p.group_id
  SET p.created_by_user_id = g.owner_user_id
  WHERE p.created_by_user_id IS NULL;
