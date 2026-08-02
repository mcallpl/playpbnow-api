<?php
/**
 * claim_verify.php — finish claiming a universal identity (Phase 3).
 *
 * Verifies the SMS one-time code, logs the person in (get-or-create a user by
 * phone, same as verify_code.php), and links the identity to that account:
 * player_identities.claimed_by_user_id. Append-only respect: an identity already
 * claimed by a DIFFERENT user is never re-assigned here (no hijack).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/identity.php';

$input       = json_decode(file_get_contents('php://input'), true) ?: [];
$phone       = trim($input['phone'] ?? '');
$otp         = trim($input['code'] ?? ($input['otp'] ?? ''));
$claim_code  = strtoupper(trim($input['claim_code'] ?? ($input['identity_code'] ?? '')));
$device_info = $input['device_info'] ?? 'claim';

if ($phone === '' || $otp === '') {
    echo json_encode(['status' => 'error', 'message' => 'Phone and code required.']);
    exit;
}

$hash = pbnow_phone_hash($phone);
if ($hash === null) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number.']);
    exit;
}
$clean_phone = cleanPhoneNumber($phone);

// ── Verify the one-time code (same window logic as verify_code.php) ──────
$verification = dbGetRow(
    "SELECT id FROM verification_codes
     WHERE phone = ? AND code = ? AND is_used = FALSE
     AND expires_at > DATE_SUB(NOW(), INTERVAL 8 HOUR)
     ORDER BY created_at DESC LIMIT 1",
    [$clean_phone, $otp]
);
if (!$verification) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code.']);
    exit;
}

// ── Resolve the identity being claimed ──────────────────────────────────
if ($claim_code !== '') {
    $identity = dbGetRow("SELECT id, phone_hash, claimed_by_user_id, display_name FROM player_identities WHERE claim_code = ?", [$claim_code]);
    if (!$identity || !hash_equals($identity['phone_hash'], $hash)) {
        echo json_encode(['status' => 'error', 'message' => 'This code and number don\'t match.']);
        exit;
    }
} else {
    $identity = dbGetRow("SELECT id, phone_hash, claimed_by_user_id, display_name FROM player_identities WHERE phone_hash = ?", [$hash]);
    if (!$identity) {
        echo json_encode(['status' => 'error', 'message' => 'No universal profile for that number.']);
        exit;
    }
}

// Never re-assign an identity already owned by someone else.
$already = $identity['claimed_by_user_id'] ? (int)$identity['claimed_by_user_id'] : null;

// Code is valid — burn it.
dbQuery("UPDATE verification_codes SET is_used = TRUE WHERE id = ?", [$verification['id']]);

// ── Get-or-create the user by phone; issue a session (mirrors verify_code.php)
$user = dbGetRow("SELECT * FROM users WHERE phone = ?", [$clean_phone]);
if (!$user) {
    $trial_end = date('Y-m-d H:i:s', strtotime('+30 days'));
    $now_str   = date('Y-m-d H:i:s');
    $device_id = 'claim_' . bin2hex(random_bytes(8)); // users.device_id is NOT NULL
    $user_id = dbInsert(
        "INSERT INTO users (device_id, phone, is_active, last_login_at, subscription_status, subscription_tier, trial_start_date, subscription_end_date)
        // Trial clock starts on FIRST MEANINGFUL USE (first saved session),
        // not at registration — see trial.php. NULLs are what mark it unstarted;
        // every expiry check is guarded on subscription_end_date.
         VALUES (?, ?, TRUE, NOW(), 'trial', 'premium', NULL, NULL)",
        [$device_id, $clean_phone]
    );
    try {
        dbQuery(
            "INSERT INTO feature_access (user_id, can_create_matches, can_edit_matches, can_delete_matches, can_generate_reports, can_create_groups, max_groups, max_collab_sessions, max_players_per_group)
             VALUES (?, 1, 1, 1, 1, 1, 999, 999, 999)",
            [$user_id]
        );
    } catch (Exception $e) { /* feature_access optional */ }
    $user = dbGetRow("SELECT * FROM users WHERE id = ?", [$user_id]);
} else {
    dbQuery("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);
}
$uid = (int)$user['id'];

// If already claimed by someone else, refuse to move it — but still log the
// person in (the phone is theirs) so they aren't stranded.
if ($already !== null && $already !== $uid) {
    error_log("claim_verify: identity {$identity['id']} already claimed by {$already}; login {$uid} denied claim.");
} else {
    // Link the identity to this account (idempotent if already ours).
    dbQuery("UPDATE player_identities SET claimed_by_user_id = ?, updated_at = NOW() WHERE id = ?", [$uid, (int)$identity['id']]);
    $already = $uid;
}

// Issue a session token.
$session_token   = bin2hex(random_bytes(32));
$session_expires = date('Y-m-d H:i:s', strtotime('+30 days'));
dbInsert(
    "INSERT INTO user_sessions (user_id, session_token, device_info, expires_at) VALUES (?, ?, ?, ?)",
    [$uid, $session_token, $device_info, $session_expires]
);

echo json_encode([
    'status'         => 'success',
    'claimed'        => ($already === $uid),
    'claim_conflict' => ($already !== $uid), // owned by another account
    'identity_id'    => (int)$identity['id'],
    'display_name'   => $identity['display_name'] ?? null,
    'user'           => [
        'id'         => $uid,
        'phone'      => $user['phone'] ?? $clean_phone,
        'first_name' => $user['first_name'] ?? null,
        'last_name'  => $user['last_name'] ?? null,
    ],
    'session_token'  => $session_token,
    'expires_at'     => $session_expires,
]);
