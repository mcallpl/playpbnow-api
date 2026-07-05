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

if (!$beacon_id || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'beacon_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

// Verify beacon exists, is active, and belongs to user
$stmt = $conn->prepare("SELECT id, user_id FROM beacons WHERE id = ? AND status = 'active'");
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
    echo json_encode(['status' => 'error', 'message' => 'Only the beacon creator can cancel it']);
    exit;
}

// Cancel the beacon
$stmt = $conn->prepare("UPDATE beacons SET status = 'cancelled' WHERE id = ?");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$stmt->close();

$conn->close();

echo json_encode(['status' => 'success']);
