<?php
// ============================================================
// Phone/Password Authentication
// Handles both login and registration
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$mode = $input['mode'] ?? '';           // 'login' or 'register'
$phone_raw = trim($input['phone'] ?? '');
$password = $input['password'] ?? '';
$first_name = trim($input['first_name'] ?? '');
$last_name = trim($input['last_name'] ?? '');
$email_raw = trim($input['email'] ?? '');
$device_info = $input['device_info'] ?? '';

// Validate password is always required
if (empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Password is required']);
    exit;
}

// Normalize phone if provided
$phone = null;
if (!empty($phone_raw)) {
    $phone = cleanPhoneNumber($phone_raw);
    if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid phone number']);
        exit;
    }
}

// Normalize email if provided
$email = !empty($email_raw) ? strtolower($email_raw) : null;

if ($mode === 'register') {
    // ============ REGISTER ============

    // Email is required for registration
    if (empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
        exit;
    }

    if (empty($first_name)) {
        echo json_encode(['status' => 'error', 'message' => 'First name is required']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters']);
        exit;
    }

    // Check if email already exists
    $existing = dbGetRow("SELECT id FROM users WHERE email = ?", [$email]);
    if ($existing) {
        echo json_encode(['status' => 'error', 'message' => 'An account with this email already exists. Please sign in.']);
        exit;
    }

    // Also check phone uniqueness if provided
    if ($phone) {
        $existing_phone = dbGetRow("SELECT id FROM users WHERE phone = ?", [$phone]);
        if ($existing_phone) {
            echo json_encode(['status' => 'error', 'message' => 'An account with this phone number already exists. Please sign in.']);
            exit;
        }
    }

    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Create new user with 30-day PRO trial
    $trial_end = date('Y-m-d H:i:s', strtotime('+30 days'));
    $now_str = date('Y-m-d H:i:s');

    if ($phone) {
        $user_id = dbInsert(
            "INSERT INTO users (phone, email, password_hash, first_name, last_name, is_active, last_login_at, subscription_status, subscription_tier, trial_start_date, subscription_end_date) VALUES (?, ?, ?, ?, ?, TRUE, NOW(), 'trial', 'pro', ?, ?)",
            [$phone, $email, $password_hash, $first_name, $last_name, $now_str, $trial_end]
        );
    } else {
        $user_id = dbInsert(
            "INSERT INTO users (email, password_hash, first_name, last_name, is_active, last_login_at, subscription_status, subscription_tier, trial_start_date, subscription_end_date) VALUES (?, ?, ?, ?, TRUE, NOW(), 'trial', 'pro', ?, ?)",
            [$email, $password_hash, $first_name, $last_name, $now_str, $trial_end]
        );
    }

    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to create account. Please try again.']);
        exit;
    }

    // Create feature_access row with full Pro access for trial
    try {
        dbQuery(
            "INSERT INTO feature_access (user_id, can_create_matches, can_edit_matches, can_delete_matches, can_generate_reports, can_create_groups, max_groups, max_collab_sessions, max_players_per_group) VALUES (?, 1, 1, 1, 1, 1, 999, 999, 999)",
            [$user_id]
        );
    } catch (Exception $e) {
        error_log("email_login: could not create feature_access: " . $e->getMessage());
    }

    $user = dbGetRow("SELECT * FROM users WHERE id = ?", [$user_id]);

    // Create session token
    $session_token = bin2hex(random_bytes(32));
    $session_expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    dbInsert(
        "INSERT INTO user_sessions (user_id, session_token, device_info, expires_at) VALUES (?, ?, ?, ?)",
        [$user['id'], $session_token, $device_info, $session_expires]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Account created successfully',
        'user' => [
            'id' => $user['id'],
            'phone' => $user['phone'],
            'email' => $user['email'] ?? '',
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name']
        ],
        'session_token' => $session_token,
        'expires_at' => $session_expires
    ]);

} elseif ($mode === 'login') {
    // ============ LOGIN ============

    // Look up user by email or phone
    $user = null;
    if ($email) {
        $user = dbGetRow("SELECT * FROM users WHERE email = ?", [$email]);
    }
    if (!$user && $phone) {
        $user = dbGetRow("SELECT * FROM users WHERE phone = ?", [$phone]);
    }
    if (!$user && !$email && !$phone) {
        echo json_encode(['status' => 'error', 'message' => 'Email or phone number is required']);
        exit;
    }

    $generic_error = 'The email/phone or password you entered is incorrect. Please try again.';

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => $generic_error]);
        exit;
    }

    if (empty($user['password_hash'])) {
        echo json_encode(['status' => 'error', 'message' => $generic_error]);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['status' => 'error', 'message' => $generic_error]);
        exit;
    }

    // Update last login
    dbQuery("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);

    // Create session token
    $session_token = bin2hex(random_bytes(32));
    $session_expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    dbInsert(
        "INSERT INTO user_sessions (user_id, session_token, device_info, expires_at) VALUES (?, ?, ?, ?)",
        [$user['id'], $session_token, $device_info, $session_expires]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'phone' => $user['phone'],
            'email' => $user['email'] ?? '',
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name']
        ],
        'session_token' => $session_token,
        'expires_at' => $session_expires
    ]);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid mode. Use "login" or "register".']);
}
?>
