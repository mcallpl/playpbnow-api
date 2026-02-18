<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$player_id = $input['player_id'] ?? '';

if (empty($player_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Player ID required']);
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
