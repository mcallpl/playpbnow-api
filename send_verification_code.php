<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';

if (empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Phone number required']);
    exit;
}

// Clean and format phone number
$clean_phone = cleanPhoneNumber($phone);

// Generate 6-digit code
$code = generateVerificationCode();

// Set expiry time - CALCULATE IT FIRST
$expires_at = date('Y-m-d H:i:s', strtotime('+' . CODE_EXPIRY_MINUTES . ' minutes'));

// THEN debug log it
error_log("🕐 Expires at: " . $expires_at);
error_log("🕐 Current time: " . date('Y-m-d H:i:s'));
error_log("🕐 CODE_EXPIRY_MINUTES: " . CODE_EXPIRY_MINUTES);

try {
    // Delete old unused codes for this phone
    dbQuery("DELETE FROM verification_codes WHERE phone = ? AND is_used = FALSE", [$clean_phone]);
    
    // Insert new code
    dbInsert(
        "INSERT INTO verification_codes (phone, code, expires_at) VALUES (?, ?, ?)",
        [$clean_phone, $code, $expires_at]
    );
    
    // Send SMS
    $sent = sendVerificationCode($clean_phone, $code);
    
    if ($sent) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Verification code sent',
            'phone' => $clean_phone,
            'expires_in_minutes' => CODE_EXPIRY_MINUTES
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to send SMS. Please try again.'
        ]);
    }
} catch (Exception $e) {
    error_log("Error sending code: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'System error. Please try again.'
    ]);
}
?>