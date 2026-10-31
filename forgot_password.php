<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
require_once __DIR__ . '/lib_mail.php';

// 2026-10-31: the app sends { email } when someone types their email instead
// of a phone number. This file only ever read 'phone', so every email reset
// failed with "Please enter your phone number". An email is now looked up
// directly; the code still goes to the phone on file (as before), or by
// email when the account has no phone. Phone callers are unchanged.
$reset_email = strtolower(trim((string)($input['email'] ?? '')));
$email_user = null;
if ($reset_email !== '' && trim((string)($input['phone'] ?? '')) === '') {
    $email_user = dbGetRow("SELECT id, first_name, email, phone FROM users WHERE LOWER(email) = ? AND _deleted_at IS NULL", [$reset_email]);
}

if ($action === 'request_code' && $reset_email !== '' && trim((string)($input['phone'] ?? '')) === '') {
    // ── Step 1 by EMAIL ──
    // Same answer whether or not the email exists, so this cannot be used to
    // find out who has an account.
    $generic = ['status' => 'success', 'message' => 'If that email is on an account, a reset code is on its way.'];
    if (!$email_user) { echo json_encode($generic); exit; }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    dbQuery("DELETE FROM password_reset_codes WHERE user_id = ?", [$email_user['id']]);
    dbQuery("INSERT INTO password_reset_codes (user_id, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))", [$email_user['id'], $code]);

    $phone_on_file = trim((string)($email_user['phone'] ?? ''));
    if ($phone_on_file !== '') {
        $sent = sendVerificationCode($phone_on_file, $code);
        if ($sent) {
            $last4 = substr(preg_replace('/\D/', '', $phone_on_file), -4);
            echo json_encode(['status' => 'success', 'message' => "We texted a reset code to your phone ending in {$last4}."]);
            exit;
        }
    }
    $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:480px;padding:20px">'
          . '<p style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:16px">Your PlayPBNow reset code is:</p>'
          . '<p style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:32px;font-weight:700;letter-spacing:4px">' . $code . '</p>'
          . '<p style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#555">It works for 15 minutes. If you did not ask for this, you can ignore this email.</p></div>';
    if (pbnow_smtp_send($email_user['email'], 'Your PlayPBNow reset code', "Your PlayPBNow reset code is $code. It works for 15 minutes.", $html)) {
        echo json_encode(['status' => 'success', 'message' => 'We emailed you a reset code.']);
        exit;
    }
    error_log("forgot_password: could not deliver a reset code for user {$email_user['id']} (no phone, email failed)");
    echo json_encode(['status' => 'error', 'message' => "We couldn't send a code to this account right now. Email mcallpl@gmail.com and we'll get you back in."]);
    exit;

} elseif ($action === 'request_code') {
    // ── Step 1: Send a 6-digit reset code via SMS ──
    $phone_raw = trim($input['phone'] ?? '');

    if (empty($phone_raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter your phone number']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);

    $user = dbGetRow("SELECT id, first_name FROM users WHERE phone = ?", [$phone]);
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'No account found with this phone number. Please create an account first.']);
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

    if ($reset_email !== '' && !$email_user && !empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code']);
        exit;
    }
    if ((empty($phone_raw) && !$email_user) || empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone and code are required']);
        exit;
    }

    if ($email_user) {
        $user = ['id' => $email_user['id']];
    } else {
        $phone = cleanPhoneNumber($phone_raw);
        $user = dbGetRow("SELECT id FROM users WHERE phone = ?", [$phone]);
    }
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

    if ((empty($phone_raw) && !$email_user) || empty($code) || empty($new_password)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
        exit;
    }

    if (strlen($new_password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters']);
        exit;
    }

    if ($email_user) {
        $user = ['id' => $email_user['id']];
    } else {
        $phone = cleanPhoneNumber($phone_raw);
        $user = dbGetRow("SELECT id FROM users WHERE phone = ?", [$phone]);
    }
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
