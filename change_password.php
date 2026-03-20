<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? '';
$current_password = $input['current_password'] ?? '';
$new_password = $input['new_password'] ?? '';

if (empty($user_id) || empty($current_password) || empty($new_password)) {
    echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
    exit;
}

if (strlen($new_password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'New password must be at least 6 characters']);
    exit;
}

$user = dbGetRow("SELECT id, password_hash FROM users WHERE id = ?", [$user_id]);

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

if (empty($user['password_hash'])) {
    echo json_encode(['status' => 'error', 'message' => 'This account does not use password authentication']);
    exit;
}

if (!password_verify($current_password, $user['password_hash'])) {
    echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect']);
    exit;
}

$new_hash = password_hash($new_password, PASSWORD_DEFAULT);
dbQuery("UPDATE users SET password_hash = ? WHERE id = ?", [$new_hash, $user_id]);

echo json_encode(['status' => 'success', 'message' => 'Password updated successfully']);
