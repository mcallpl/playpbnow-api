<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

$user_id = $input['user_id'] ?? null;
$court_id = $input['court_id'] ?? null;
$beacon_type = $input['beacon_type'] ?? 'structured';
$player_count = ($beacon_type === 'casual') ? 0 : ($input['player_count'] ?? 1);
$skill_level = $input['skill_level'] ?? null;
$message = $input['message'] ?? null;
$duration_minutes = (int)($input['duration_minutes'] ?? 60);

if (!$user_id || !$court_id) {
    echo json_encode(['status' => 'error', 'message' => 'user_id and court_id are required']);
    exit;
}

$conn = getDBConnection();

// Expire old beacons first
$conn->query("UPDATE beacons SET status='expired' WHERE expires_at < NOW() AND status='active'");

// Check if beacon_type column exists (migration may not have been run yet)
$colCheck = $conn->query("SHOW COLUMNS FROM beacons LIKE 'beacon_type'");
$hasBeaconType = $colCheck && $colCheck->num_rows > 0;
if ($colCheck) $colCheck->free();

// Check if user already has an active beacon
$stmt = $conn->prepare("SELECT id FROM beacons WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('s', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$existing = $result->fetch_assoc();
$stmt->close();

if ($existing) {
    // Update existing beacon
    if ($hasBeaconType) {
        $stmt = $conn->prepare(
            "UPDATE beacons SET court_id = ?, beacon_type = ?, player_count = ?, skill_level = ?, message = ?,
             expires_at = NOW() + INTERVAL ? MINUTE
             WHERE id = ?"
        );
        $stmt->bind_param('isissii', $court_id, $beacon_type, $player_count, $skill_level, $message, $duration_minutes, $existing['id']);
    } else {
        $stmt = $conn->prepare(
            "UPDATE beacons SET court_id = ?, player_count = ?, skill_level = ?, message = ?,
             expires_at = NOW() + INTERVAL ? MINUTE
             WHERE id = ?"
        );
        $stmt->bind_param('iissii', $court_id, $player_count, $skill_level, $message, $duration_minutes, $existing['id']);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        echo json_encode(['status' => 'error', 'message' => 'Failed to update beacon: ' . $err]);
        exit;
    }
    $stmt->close();
    $beacon_id = $existing['id'];
} else {
    // Insert new beacon
    if ($hasBeaconType) {
        $stmt = $conn->prepare(
            "INSERT INTO beacons (user_id, beacon_type, court_id, player_count, skill_level, message, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', NOW() + INTERVAL ? MINUTE)"
        );
        $stmt->bind_param('ssiissi', $user_id, $beacon_type, $court_id, $player_count, $skill_level, $message, $duration_minutes);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO beacons (user_id, court_id, player_count, skill_level, message, status, expires_at)
             VALUES (?, ?, ?, ?, ?, 'active', NOW() + INTERVAL ? MINUTE)"
        );
        $stmt->bind_param('siissi', $user_id, $court_id, $player_count, $skill_level, $message, $duration_minutes);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        $conn->close();
        echo json_encode(['status' => 'error', 'message' => 'Failed to create beacon: ' . $err]);
        exit;
    }
    $beacon_id = $conn->insert_id;
    $stmt->close();
}

// Fetch the beacon to return
$selectCols = $hasBeaconType
    ? "id, beacon_type, court_id, player_count, skill_level, message, status, expires_at"
    : "id, 'structured' AS beacon_type, court_id, player_count, skill_level, message, status, expires_at";
$stmt = $conn->prepare("SELECT $selectCols FROM beacons WHERE id = ?");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$beacon = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

echo json_encode(['status' => 'success', 'beacon' => $beacon]);
