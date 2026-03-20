<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'request_code') {
    // ── Step 1: Send a 6-digit reset code via SMS ──
    $phone_raw = trim($input['phone'] ?? '');

    if (empty($phone_raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter your phone number']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);

    $user = dbGetRow("SELECT id, first_name FROM users WHERE phone = ?", [$phone]);
    if (!$user) {
        // Don't reveal whether the phone exists
        echo json_encode(['status' => 'success', 'message' => 'If an account exists with this number, a reset code has been sent.']);
        exit;
    }

    // Generate 6-digit code
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // Delete any existing codes for this user
    dbQuery("DELETE FROM password_reset_codes WHERE user_id = ?", [$user['id']]);

    // Store the code
    dbInsert(
        "INSERT INTO password_reset_codes (user_id, code, expires_at) VALUES (?, ?, ?)",
        [$user['id'], $code, $expires_at]
    );

    // Send via Twilio SMS
    $sent = sendVerificationCode($phone, $code);

    if (!$sent) {
        error_log("forgot_password: Twilio SMS failed for user {$user['id']}");
        echo json_encode(['status' => 'error', 'message' => 'Failed to send reset code. Please try again.']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'A reset code has been sent to your phone.'
    ]);

} elseif ($action === 'verify_code') {
    // ── Step 2: Verify the code ──
    $phone_raw = trim($input['phone'] ?? '');
    $code = trim($input['code'] ?? '');

    if (empty($phone_raw) || empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone and code are required']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);
    $user = dbGetRow("SELECT id FROM users WHERE phone = ?", [$phone]);
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid code']);
        exit;
    }

    $reset = dbGetRow(
        "SELECT id FROM password_reset_codes WHERE user_id = ? AND code = ? AND expires_at > NOW()",
        [$user['id'], $code]
    );

    if (!$reset) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code']);
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => 'Code verified']);

} elseif ($action === 'reset_password') {
    // ── Step 3: Set the new password ──
    $phone_raw = trim($input['phone'] ?? '');
    $code = trim($input['code'] ?? '');
    $new_password = $input['new_password'] ?? '';

    if (empty($phone_raw) || empty($code) || empty($new_password)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
        exit;
    }

    if (strlen($new_password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);
    $user = dbGetRow("SELECT id FROM users WHERE phone = ?", [$phone]);
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        exit;
    }

    // Re-verify the code
    $reset = dbGetRow(
        "SELECT id FROM password_reset_codes WHERE user_id = ? AND code = ? AND expires_at > NOW()",
        [$user['id'], $code]
    );

    if (!$reset) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code. Please request a new one.']);
        exit;
    }

    // Update password
    $hash = password_hash($new_password, PASSWORD_DEFAULT);
    dbQuery("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $user['id']]);

    // Delete used code
    dbQuery("DELETE FROM password_reset_codes WHERE user_id = ?", [$user['id']]);

    echo json_encode(['status' => 'success', 'message' => 'Password has been reset. You can now sign in.']);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
