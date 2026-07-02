<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://peoplestar.com');
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// Must be logged in (previously this deleted any player by id with zero auth).
$auth_uid = pbnow_require_session_user();

$input = json_decode(file_get_contents('php://input'), true);
$player_id = $input['player_id'] ?? '';

if (empty($player_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Player ID required']);
    exit;
}

// Ownership: if the player records a creator, only they may delete it.
$player = dbGetRow("SELECT id, device_id, created_by_device_id FROM players WHERE id = ?", [$player_id]);
if (!$player) {
    echo json_encode(['status' => 'error', 'message' => 'Player not found']);
    exit;
}
$owner = $player['device_id'] ?: $player['created_by_device_id'];
if (!empty($owner) && (string) $owner !== (string) $auth_uid) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You can only delete players you added']);
    exit;
}

try {
    // Just delete the player record - matches/sessions stay intact
    dbQuery("DELETE FROM players WHERE id = ?", [$player_id]);
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Player deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error deleting player: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error'
    ]);
}
?>
