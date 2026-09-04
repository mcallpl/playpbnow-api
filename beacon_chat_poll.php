<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';
require_once 'beacon_common.php'; // H1 session adoption + H2 ISO dates

$beacon_id = $_GET['beacon_id'] ?? null;
$after_id = $_GET['after_id'] ?? null;

// H1: session identity (tolerant — polling stays readable without a token,
// but a token-bearing caller is identified for is_mine below).
$viewer_user_id = beacon_adopt_session_user($_GET['user_id'] ?? null);
$viewer_user_id = $viewer_user_id !== null ? (string)$viewer_user_id : null;

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
    // H2: ISO copy of created_at alongside the original; M3: is_mine computed
    // server-side so the client never compares int to string.
    $row['created_at_iso'] = beacon_iso($row['created_at'] ?? null);
    $row['is_mine'] = ($viewer_user_id !== null && (string)$row['user_id'] === $viewer_user_id);
    $messages[] = $row;
}
$stmt->close();

// If initial load (no after_id), reverse so oldest first
if (!$after_id) {
    $messages = array_reverse($messages);
}

$conn->close();

echo json_encode(['status' => 'success', 'messages' => $messages]);
