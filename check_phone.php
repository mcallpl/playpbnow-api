<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$user_id = $input['user_id'] ?? '';

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

if ($action === 'check') {
    // Check if user has a phone number
    $user = dbGetRow("SELECT id, phone FROM users WHERE id = ?", [$user_id]);
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'has_phone' => !empty($user['phone'])
    ]);

} elseif ($action === 'set') {
    // Set/update phone number for user
    $phone_raw = trim($input['phone'] ?? '');

    if (empty($phone_raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);
    if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid phone number']);
        exit;
    }

    // Check if phone is already used by another user
    $existing = dbGetRow("SELECT id FROM users WHERE phone = ? AND id != ?", [$phone, $user_id]);
    if ($existing) {
        echo json_encode(['status' => 'error', 'message' => 'This phone number is already associated with another account']);
        exit;
    }

    dbQuery("UPDATE users SET phone = ? WHERE id = ?", [$phone, $user_id]);

    echo json_encode(['status' => 'success', 'message' => 'Phone number saved']);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action. Use "check" or "set".']);
}
