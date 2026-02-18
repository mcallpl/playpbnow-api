<?php
// ============================================
// delete_session.php - DATABASE VERSION
// Deletes an entire session (all matches in batch)
// ============================================

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);

$group_key = $input['group'] ?? '';
$batch_id = $input['batch_id'] ?? '';
$device_id = $input['device_id'] ?? '';

if (!$group_key || !$batch_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

// 1. FIND THE GROUP
$group = dbGetRow(
    "SELECT id FROM `groups` WHERE group_key = ?",
    [$group_key]
);

if (!$group) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}

$group_id = $group['id'];

// 2. CHECK PERMISSION (Optional - only delete if you created it)
if (!empty($device_id)) {
    $session = dbGetRow(
        "SELECT device_id FROM sessions WHERE batch_id = ?",
        [$batch_id]
    );
    
    if ($session && $session['device_id'] !== $device_id) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
}

// 3. DELETE ALL MATCHES IN THIS BATCH
$conn = getDBConnection();
$stmt = $conn->prepare(
    "DELETE FROM matches WHERE batch_id = ? AND group_id = ?"
);
$stmt->bind_param('si', $batch_id, $group_id);
$stmt->execute();
$deleted_matches = $stmt->affected_rows;
$stmt->close();
$conn->close();

// 4. DELETE THE SESSION
$conn = getDBConnection();
$stmt = $conn->prepare(
    "DELETE FROM sessions WHERE batch_id = ? AND group_id = ?"
);
$stmt->bind_param('si', $batch_id, $group_id);
$stmt->execute();
$stmt->close();
$conn->close();

if ($deleted_matches > 0) {
    echo json_encode([
        'status' => 'success',
        'deleted_matches' => $deleted_matches
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Session not found or already deleted']);
}
?>
