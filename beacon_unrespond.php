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

if (!$beacon_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'beacon_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM beacon_responses WHERE beacon_id = ? AND user_id = ?");
$stmt->bind_param('is', $beacon_id, $user_id);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to remove response']);
    exit;
}

if ($stmt->affected_rows === 0) {
    $stmt->close();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'No matching response found']);
    exit;
}

$stmt->close();
$conn->close();

echo json_encode(['status' => 'success']);
