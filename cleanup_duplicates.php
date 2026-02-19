<?php
// ============================================
// cleanup_duplicates.php — BULK MERGE ALL DUPLICATE PLAYERS
//
// For each owner, finds players with the same first_name (case-insensitive),
// keeps the one with the most matches (or lowest ID as tiebreaker),
// merges all others into it, and deletes the duplicates.
//
// Usage:
//   GET  ?user_id=5&dry_run=1   — preview what would be merged (safe)
//   GET  ?user_id=5              — actually perform the merges
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$user_id = $_GET['user_id'] ?? '';
$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'user_id required']);
    exit;
}

try {
    $conn = getDBConnection();

    // 1. Get all players for this user
    $stmt = $conn->prepare(
        "SELECT p.* FROM players p
         INNER JOIN `groups` g ON p.group_id = g.id
         WHERE g.owner_user_id = ?
         ORDER BY p.first_name ASC, p.id ASC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $allPlayers = [];
    while ($row = $result->fetch_assoc()) {
        $allPlayers[] = $row;
    }
    $stmt->close();

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
            $countResult = $conn->query(
                "SELECT COUNT(*) as cnt FROM matches
                 WHERE p1_key = '$pk' OR p2_key = '$pk' OR p3_key = '$pk' OR p4_key = '$pk'"
            );
            $cnt = $countResult->fetch_assoc()['cnt'];
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
                    $conn->query("UPDATE matches SET $field = '" . $conn->real_escape_string($keepKey) . "' WHERE $field = '" . $conn->real_escape_string($mergeKey) . "'");
                }

                // Also update name fields in matches
                $nameFields = [
                    'p1_key' => 'p1_name', 'p2_key' => 'p2_name',
                    'p3_key' => 'p3_name', 'p4_key' => 'p4_name'
                ];
                foreach ($nameFields as $keyField => $nameField) {
                    $conn->query("UPDATE matches SET $nameField = '" . $conn->real_escape_string($keepPlayer['first_name']) . "' WHERE $keyField = '" . $conn->real_escape_string($keepKey) . "'");
                }

                // ── B. Transfer group memberships ──
                $memResult = $conn->query("SELECT group_id FROM player_group_memberships WHERE player_id = $mergeId");
                while ($mem = $memResult->fetch_assoc()) {
                    $gid = (int)$mem['group_id'];
                    $existCheck = $conn->query("SELECT id FROM player_group_memberships WHERE player_id = $keepId AND group_id = $gid");
                    if ($existCheck->num_rows === 0) {
                        $conn->query("UPDATE player_group_memberships SET player_id = $keepId WHERE player_id = $mergeId AND group_id = $gid");
                    }
                }
                $conn->query("DELETE FROM player_group_memberships WHERE player_id = $mergeId");

                // ── C. Clear merge player's phone to avoid unique constraint ──
                $conn->query("UPDATE players SET cell_phone = NULL WHERE id = $mergeId");

                // ── D. Fill in blanks on keep player ──
                $updates = [];
                if (empty($keepPlayer['cell_phone']) && !empty($mergePlayer['cell_phone'])) {
                    $updates[] = "cell_phone = '" . $conn->real_escape_string($mergePlayer['cell_phone']) . "'";
                }
                if (empty($keepPlayer['last_name']) && !empty($mergePlayer['last_name'])) {
                    $updates[] = "last_name = '" . $conn->real_escape_string($mergePlayer['last_name']) . "'";
                    $keepPlayer['last_name'] = $mergePlayer['last_name']; // carry forward
                }
                if (empty($keepPlayer['home_court_id']) && !empty($mergePlayer['home_court_id'])) {
                    $updates[] = "home_court_id = " . (int)$mergePlayer['home_court_id'];
                }
                if (empty($keepPlayer['dupr_rating']) && !empty($mergePlayer['dupr_rating'])) {
                    $updates[] = "dupr_rating = " . floatval($mergePlayer['dupr_rating']);
                }
                if (!empty($updates)) {
                    $conn->query("UPDATE players SET " . implode(', ', $updates) . " WHERE id = $keepId");
                }

                // ── E. Clean up not-duplicate records ──
                $conn->query("DELETE FROM player_not_duplicates WHERE player_id_1 = $mergeId OR player_id_2 = $mergeId");

                // ── F. Delete the duplicate player ──
                $conn->query("DELETE FROM players WHERE id = $mergeId");

                $totalDeleted++;
            }

            $totalMerged++;
        }

        // ── G. Recalculate stats for kept player (after all merges for this name) ──
        if (!$dry_run) {
            $t1 = $conn->query("SELECT s1, s2 FROM matches WHERE p1_key = '" . $conn->real_escape_string($keepKey) . "' OR p2_key = '" . $conn->real_escape_string($keepKey) . "'");
            $t2 = $conn->query("SELECT s1, s2 FROM matches WHERE p3_key = '" . $conn->real_escape_string($keepKey) . "' OR p4_key = '" . $conn->real_escape_string($keepKey) . "'");

            $wins = 0; $losses = 0; $diff = 0;
            while ($m = $t1->fetch_assoc()) {
                $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
                if ($s1 > $s2) $wins++; elseif ($s2 > $s1) $losses++;
                $diff += ($s1 - $s2);
            }
            while ($m = $t2->fetch_assoc()) {
                $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
                if ($s2 > $s1) $wins++; elseif ($s1 > $s2) $losses++;
                $diff += ($s2 - $s1);
            }
            $total = $wins + $losses;
            $winPct = $total > 0 ? round(($wins / $total) * 100, 2) : 0.00;

            $conn->query("UPDATE players SET wins = $wins, losses = $losses, diff = $diff, win_pct = $winPct WHERE id = $keepId");

            $groupAction['new_stats'] = ['wins' => $wins, 'losses' => $losses, 'diff' => $diff, 'win_pct' => $winPct];
        }

        $mergeActions[] = $groupAction;
    }

    $conn->close();

    echo json_encode([
        'status' => 'success',
        'dry_run' => $dry_run,
        'message' => $dry_run
            ? "DRY RUN: Would merge $totalMerged duplicate records. Add &dry_run=0 or remove dry_run param to execute."
            : "Merged $totalMerged duplicate records. $totalDeleted players deleted.",
        'duplicate_groups' => count($mergeActions),
        'total_merged' => $totalMerged,
        'actions' => $mergeActions,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("CLEANUP ERROR: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
