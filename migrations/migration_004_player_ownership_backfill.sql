-- ============================================================
-- migration_004_player_ownership_backfill.sql
-- Applied to production 2026-08-01. Backup: /root/pbn_backups/players_pgm_20260801.sql
--
-- Prep work for retiring the Players tab and the vestigial players.group_id
-- column. Both statements are idempotent — re-running them is a no-op.
--
-- Scope note: this turned out to be a 2-row fix, not the large migration it
-- looked like from the raw orphan counts. Of the 56 players with no membership
-- row, only ONE had a legacy players.group_id to recover a group from. The
-- other 55 have no group, no owner, and no match history (verified: zero rows
-- in `matches` for their player_key) — they are abandoned setup-flow entries
-- and starter "Test Group" rosters. They are already invisible everywhere in
-- the app, since get_players.php, get_all_players.php and the leaderboard all
-- read through player_group_memberships or `matches`. Left in place rather
-- than deleted; see the note at the bottom.
-- ============================================================

START TRANSACTION;

-- (1) Orphan players that still carry a legacy players.group_id but never got
--     a player_group_memberships row. Promote the legacy pointer to a real
--     membership so nothing depends on players.group_id before we drop it.
INSERT INTO player_group_memberships (player_id, group_id, order_index, joined_at)
SELECT p.id, p.group_id,
       COALESCE((SELECT MAX(order_index)+1 FROM player_group_memberships x WHERE x.group_id=p.group_id), 0),
       NOW()
FROM players p
LEFT JOIN player_group_memberships m ON m.player_id = p.id
WHERE m.id IS NULL AND p._deleted_at IS NULL AND p.group_id IS NOT NULL;
-- Affected: player 1361 (Dongiegz) → group 93 "Court 1".

-- (2) Players with NULL created_by_user_id whose owner is unambiguously
--     derivable from group ownership. The HAVING COUNT(DISTINCT ...) = 1 guard
--     means a player in two different organizers' groups is skipped rather
--     than assigned to an arbitrary one.
UPDATE players p
JOIN (SELECT m.player_id, MIN(g.owner_user_id) AS owner
      FROM player_group_memberships m
      JOIN `groups` g ON g.id = m.group_id AND g.owner_user_id IS NOT NULL
      GROUP BY m.player_id
      HAVING COUNT(DISTINCT g.owner_user_id) = 1) d
  ON d.player_id = p.id
SET p.created_by_user_id = d.owner
WHERE p.created_by_user_id IS NULL AND p._deleted_at IS NULL;
-- Affected: player 370 (Richard) → user 78.

COMMIT;

-- ── Remaining, deliberately NOT changed ─────────────────────────────────────
-- 23 players still have created_by_user_id = NULL. They have no membership, no
-- group_id, and no matches — there is no evidence to derive an owner from, so
-- guessing would invent ownership rather than recover it. They are unreachable
-- through every current query path, so they cost nothing by staying.
--
-- Cleaning them up is a product decision (soft-delete via _deleted_at), not a
-- data-integrity one, and would matter mainly to keep them out of a future
-- merge/dedup UI. Left for Chip to call.
