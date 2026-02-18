<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$player_id = $input['player_id'] ?? '';
$first_name = trim($input['first_name'] ?? '');
$last_name = trim($input['last_name'] ?? '');
$cell_phone = trim($input['cell_phone'] ?? '');
$gender = $input['gender'] ?? '';

if (empty($player_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Player ID required']);
    exit;
}

try {
    // Build update query dynamically
    $updates = [];
    $params = [];
    
    if (!empty($first_name)) {
        $updates[] = "first_name = ?";
        $params[] = $first_name;
    }
    
    if (!empty($last_name)) {
        $updates[] = "last_name = ?";
        $params[] = $last_name;
    } else {
        $updates[] = "last_name = NULL";
    }
    
    if (!empty($cell_phone)) {
        $updates[] = "cell_phone = ?";
        $params[] = $cell_phone;
    } else {
        $updates[] = "cell_phone = NULL";
    }
    
    if (!empty($gender)) {
        $updates[] = "gender = ?";
        $params[] = $gender;
    }
    
    $params[] = $player_id;
    
    $sql = "UPDATE players SET " . implode(", ", $updates) . " WHERE id = ?";
    
    dbQuery($sql, $params);
    
    error_log("✅ Player updated: ID $player_id");
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Player updated successfully'
    ]);
    
} catch (Exception $e) {
    error_log("❌ Update player error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
