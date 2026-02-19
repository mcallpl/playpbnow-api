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
$dupr_rating = isset($input['dupr_rating']) ? $input['dupr_rating'] : null;
$home_court_id = isset($input['home_court_id']) ? $input['home_court_id'] : null;

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
        // Check if this phone is already used by a different player
        $phoneOwner = dbGetRow(
            "SELECT id, first_name FROM players WHERE cell_phone = ? AND id != ?",
            [$cell_phone, $player_id]
        );
        if ($phoneOwner) {
            echo json_encode([
                'status' => 'error',
                'message' => "This phone number is already assigned to {$phoneOwner['first_name']}. You may want to merge these players on the Players tab."
            ]);
            exit;
        }
        $updates[] = "cell_phone = ?";
        $params[] = $cell_phone;
    } else {
        $updates[] = "cell_phone = NULL";
    }
    
    if (!empty($gender)) {
        $updates[] = "gender = ?";
        $params[] = $gender;
    }

    // DUPR rating — allow setting to null (clear) or a valid number
    if ($dupr_rating !== null) {
        $rating = floatval($dupr_rating);
        if ($rating >= 1.0 && $rating <= 8.0) {
            $updates[] = "dupr_rating = ?";
            $params[] = $rating;
        } else if ($dupr_rating === '' || $dupr_rating === '0') {
            $updates[] = "dupr_rating = NULL";
        }
    }

    // Home court — allow setting or clearing
    if ($home_court_id !== null) {
        if ($home_court_id === '' || $home_court_id === 0 || $home_court_id === '0') {
            $updates[] = "home_court_id = NULL";
        } else {
            $updates[] = "home_court_id = ?";
            $params[] = (int)$home_court_id;
        }
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
