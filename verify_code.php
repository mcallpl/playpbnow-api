<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';
$code = $input['code'] ?? '';
$device_info = $input['device_info'] ?? '';

if (empty($phone) || empty($code)) {
    echo json_encode(['status' => 'error', 'message' => 'Phone and code required']);
    exit;
}

$clean_phone = cleanPhoneNumber($phone);

error_log("🔍 Verifying - Phone: {$clean_phone}, Code: {$code}");

// Check if code is valid
$verification = dbGetRow(
    "SELECT * FROM verification_codes 
     WHERE phone = ? AND code = ? AND is_used = FALSE 
     AND expires_at > DATE_SUB(NOW(), INTERVAL 8 HOUR)
     ORDER BY created_at DESC LIMIT 1",
    [$clean_phone, $code]
);

error_log("🔍 Found verification: " . json_encode($verification));

if (!$verification) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or expired code'
    ]);
    exit;
}

// Mark code as used
dbQuery("UPDATE verification_codes SET is_used = TRUE WHERE id = ?", [$verification['id']]);

// Get or create user
$user = dbGetRow("SELECT * FROM users WHERE phone = ?", [$clean_phone]);

if (!$user) {
    $user_id = dbInsert(
        "INSERT INTO users (phone, is_active, last_login_at) VALUES (?, TRUE, NOW())",
        [$clean_phone]
    );
    $user = dbGetRow("SELECT * FROM users WHERE id = ?", [$user_id]);
} else {
    dbQuery("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);
}

// Create session token
$session_token = bin2hex(random_bytes(32));
$session_expires = date('Y-m-d H:i:s', strtotime('+30 days'));

dbInsert(
    "INSERT INTO user_sessions (user_id, session_token, device_info, expires_at) 
     VALUES (?, ?, ?, ?)",
    [$user['id'], $session_token, $device_info, $session_expires]
);

echo json_encode([
    'status' => 'success',
    'message' => 'Login successful',
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