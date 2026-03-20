<?php
// ============================================================
// TEMPORARY: Apple Review bypass login (no SMS required)
// Remove this file after Apple approves the app
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';

if (empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Phone required']);
    exit;
}

$clean_phone = cleanPhoneNumber($phone);

// Only allow the app owner's phone number
$ALLOWED_PHONE = '+19497359415';
if ($clean_phone !== $ALLOWED_PHONE) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Look up the existing user
$user = dbGetRow("SELECT * FROM users WHERE phone = ?", [$clean_phone]);

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// Update last login
dbQuery("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);

// Create session token
$session_token = bin2hex(random_bytes(32));
$session_expires = date('Y-m-d H:i:s', strtotime('+30 days'));

dbInsert(
    "INSERT INTO user_sessions (user_id, session_token, device_info, expires_at)
     VALUES (?, ?, ?, ?)",
    [$user['id'], $session_token, 'apple-review', $session_expires]
);

echo json_encode([
    'status' => 'success',
    'message' => 'Review login successful',
    'user' => [
        'id' => $user['id'],
        'phone' => $user['phone'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name']
    ],
    'session_token' => $session_token,
    'expires_at' => $session_expires
]);
?>
