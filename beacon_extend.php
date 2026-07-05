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

$input = json_decode(file_get_contents('php://input'), true);

$beacon_id = $input['beacon_id'] ?? null;
$user_id = $input['user_id'] ?? null;
$additional_minutes = $input['additional_minutes'] ?? null;

if (!$beacon_id || !$user_id || !$additional_minutes) {
    echo json_encode(['status' => 'error', 'message' => 'beacon_id, user_id, and additional_minutes are required']);
    exit;
}

$additional_minutes = (int)$additional_minutes;
if ($additional_minutes < 1 || $additional_minutes > 240) {
    echo json_encode(['status' => 'error', 'message' => 'additional_minutes must be between 1 and 240']);
    exit;
}

$conn = getDBConnection();

// Verify beacon exists, is active, and belongs to user
$stmt = $conn->prepare("SELECT id, user_id, expires_at FROM beacons WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$beacon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$beacon) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Beacon not found or no longer active']);
    exit;
}

if ($beacon['user_id'] !== $user_id) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Only the beacon creator can extend it']);
    exit;
}

// Extend the beacon
$stmt = $conn->prepare("UPDATE beacons SET expires_at = DATE_ADD(expires_at, INTERVAL ? MINUTE) WHERE id = ?");
$stmt->bind_param('ii', $additional_minutes, $beacon_id);
$stmt->execute();
$stmt->close();

// Fetch updated beacon
$stmt = $conn->prepare("SELECT id, expires_at FROM beacons WHERE id = ?");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$updated = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

echo json_encode(['status' => 'success', 'beacon' => $updated]);
