<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$beacon_id = $input['beacon_id'] ?? null;
$user_id = $input['user_id'] ?? null;
$message = $input['message'] ?? null;

if (!$beacon_id || !$user_id || !$message) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'beacon_id, user_id, and message are required']);
    exit;
}

$message = trim($message);
if (mb_strlen($message, 'UTF-8') === 0 || mb_strlen($message, 'UTF-8') > 500) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Message must be 1-500 characters']);
    exit;
}

$conn = getDBConnection();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Verify user has a completed profile (verified phone + name)
$stmt = $conn->prepare("SELECT first_name, last_name, phone FROM user_profiles WHERE user_id = ?");
$stmt->bind_param('s', $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile || !$profile['first_name'] || !$profile['phone']) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'You must verify your phone number and enter your name before chatting. Please log out and log back in with Twilio verification.']);
    exit;
}

// Use the verified name from the database — never trust the client
$user_name = trim($profile['first_name'] . ' ' . ($profile['last_name'] ?? ''));

// Verify beacon exists and is active
$stmt = $conn->prepare("SELECT id FROM beacons WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$beacon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$beacon) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Beacon not found or no longer active']);
    exit;
}

// Insert message with server-verified name
$stmt = $conn->prepare(
    "INSERT INTO beacon_messages (beacon_id, user_id, user_name, message) VALUES (?, ?, ?, ?)"
);
$stmt->bind_param('isss', $beacon_id, $user_id, $user_name, $message);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to send message']);
    exit;
}

$msg_id = $conn->insert_id;
$stmt->close();

// Fetch the inserted message
$stmt = $conn->prepare("SELECT id, beacon_id, user_id, user_name, message, created_at FROM beacon_messages WHERE id = ?");
$stmt->bind_param('i', $msg_id);
$stmt->execute();
$msg = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

if ($msg === null) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve sent message']);
    exit;
}

echo json_encode(['status' => 'success', 'data' => $msg]);
