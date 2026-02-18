<?php
// ============================================
// save_players.php V2 — Saves drag-drop order_index
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$group_key = $input['group_key'] ?? '';
$user_id   = $input['user_id'] ?? '';
$players   = $input['players'] ?? [];

error_log("📥 save_players.php - group_key: $group_key, user_id: $user_id, players: " . count($players));

if (empty($group_key)) { echo json_encode(['status' => 'error', 'message' => 'Group key required']); exit; }
if (empty($user_id))   { echo json_encode(['status' => 'error', 'message' => 'User ID required']); exit; }

try {
    // Get group
    $group = dbGetRow("SELECT id FROM `groups` WHERE group_key = ?", [$group_key]);
    if (!$group) { echo json_encode(['status' => 'error', 'message' => 'Group not found']); exit; }
    $groupDbId = (int)$group['id'];

    $conn = getDBConnection();
    $savedCount = 0;

    foreach ($players as $index => $player) {
        $playerKey = $player['id'] ?? '';
        if (!$playerKey) continue;

        // Find the player by player_key
        $existing = dbGetRow("SELECT id FROM players WHERE player_key = ?", [$playerKey]);
        if (!$existing) continue;

        $playerId = (int)$existing['id'];

        // Update order_index in memberships
        $stmt = $conn->prepare(
            "UPDATE player_group_memberships SET order_index = ? WHERE player_id = ? AND group_id = ?"
        );
        $stmt->bind_param('iii', $index, $playerId, $groupDbId);
        $stmt->execute();
        $savedCount += $stmt->affected_rows;
        $stmt->close();
    }

    $conn->close();

    error_log("✅ Saved order for $savedCount players in group $group_key");

    echo json_encode([
        'status' => 'success',
        'message' => 'Roster order saved successfully',
        'player_count' => count($players),
        'updated' => $savedCount
    ]);

} catch (Exception $e) {
    error_log("❌ Save error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
