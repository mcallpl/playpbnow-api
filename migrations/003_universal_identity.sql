-- 003_universal_identity.sql — Universal Player Identity (Phase 2)
--
-- "Credit follows the real person across organizers." A phone number is the
-- matching key that unifies the same person's local player rows across
-- different organizers' rosters. We store ONLY a peppered SHA-256 hash of the
-- phone on the master identity — never the raw number in this shared table.
--
-- Rules enforced in application code (identity.php / add_player.php /
-- require_admin.php), not by these DDL statements:
--   * Only Premium users can mint/attach a universal identity (add-by-phone).
--   * The shared identity table is append-only for everyone except super_admin.
--   * The raw phone stays on each organizer's own local `players` row only.
--
-- Applied live 2026-07-04.

-- Master identity, keyed by peppered phone hash. One row per real person.
CREATE TABLE IF NOT EXISTS player_identities (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    phone_hash         CHAR(64) NOT NULL,          -- sha256(pepper|E.164 digits)
    display_name       VARCHAR(120) NULL,          -- convenience label only
    claimed_by_user_id INT NULL,                   -- reserved for Phase 3 (claim)
    created_at         DATETIME NULL,
    updated_at         DATETIME NULL,
    UNIQUE KEY uq_phone_hash (phone_hash)
);

-- Link each local player row to its master identity (NULL = unlinked/local-only).
ALTER TABLE players
    ADD COLUMN identity_id INT NULL AFTER created_by_user_id,
    ADD INDEX idx_identity (identity_id);
