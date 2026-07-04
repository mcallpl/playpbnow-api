<?php
/**
 * claim_start.php — begin claiming a universal identity (Phase 3).
 *
 * The person types their own phone number. We hash it and confirm it matches
 * the identity on file (optionally addressed by the QR claim_code), then text a
 * one-time code TO THE NUMBER THEY JUST TYPED. The raw number is used only
 * transiently to send — it is never written to the shared identity table.
 *
 * Privacy/abuse posture mirrors send_verification_code.php: 6-digit code in the
 * existing verification_codes table, file-based rate limit, fail-open limiter.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/identity.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$phone = trim($input['phone'] ?? '');
$code  = strtoupper(trim($input['code'] ?? '')); // optional QR claim_code

if ($phone === '') {
    echo json_encode(['status' => 'error', 'message' => 'Enter your mobile number to continue.']);
    exit;
}

$hash = pbnow_phone_hash($phone);
if ($hash === null) {
    echo json_encode(['status' => 'error', 'message' => 'That doesn\'t look like a valid mobile number.']);
    exit;
}

// Resolve the identity this claim is for.
if ($code !== '') {
    $identity = dbGetRow("SELECT id, phone_hash, claimed_by_user_id FROM player_identities WHERE claim_code = ?", [$code]);
    if (!$identity) {
        echo json_encode(['status' => 'error', 'message' => 'That claim code isn\'t valid. Double-check the code or QR.']);
        exit;
    }
    // The code names a profile; the phone must be THAT profile's phone.
    if (!hash_equals($identity['phone_hash'], $hash)) {
        echo json_encode(['status' => 'error', 'message' => 'That number doesn\'t match this profile. Enter the number your organizer has for you.']);
        exit;
    }
} else {
    $identity = dbGetRow("SELECT id, phone_hash, claimed_by_user_id FROM player_identities WHERE phone_hash = ?", [$hash]);
    if (!$identity) {
        echo json_encode([
            'status'  => 'not_found',
            'message' => 'We couldn\'t find a universal profile for that number yet. Ask your organizer to add you by phone.'
        ]);
        exit;
    }
}

if (!empty($identity['claimed_by_user_id'])) {
    echo json_encode([
        'status'  => 'already_claimed',
        'message' => 'This profile is already claimed. Just log in with this number to see your record.'
    ]);
    exit;
}

$clean_phone = cleanPhoneNumber($phone);

// ── Rate limit: max 5 codes per phone per hour (fail-open) ──────────────
try {
    $rlDir = sys_get_temp_dir() . '/pbnow_rl';
    if (!is_dir($rlDir)) { @mkdir($rlDir, 0700, true); }
    $rlFile = $rlDir . '/claim_' . md5($clean_phone);
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
} catch (Throwable $e) { /* fail open */ }

$otp        = generateVerificationCode();
$expires_at = date('Y-m-d H:i:s', strtotime('+' . CODE_EXPIRY_MINUTES . ' minutes'));

try {
    dbQuery("DELETE FROM verification_codes WHERE phone = ? AND is_used = FALSE", [$clean_phone]);
    dbInsert("INSERT INTO verification_codes (phone, code, expires_at) VALUES (?, ?, ?)", [$clean_phone, $otp, $expires_at]);

    // Dry-run guard: a `.sms_dryrun` marker file in this dir skips the live send
    // (used for testing so no real Twilio message goes out). Production has no
    // such file, so real users always get a real text.
    if (file_exists(__DIR__ . '/.sms_dryrun')) {
        error_log("🧪 CLAIM DRY-RUN: OTP {$otp} for {$clean_phone} (no SMS sent)");
        $sent = true;
    } else {
        $sent = sendVerificationCode($clean_phone, $otp);
    }

    if (!$sent) {
        echo json_encode(['status' => 'error', 'message' => 'Couldn\'t send the code right now. Please try again.']);
        exit;
    }

    // Mask the number in the response so the page can say where the code went
    // without echoing the full number back.
    $digits = preg_replace('/\D+/', '', $clean_phone);
    $masked = '•••-•••-' . substr($digits, -4);

    echo json_encode([
        'status'             => 'success',
        'message'            => 'We sent a 6-digit code to ' . $masked . '.',
        'masked_phone'       => $masked,
        'expires_in_minutes' => CODE_EXPIRY_MINUTES,
    ]);
} catch (Exception $e) {
    error_log("claim_start error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'System error. Please try again.']);
}
