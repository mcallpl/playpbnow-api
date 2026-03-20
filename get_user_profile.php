<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? '';

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

$user = dbGetRow("SELECT id, email, first_name, last_name, phone, dupr_rating FROM users WHERE id = ?", [$user_id]);

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'user' => [
        'id' => $user['id'],
        'email' => $user['email'] ?? '',
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'phone' => $user['phone'] ?? '',
        'dupr_rating' => $user['dupr_rating'] ?? '',
    ]
]);
