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

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

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

    // Check if phone already registered
    $existing = dbGetRow("SELECT id, first_name FROM pool_players WHERE phone = ?", [$phone]);
    if ($existing) {
        echo json_encode(['status' => 'error', 'message' => 'This phone number is already registered. Welcome back, ' . $existing['first_name'] . '! Use "Update My Info" below to make changes.']);
        exit;
    }

    // Generate and send verification code
    $code = generateVerificationCode();
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

    $reg = dbGetRow(
        "SELECT * FROM player_verification_codes WHERE phone = ? AND code = ? AND expires_at > NOW()",
        [$phone, $code]
    );

    if (!$reg) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code. Please try again.']);
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

    $code = generateVerificationCode();
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    dbQuery("UPDATE player_verification_codes SET code = ?, expires_at = ? WHERE phone = ?", [$code, $expires, $phone]);

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

    $existing = dbGetRow("SELECT id, first_name FROM pool_players WHERE phone = ?", [$phone]);
    if (!$existing) {
        echo json_encode(['status' => 'error', 'message' => 'No player found with this phone number. Please register first.']);
        exit;
    }

    $code = generateVerificationCode();
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

    $reg = dbGetRow(
        "SELECT * FROM player_verification_codes WHERE phone = ? AND code = ? AND expires_at > NOW()",
        [$phone, $code]
    );

    if (!$reg) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired code. Please try again.']);
        exit;
    }

    // Get current player data
    $player = dbGetRow("SELECT * FROM pool_players WHERE phone = ?", [$phone]);
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

    // Verify this player_id matches the phone (security check)
    $player = dbGetRow("SELECT id FROM pool_players WHERE id = ? AND phone = ?", [$player_id, $phone]);
    if (!$player) {
        echo json_encode(['status' => 'error', 'message' => 'Player verification failed.']);
        exit;
    }

    $first_name = trim($input['first_name'] ?? '');
    $last_name = trim($input['last_name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
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
