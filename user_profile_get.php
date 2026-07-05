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

$user_id = $_GET['user_id'] ?? null;

if ($user_id === null || $user_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
    exit;
}

$conn = getDBConnection();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT user_id, phone, first_name, last_name, gender, created_at
     FROM user_profiles WHERE user_id = ?"
);
$stmt->bind_param('s', $user_id);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to query profile']);
    exit;
}

$result = $stmt->get_result();

if ($result === false) {
    $stmt->close();
    $conn->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve profile result']);
    exit;
}

$profile = $result->fetch_assoc();
$stmt->close();

$conn->close();

if ($profile) {
    echo json_encode(['status' => 'success', 'profile' => $profile]);
} else {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Profile not found']);
}
