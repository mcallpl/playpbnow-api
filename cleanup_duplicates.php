<?php
// ============================================
// cleanup_duplicates.php — BULK MERGE ALL DUPLICATE PLAYERS
//
// Finds ALL players with the same first_name (case-insensitive),
// keeps the one with the most matches (or lowest ID as tiebreaker),
// merges all others into it, and deletes the duplicates.
//
// Usage:
//   GET  ?dry_run=1   — preview what would be merged (safe)
//   GET  (no params)  — actually perform the merges
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';

try {
    $conn = getDBConnection();

    // 1. Get ALL players in the table
    $result = $conn->query("SELECT * FROM players ORDER BY first_name ASC, id ASC");
    $allPlayers = [];
    while ($row = $result->fetch_assoc()) {
        $allPlayers[] = $row;
    }

    // 2. Group by lowercase first_name
    $nameGroups = [];
    foreach ($allPlayers as $p) {
        $key = strtolower(trim($p['first_name']));
        if (!isset($nameGroups[$key])) $nameGroups[$key] = [];
        $nameGroups[$key][] = $p;
    }

    // 3. For each group with duplicates, find the best "keep" player
    $mergeActions = [];
    $totalMerged = 0;
    $totalDeleted = 0;

    foreach ($nameGroups as $name => $group) {
        if (count($group) <= 1) continue;

        // Count matches for each player to pick the best one to keep
        $playerMatchCounts = [];
        foreach ($group as $p) {
            $pk = $p['player_key'];
            $countRow = dbGetRow(
                "SELECT COUNT(*) as cnt FROM matches
                 WHERE p1_key = ? OR p2_key = ? OR p3_key = ? OR p4_key = ?",
                [$pk, $pk, $pk, $pk]
            );
            $cnt = $countRow['cnt'] ?? 0;
            $playerMatchCounts[$p['id']] = (int)$cnt;
        }

        // Sort: most matches first, then lowest ID as tiebreaker
        usort($group, function($a, $b) use ($playerMatchCounts) {
            $matchDiff = $playerMatchCounts[$b['id']] - $playerMatchCounts[$a['id']];
            if ($matchDiff !== 0) return $matchDiff;
            return $a['id'] - $b['id']; // lower ID = older = keep
        });

        $keepPlayer = $group[0];
        $keepKey = $keepPlayer['player_key'];
        $keepId = (int)$keepPlayer['id'];

        $groupAction = [
            'name' => $keepPlayer['first_name'],
            'total_in_group' => count($group),
            'keep' => [
                'id' => $keepId,
                'player_key' => $keepKey,
                'matches' => $playerMatchCounts[$keepId],
                'wins' => (int)$keepPlayer['wins'],
                'losses' => (int)$keepPlayer['losses'],
                'phone' => $keepPlayer['cell_phone'],
                'last_name' => $keepPlayer['last_name'],
            ],
            'merge_into_keep' => [],
        ];

        for ($i = 1; $i < count($group); $i++) {
            $mergePlayer = $group[$i];
            $mergeKey = $mergePlayer['player_key'];
            $mergeId = (int)$mergePlayer['id'];

            $groupAction['merge_into_keep'][] = [
                'id' => $mergeId,
                'player_key' => $mergeKey,
                'matches' => $playerMatchCounts[$mergeId],
                'wins' => (int)$mergePlayer['wins'],
                'losses' => (int)$mergePlayer['losses'],
                'phone' => $mergePlayer['cell_phone'],
                'last_name' => $mergePlayer['last_name'],
            ];

            if (!$dry_run) {
                // ── A. Transfer all match references ──
                foreach (['p1_key', 'p2_key', 'p3_key', 'p4_key'] as $field) {
                    dbQuery("UPDATE matches SET $field = ? WHERE $field = ?", [$keepKey, $mergeKey]);
                }

                // Also update name fields in matches
                $nameFields = [
                    'p1_key' => 'p1_name', 'p2_key' => 'p2_name',
                    'p3_key' => 'p3_name', 'p4_key' => 'p4_name'
                ];
                foreach ($nameFields as $keyField => $nameField) {
                    dbQuery("UPDATE matches SET $nameField = ? WHERE $keyField = ?", [$keepPlayer['first_name'], $keepKey]);
                }

                // ── B. Transfer group memberships ──
                $memberships = dbGetAll("SELECT group_id FROM player_group_memberships WHERE player_id = ?", [$mergeId]);
                foreach ($memberships as $mem) {
                    $gid = (int)$mem['group_id'];
                    $existCheck = dbGetRow("SELECT id FROM player_group_memberships WHERE player_id = ? AND group_id = ?", [$keepId, $gid]);
                    if (!$existCheck) {
                        dbQuery("UPDATE player_group_memberships SET player_id = ? WHERE player_id = ? AND group_id = ?", [$keepId, $mergeId, $gid]);
                    }
                }
                dbQuery("DELETE FROM player_group_memberships WHERE player_id = ?", [$mergeId]);

                // ── C. Clear merge player's phone to avoid unique constraint ──
                dbQuery("UPDATE players SET cell_phone = NULL WHERE id = ?", [$mergeId]);

                // ── D. Fill in blanks on keep player ──
                if (empty($keepPlayer['cell_phone']) && !empty($mergePlayer['cell_phone'])) {
                    dbQuery("UPDATE players SET cell_phone = ? WHERE id = ?", [$mergePlayer['cell_phone'], $keepId]);
                    $keepPlayer['cell_phone'] = $mergePlayer['cell_phone'];
                }
                if (empty($keepPlayer['last_name']) && !empty($mergePlayer['last_name'])) {
                    dbQuery("UPDATE players SET last_name = ? WHERE id = ?", [$mergePlayer['last_name'], $keepId]);
                    $keepPlayer['last_name'] = $mergePlayer['last_name'];
                }
                if (empty($keepPlayer['home_court_id']) && !empty($mergePlayer['home_court_id'])) {
                    dbQuery("UPDATE players SET home_court_id = ? WHERE id = ?", [(int)$mergePlayer['home_court_id'], $keepId]);
                }
                if (empty($keepPlayer['dupr_rating']) && !empty($mergePlayer['dupr_rating'])) {
                    dbQuery("UPDATE players SET dupr_rating = ? WHERE id = ?", [floatval($mergePlayer['dupr_rating']), $keepId]);
                }

                // ── E. Clean up not-duplicate records (safe if table doesn't exist) ──
                @dbQuery("DELETE FROM player_not_duplicates WHERE player_id_1 = ? OR player_id_2 = ?", [$mergeId, $mergeId]);

                // ── F. Delete the duplicate player ──
                dbQuery("DELETE FROM players WHERE id = ?", [$mergeId]);

                $totalDeleted++;
            }

            $totalMerged++;
        }

        // ── G. Recalculate stats for kept player (after all merges for this name) ──
        if (!$dry_run) {
            $t1Result = dbGetAll("SELECT s1, s2 FROM matches WHERE p1_key = ? OR p2_key = ?", [$keepKey, $keepKey]);
            $t2Result = dbGetAll("SELECT s1, s2 FROM matches WHERE p3_key = ? OR p4_key = ?", [$keepKey, $keepKey]);

            $wins = 0; $losses = 0; $diff = 0;
            foreach ($t1Result as $m) {
                $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
                if ($s1 > $s2) $wins++; elseif ($s2 > $s1) $losses++;
                $diff += ($s1 - $s2);
            }
            foreach ($t2Result as $m) {
                $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
                if ($s2 > $s1) $wins++; elseif ($s1 > $s2) $losses++;
                $diff += ($s2 - $s1);
            }
            $total = $wins + $losses;
            $winPct = $total > 0 ? round(($wins / $total) * 100, 2) : 0.00;

            dbQuery("UPDATE players SET wins = ?, losses = ?, diff = ?, win_pct = ? WHERE id = ?", [$wins, $losses, $diff, $winPct, $keepId]);

            $groupAction['new_stats'] = ['wins' => $wins, 'losses' => $losses, 'diff' => $diff, 'win_pct' => $winPct];
        }

        $mergeActions[] = $groupAction;
    }

    $conn->close();

    echo json_encode([
        'status' => 'success',
        'dry_run' => $dry_run,
        'message' => $dry_run
            ? "DRY RUN: Would merge $totalMerged duplicate records across " . count($mergeActions) . " names. Remove dry_run param to execute."
            : "DONE: Merged $totalMerged duplicate records. $totalDeleted players deleted.",
        'total_players_before' => count($allPlayers),
        'duplicate_groups' => count($mergeActions),
        'total_to_merge' => $totalMerged,
        'actions' => $mergeActions,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("CLEANUP ERROR: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
