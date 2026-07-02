<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://peoplestar.com');

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';

if (empty($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Phone number required']);
    exit;
}

// Clean and format phone number
$clean_phone = cleanPhoneNumber($phone);

// ── Rate limit: max 5 codes per phone per hour ──────────────────────────
// This endpoint is unauthenticated and triggers a paid Twilio SMS, so without
// a limit anyone can run up the Twilio bill / spam a number. Fail-OPEN: any
// error here allows the send, so legitimate users are never blocked by an
// infra problem — only confirmed abuse (>=5 in the last hour) is stopped.
try {
    $rlDir = sys_get_temp_dir() . '/pbnow_rl';
    if (!is_dir($rlDir)) { @mkdir($rlDir, 0700, true); }
    $rlFile = $rlDir . '/vc_' . md5($clean_phone);
    $now = time();
    $hits = [];
    if (is_file($rlFile)) {
        $raw = @file_get_contents($rlFile);
        if ($raw !== false && $raw !== '') {
            foreach (explode(',', $raw) as $t) {
                $t = (int) $t;
                if ($t > $now - 3600) { $hits[] = $t; }
            }
        }
    }
    if (count($hits) >= 5) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many code requests. Please wait a bit and try again.']);
        exit;
    }
    $hits[] = $now;
    @file_put_contents($rlFile, implode(',', $hits), LOCK_EX);
} catch (Throwable $e) {
    // fail open — never block a legitimate user due to a rate-limiter error
}

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