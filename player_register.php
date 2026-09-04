<?php
// ============================================================
// Player Registration (Free talent pool)
// Handles: register, verify_code, resend_code
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/sms_throttle.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

// ── Shared guards (audit H3 / M8) ──────────────────────────────────────────
// This endpoint is unauthenticated and three of its actions cost a Twilio
// send, so: (1) a per-phone AND per-IP throttle on every code-sending action,
// (2) codes from a CSPRNG instead of rand(), (3) a cap on wrong-code guesses
// (needs the `attempts` column from migrations/2026_09_04_pool_verification_
// attempts.sql — detected at runtime, silently skipped when absent), and
// (4) phone lookups that tolerate the legacy formats still in pool_players.

/** 5 codes per phone per hour, 15 per IP per hour. 429 + exit when exceeded. */
function pbnow_code_send_guard(string $cleanPhone): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ip = trim(explode(',', (string) $ip)[0]);
    if (!sms_throttle('preg_phone_' . $cleanPhone, 5, 3600) || !sms_throttle('preg_ip_' . $ip, 15, 3600)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many code requests. Please wait a bit and try again.']);
        exit;
    }
}

/** Does player_verification_codes have the `attempts` column? (one probe per request) */
function pbnow_pvc_has_attempts(): bool {
    static $has = null;
    if ($has === null) {
        $has = (bool) dbGetRow("SHOW COLUMNS FROM player_verification_codes LIKE 'attempts'");
    }
    return $has;
}

define('PBNOW_MAX_CODE_ATTEMPTS', 5);

/**
 * Look up the pending code for a phone and enforce the guess cap. Returns the
 * matching row, or echoes an error and exits. Wrong guesses bump `attempts`
 * when the column exists; the 6th guess is refused until a fresh code is sent.
 */
function pbnow_check_code(string $cleanPhone, string $code): array {
    $hasAttempts = pbnow_pvc_has_attempts();
    if ($hasAttempts) {
        $pending = dbGetRow("SELECT attempts FROM player_verification_codes WHERE phone = ? LIMIT 1", [$cleanPhone]);
        if ($pending && (int)$pending['attempts'] >= PBNOW_MAX_CODE_ATTEMPTS) {
            echo json_encode(['status' => 'error', 'message' => 'Too many incorrect attempts. Please request a new code.']);
            exit;
        }
    }
    $reg = dbGetRow(
        "SELECT * FROM player_verification_codes WHERE phone = ? AND code = ? AND expires_at > NOW()",
        [$cleanPhone, $code]
    );
    if (!$reg) {
        if ($hasAttempts) {
            dbQuery("UPDATE player_verification_codes SET attempts = attempts + 1 WHERE phone = ?", [$cleanPhone]);
        }
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code. Please try again.']);
        exit;
    }
    return $reg;
}

/** Find a pool player by phone, matching legacy stored formats on the last 10 digits. */
function pbnow_find_pool_player(string $cleanPhone, string $columns = 'id, first_name'): ?array {
    [$sql, $params] = pbnow_phone_match_sql('phone', $cleanPhone);
    $row = dbGetRow("SELECT $columns FROM pool_players WHERE $sql ORDER BY id ASC LIMIT 1", $params);
    return $row ?: null;
}

if ($action === 'register') {
    // ── Step 1: Collect info and send verification code ─────
    $first_name = trim($input['first_name'] ?? '');
    $last_name = trim($input['last_name'] ?? '');
    $phone_raw = trim($input['phone'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $gender = $input['gender'] ?? null;
    $play_level = $input['play_level'] ?? null;
    $days_to_play = $input['days_to_play'] ?? 'Anyday';
    $times_to_play = $input['times_to_play'] ?? 'Anytime';
    $cities_to_play = $input['cities_to_play'] ?? 'Anywhere';
    $short_notice = $input['short_notice'] ?? 'Yes';
    $tournament_interest = $input['tournament_interest'] ?? 'Yes';
    $max_travel_minutes = intval($input['max_travel_minutes'] ?? 30);

    // Validate required fields
    if (empty($first_name)) {
        echo json_encode(['status' => 'error', 'message' => 'First name is required']);
        exit;
    }
    if (empty($phone_raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);
    if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid phone number']);
        exit;
    }
    // cleanPhoneNumber() accepts any digit string; Twilio only delivers to a
    // real North American mobile-shaped number here (audit M8).
    if (!pbnow_valid_nanp($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid 10-digit US or Canadian mobile number']);
        exit;
    }

    // Cap lengths of free-text fields
    $first_name = mb_substr($first_name, 0, 60, 'UTF-8');
    $last_name  = mb_substr($last_name, 0, 60, 'UTF-8');
    $email      = mb_substr($email, 0, 190, 'UTF-8');

    // Check if phone already registered (legacy formats matched on last 10 digits)
    $existing = pbnow_find_pool_player($phone);
    if ($existing) {
        echo json_encode(['status' => 'error', 'message' => 'This phone number is already registered. Welcome back, ' . $existing['first_name'] . '! Use "Update My Info" below to make changes.']);
        exit;
    }

    // Throttle BEFORE the paid send
    pbnow_code_send_guard($phone);

    // Generate and send verification code (CSPRNG)
    $code = pbnow_secure_code(CODE_LENGTH);
    $sent = sendVerificationCode($phone, $code);

    if (!$sent) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send verification code. Please try again.']);
        exit;
    }

    // Store code temporarily (expires in 10 minutes)
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Delete any existing verification codes for this phone
    dbQuery("DELETE FROM player_verification_codes WHERE phone = ?", [$phone]);
    dbInsert(
        "INSERT INTO player_verification_codes (phone, code, first_name, last_name, email, gender, play_level, days_to_play, times_to_play, cities_to_play, short_notice, tournament_interest, max_travel_minutes, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$phone, $code, $first_name, $last_name, $email, $gender, $play_level, $days_to_play, $times_to_play, $cities_to_play, $short_notice, $tournament_interest, $max_travel_minutes, $expires]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Verification code sent to your phone.',
        'phone_last4' => substr($phone, -4)
    ]);

} elseif ($action === 'verify') {
    // ── Step 2: Verify code and create player ───────────────
    $phone_raw = trim($input['phone'] ?? '');
    $code = trim($input['code'] ?? '');

    if (empty($phone_raw) || empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone and code are required']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);

    // Attempt-capped lookup (audit H3); echoes the error + exits on a miss.
    $reg = pbnow_check_code($phone, $code);

    // Race guard: two verifies in flight (double tap) must not create two
    // pool rows for the same phone.
    $dupe = pbnow_find_pool_player($phone);
    if ($dupe) {
        dbQuery("DELETE FROM player_verification_codes WHERE phone = ?", [$phone]);
        echo json_encode([
            'status' => 'success',
            'message' => "Welcome to PlayPBNow, {$reg['first_name']}! You're now in the player pool and can be invited to matches.",
            'player_id' => (int) $dupe['id']
        ]);
        exit;
    }

    // Create the player
    $gender_val = in_array($reg['gender'], ['Male', 'Female']) ? $reg['gender'] : null;
    $sn = in_array($reg['short_notice'] ?? 'Yes', ['Yes', 'No']) ? $reg['short_notice'] : 'Yes';
    $ti = in_array($reg['tournament_interest'] ?? 'Yes', ['Yes', 'No']) ? $reg['tournament_interest'] : 'Yes';
    $mt = intval($reg['max_travel_minutes'] ?? 30);

    if ($gender_val) {
        $player_id = dbInsert(
            "INSERT INTO pool_players (first_name, last_name, phone, email, gender, play_level, days_to_play, times_to_play, cities_to_play, short_notice, tournament_interest, max_travel_minutes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$reg['first_name'], $reg['last_name'], $phone, $reg['email'], $gender_val, $reg['play_level'], $reg['days_to_play'], $reg['times_to_play'], $reg['cities_to_play'], $sn, $ti, $mt]
        );
    } else {
        $player_id = dbInsert(
            "INSERT INTO pool_players (first_name, last_name, phone, email, play_level, days_to_play, times_to_play, cities_to_play, short_notice, tournament_interest, max_travel_minutes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$reg['first_name'], $reg['last_name'], $phone, $reg['email'], $reg['play_level'], $reg['days_to_play'], $reg['times_to_play'], $reg['cities_to_play'], $sn, $ti, $mt]
        );
    }

    if (!$player_id) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to create your profile. Please try again.']);
        exit;
    }

    // Clean up verification code
    dbQuery("DELETE FROM player_verification_codes WHERE phone = ?", [$phone]);

    echo json_encode([
        'status' => 'success',
        'message' => "Welcome to PlayPBNow, {$reg['first_name']}! You're now in the player pool and can be invited to matches.",
        'player_id' => $player_id
    ]);

} elseif ($action === 'resend') {
    // ── Resend verification code ────────────────────────────
    $phone_raw = trim($input['phone'] ?? '');
    if (empty($phone_raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);

    $existing = dbGetRow("SELECT id FROM player_verification_codes WHERE phone = ?", [$phone]);
    if (!$existing) {
        echo json_encode(['status' => 'error', 'message' => 'No pending registration found. Please start over.']);
        exit;
    }

    // Throttle BEFORE the paid send (audit H3)
    pbnow_code_send_guard($phone);

    $code = pbnow_secure_code(CODE_LENGTH);
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    dbQuery("UPDATE player_verification_codes SET code = ?, expires_at = ? WHERE phone = ?", [$code, $expires, $phone]);
    // A fresh code gets a fresh guess budget.
    if (pbnow_pvc_has_attempts()) {
        dbQuery("UPDATE player_verification_codes SET attempts = 0 WHERE phone = ?", [$phone]);
    }

    $sent = sendVerificationCode($phone, $code);
    if (!$sent) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send code. Please try again.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => 'New code sent.']);

} elseif ($action === 'request_update') {
    // ── Send verification code to existing player ────────
    $phone_raw = trim($input['phone'] ?? '');
    if (empty($phone_raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);
    if (!pbnow_valid_nanp($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid 10-digit US or Canadian mobile number']);
        exit;
    }

    // Legacy formats matched on last 10 digits (audit M8)
    $existing = pbnow_find_pool_player($phone);
    if (!$existing) {
        echo json_encode(['status' => 'error', 'message' => 'No player found with this phone number. Please register first.']);
        exit;
    }

    // Throttle BEFORE the paid send (audit H3)
    pbnow_code_send_guard($phone);

    $code = pbnow_secure_code(CODE_LENGTH);
    $sent = sendVerificationCode($phone, $code);

    if (!$sent) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to send verification code. Please try again.']);
        exit;
    }

    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    dbQuery("DELETE FROM player_verification_codes WHERE phone = ?", [$phone]);
    dbInsert(
        "INSERT INTO player_verification_codes (phone, code, first_name, expires_at) VALUES (?, ?, ?, ?)",
        [$phone, $code, $existing['first_name'], $expires]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Verification code sent to your phone.',
        'phone_last4' => substr($phone, -4),
        'first_name' => $existing['first_name']
    ]);

} elseif ($action === 'verify_update') {
    // ── Verify code and return current player data ───────
    $phone_raw = trim($input['phone'] ?? '');
    $code = trim($input['code'] ?? '');

    if (empty($phone_raw) || empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone and code are required']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);

    // Attempt-capped lookup (audit H3); echoes the error + exits on a miss.
    $reg = pbnow_check_code($phone, $code);

    // Get current player data (legacy phone formats tolerated)
    $player = pbnow_find_pool_player($phone, '*');
    if (!$player) {
        echo json_encode(['status' => 'error', 'message' => 'Player not found.']);
        exit;
    }

    // Clean up verification code
    dbQuery("DELETE FROM player_verification_codes WHERE phone = ?", [$phone]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Verified! You can now update your information.',
        'player' => [
            'id' => $player['id'],
            'first_name' => $player['first_name'],
            'last_name' => $player['last_name'] ?? '',
            'email' => $player['email'] ?? '',
            'gender' => $player['gender'] ?? '',
            'play_level' => $player['play_level'] ?? '',
            'days_to_play' => $player['days_to_play'] ?? 'Anyday',
            'times_to_play' => $player['times_to_play'] ?? 'Anytime',
            'cities_to_play' => $player['cities_to_play'] ?? 'Anywhere',
            'short_notice' => $player['short_notice'] ?? 'Yes',
            'tournament_interest' => $player['tournament_interest'] ?? 'Yes',
            'max_travel_minutes' => $player['max_travel_minutes'] ?? 30,
        ]
    ]);

} elseif ($action === 'save_update') {
    // ── Save updated player info ─────────────────────────
    $player_id = intval($input['player_id'] ?? 0);
    $phone_raw = trim($input['phone'] ?? '');

    if (!$player_id || empty($phone_raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing player ID or phone']);
        exit;
    }

    $phone = cleanPhoneNumber($phone_raw);

    // Verify this player_id matches the phone (security check). Legacy stored
    // formats are matched on the last 10 digits, same as the lookup that
    // issued the code — otherwise a "(714) 555-1212" row could verify but
    // never save.
    [$phoneSql, $phoneParams] = pbnow_phone_match_sql('phone', $phone);
    $player = dbGetRow("SELECT id FROM pool_players WHERE id = ? AND $phoneSql", array_merge([$player_id], $phoneParams));
    if (!$player) {
        echo json_encode(['status' => 'error', 'message' => 'Player verification failed.']);
        exit;
    }

    $first_name = mb_substr(trim($input['first_name'] ?? ''), 0, 60, 'UTF-8');
    $last_name = mb_substr(trim($input['last_name'] ?? ''), 0, 60, 'UTF-8');
    $email = mb_substr(strtolower(trim($input['email'] ?? '')), 0, 190, 'UTF-8');
    $gender = $input['gender'] ?? null;
    $play_level = $input['play_level'] ?? null;
    $days_to_play = $input['days_to_play'] ?? 'Anyday';
    $times_to_play = $input['times_to_play'] ?? 'Anytime';
    $cities_to_play = $input['cities_to_play'] ?? 'Anywhere';
    $short_notice = $input['short_notice'] ?? 'Yes';
    $tournament_interest = $input['tournament_interest'] ?? 'Yes';
    $max_travel_minutes = intval($input['max_travel_minutes'] ?? 30);

    if (empty($first_name)) {
        echo json_encode(['status' => 'error', 'message' => 'First name is required']);
        exit;
    }

    dbQuery(
        "UPDATE pool_players SET first_name = ?, last_name = ?, email = ?, gender = ?, play_level = ?, days_to_play = ?, times_to_play = ?, cities_to_play = ?, short_notice = ?, tournament_interest = ?, max_travel_minutes = ? WHERE id = ?",
        [$first_name, $last_name, $email, $gender, $play_level, $days_to_play, $times_to_play, $cities_to_play, $short_notice, $tournament_interest, $max_travel_minutes, $player_id]
    );

    echo json_encode([
        'status' => 'success',
        'message' => "Your profile has been updated, {$first_name}!"
    ]);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
