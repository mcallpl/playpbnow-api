<?php
// ============================================
// merge_players.php — MERGE DUPLICATE PLAYERS
//
// Takes two player IDs and merges them into one:
//   - keep_id: the player to KEEP (becomes the master)
//   - merge_id: the player to MERGE INTO keep_id (gets deleted)
//
// What happens:
//   1. All match references (p1_key through p4_key) pointing to 
//      merge_id's player_key get updated to keep_id's player_key
//   2. All group memberships transfer (no duplicates)
//   3. Phone/name/court from merge_id fill in blanks on keep_id
//   4. merge_id gets deleted
//   5. Stats recalculated for keep_id
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$keep_id  = (int)($input['keep_id'] ?? 0);
$merge_id = (int)($input['merge_id'] ?? 0);

if (!$keep_id || !$merge_id || $keep_id === $merge_id) {
    echo json_encode(['status' => 'error', 'message' => 'Two different player IDs required']);
    exit;
}

try {
    $keepPlayer  = dbGetRow("SELECT * FROM players WHERE id = ?", [$keep_id]);
    $mergePlayer = dbGetRow("SELECT * FROM players WHERE id = ?", [$merge_id]);
    
    if (!$keepPlayer || !$mergePlayer) {
        echo json_encode(['status' => 'error', 'message' => 'One or both players not found']);
        exit;
    }
    
    $keepKey  = $keepPlayer['player_key'];
    $mergeKey = $mergePlayer['player_key'];
    
    $conn = getDBConnection();
    
    // ── 1. Update all match references ───────────────────────
    $fields = ['p1_key', 'p2_key', 'p3_key', 'p4_key'];
    $matchesUpdated = 0;
    
    foreach ($fields as $field) {
        $stmt = $conn->prepare("UPDATE matches SET $field = ? WHERE $field = ?");
        $stmt->bind_param('ss', $keepKey, $mergeKey);
        $stmt->execute();
        $matchesUpdated += $stmt->affected_rows;
        $stmt->close();
    }
    
    // Also update name fields
    $nameFields = [
        'p1_key' => 'p1_name', 'p2_key' => 'p2_name',
        'p3_key' => 'p3_name', 'p4_key' => 'p4_name'
    ];
    foreach ($nameFields as $keyField => $nameField) {
        $stmt = $conn->prepare("UPDATE matches SET $nameField = ? WHERE $keyField = ?");
        $keepName = $keepPlayer['first_name'];
        $stmt->bind_param('ss', $keepName, $keepKey);
        $stmt->execute();
        $stmt->close();
    }
    
    // ── 2. Transfer group memberships ────────────────────────
    $mergeMemberships = dbGetAll(
        "SELECT group_id FROM player_group_memberships WHERE player_id = ?",
        [$merge_id]
    );
    
    $membershipsTransferred = 0;
    foreach ($mergeMemberships as $mem) {
        $gid = (int)$mem['group_id'];
        // Check if keep player already in this group
        $existing = dbGetRow(
            "SELECT id FROM player_group_memberships WHERE player_id = ? AND group_id = ?",
            [$keep_id, $gid]
        );
        if (!$existing) {
            // Transfer membership
            $stmt = $conn->prepare(
                "UPDATE player_group_memberships SET player_id = ? WHERE player_id = ? AND group_id = ?"
            );
            $stmt->bind_param('iii', $keep_id, $merge_id, $gid);
            $stmt->execute();
            $stmt->close();
            $membershipsTransferred++;
        }
    }
    
    // Delete remaining merge memberships (duplicates)
    $stmt = $conn->prepare("DELETE FROM player_group_memberships WHERE player_id = ?");
    $stmt->bind_param('i', $merge_id);
    $stmt->execute();
    $stmt->close();
    
    // ── 3. Fill in blanks on keep player ─────────────────────
    $updates = [];
    if (empty($keepPlayer['cell_phone']) && !empty($mergePlayer['cell_phone'])) {
        $updates[] = "cell_phone = '" . $conn->real_escape_string($mergePlayer['cell_phone']) . "'";
    }
    if (empty($keepPlayer['last_name']) && !empty($mergePlayer['last_name'])) {
        $updates[] = "last_name = '" . $conn->real_escape_string($mergePlayer['last_name']) . "'";
    }
    if (empty($keepPlayer['home_court_id']) && !empty($mergePlayer['home_court_id'])) {
        $updates[] = "home_court_id = " . (int)$mergePlayer['home_court_id'];
    }
    if (!$keepPlayer['is_verified'] && $mergePlayer['is_verified']) {
        $updates[] = "is_verified = 1";
    }
    if (empty($keepPlayer['dupr_rating']) && !empty($mergePlayer['dupr_rating'])) {
        $updates[] = "dupr_rating = " . floatval($mergePlayer['dupr_rating']);
    }
    
    if (!empty($updates)) {
        $conn->query("UPDATE players SET " . implode(', ', $updates) . " WHERE id = $keep_id");
    }
    
    // ── 4. Clean up not-duplicate records for merged player ──
    $conn->query("DELETE FROM player_not_duplicates WHERE player_id_1 = $merge_id OR player_id_2 = $merge_id");

    // ── 5. Delete merged player ──────────────────────────────
    $stmt = $conn->prepare("DELETE FROM players WHERE id = ?");
    $stmt->bind_param('i', $merge_id);
    $stmt->execute();
    $stmt->close();
    
    // ── 6. Recalculate stats for kept player ─────────────────
    $t1Matches = dbGetAll("SELECT s1, s2 FROM matches WHERE p1_key = ? OR p2_key = ?", [$keepKey, $keepKey]);
    $t2Matches = dbGetAll("SELECT s1, s2 FROM matches WHERE p3_key = ? OR p4_key = ?", [$keepKey, $keepKey]);
    
    $wins = 0; $losses = 0; $diff = 0;
    foreach ($t1Matches as $m) {
        $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
        if ($s1 > $s2) $wins++; elseif ($s2 > $s1) $losses++;
        $diff += ($s1 - $s2);
    }
    foreach ($t2Matches as $m) {
        $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
        if ($s2 > $s1) $wins++; elseif ($s1 > $s2) $losses++;
        $diff += ($s2 - $s1);
    }
    $total = $wins + $losses;
    $winPct = $total > 0 ? round(($wins / $total) * 100, 2) : 0.00;
    
    $stmt = $conn->prepare("UPDATE players SET wins = ?, losses = ?, diff = ?, win_pct = ? WHERE id = ?");
    $stmt->bind_param('iiidi', $wins, $losses, $diff, $winPct, $keep_id);
    $stmt->execute();
    $stmt->close();
    
    $conn->close();
    
    echo json_encode([
        'status' => 'success',
        'message' => "Merged \"{$mergePlayer['first_name']}\" into \"{$keepPlayer['first_name']}\"",
        'kept_player_id' => $keep_id,
        'matches_updated' => $matchesUpdated,
        'memberships_transferred' => $membershipsTransferred,
        'stats' => ['wins' => $wins, 'losses' => $losses, 'diff' => $diff, 'win_pct' => $winPct]
    ]);
    
} catch (Exception $e) {
    error_log("MERGE ERROR: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
