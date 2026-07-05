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
$response_type = $input['response_type'] ?? 'on_my_way';

if (!$beacon_id || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'beacon_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

// Verify beacon is active and casual
$stmt = $conn->prepare("SELECT id, beacon_type FROM beacons WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$beacon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$beacon) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Beacon not found or no longer active']);
    exit;
}

if ($beacon['beacon_type'] !== 'casual') {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'This beacon requires joining the lobby instead']);
    exit;
}

// Verify user has a verified profile (phone + name)
$stmt = $conn->prepare("SELECT first_name, last_name, phone FROM user_profiles WHERE user_id = ?");
$stmt->bind_param('s', $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile || !$profile['first_name'] || !$profile['phone']) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'You must verify your phone number and enter your name before responding']);
    exit;
}

$first_name = $profile['first_name'];
$last_name = $profile['last_name'] ?? '';

// Upsert response
$stmt = $conn->prepare(
    "INSERT INTO beacon_responses (beacon_id, user_id, first_name, last_name, response_type)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE response_type = VALUES(response_type), first_name = VALUES(first_name), last_name = VALUES(last_name)"
);
$stmt->bind_param('issss', $beacon_id, $user_id, $first_name, $last_name, $response_type);
$stmt->execute();
$stmt->close();

// Get updated response count
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM beacon_responses WHERE beacon_id = ?");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$conn->close();

echo json_encode(['status' => 'success', 'response_count' => $count]);
