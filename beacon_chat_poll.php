<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';

$beacon_id = $_GET['beacon_id'] ?? null;
$after_id = $_GET['after_id'] ?? null;

if (!$beacon_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'beacon_id is required']);
    exit;
}

$conn = getDBConnection();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

if ($after_id) {
    // Incremental: only messages after the given ID
    $stmt = $conn->prepare(
        "SELECT id, beacon_id, user_id, user_name, message, created_at
         FROM beacon_messages
         WHERE beacon_id = ? AND id > ?
         ORDER BY id ASC"
    );
    $stmt->bind_param('ii', $beacon_id, $after_id);
} else {
    // Initial load: last 50 messages
    $stmt = $conn->prepare(
        "SELECT id, beacon_id, user_id, user_name, message, created_at
         FROM beacon_messages
         WHERE beacon_id = ?
         ORDER BY id DESC
         LIMIT 50"
    );
    $stmt->bind_param('i', $beacon_id);
}

$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}
$stmt->close();

// If initial load (no after_id), reverse so oldest first
if (!$after_id) {
    $messages = array_reverse($messages);
}

$conn->close();

echo json_encode(['status' => 'success', 'messages' => $messages]);
