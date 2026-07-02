<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://peoplestar.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$input = json_decode(file_get_contents('php://input'), true);

$invite_id = $input['invite_id'] ?? null;
$user_id = $input['user_id'] ?? null;
$player_id = $input['player_id'] ?? null;
$message = trim($input['message'] ?? '');

if (!$invite_id || (!$user_id && !$player_id) || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'invite_id, user_id or player_id, and message are required']);
    exit;
}

if (mb_strlen($message, 'UTF-8') > 500) {
    echo json_encode(['status' => 'error', 'message' => 'Message must be 1-500 characters']);
    exit;
}

// Auth: chat is only used from within the app (which sends a session token),
// so require a valid login. Previously anyone could post to any invite's chat
// under any name by guessing the sequential invite_id. When posting as an app
// user, the token identity must match and must own the invite.
$auth_uid = pbnow_require_session_user();
if ($user_id) {
    if ((string) $auth_uid !== (string) $user_id) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authorized']);
        exit;
    }
    $owns = dbGetRow("SELECT id FROM match_invites WHERE id = ? AND user_id = ?", [$invite_id, $auth_uid]);
    if (!$owns) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authorized for this invite']);
        exit;
    }
}

// Determine sender name
$sender_name = 'Unknown';

if ($user_id) {
    // App user (organizer) — get name from user_profiles or users table
    $profile = dbGetRow("SELECT first_name, last_name FROM user_profiles WHERE user_id = ?", [$user_id]);
    if ($profile && $profile['first_name']) {
        $sender_name = trim($profile['first_name'] . ' ' . ($profile['last_name'] ?? ''));
    } else {
        $user = dbGetRow("SELECT first_name, last_name FROM users WHERE id = ?", [$user_id]);
        if ($user && $user['first_name']) {
            $sender_name = trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''));
        } else {
            $sender_name = 'Organizer';
        }
    }
}

if ($player_id) {
    // Pool player (invited player)
    $player = dbGetRow("SELECT first_name, last_name FROM pool_players WHERE id = ?", [$player_id]);
    if ($player) {
        $sender_name = trim($player['first_name'] . ' ' . ($player['last_name'] ?? ''));
    }
}

// Insert message
$msg_id = dbInsert(
    "INSERT INTO invite_messages (invite_id, user_id, player_id, sender_name, message, is_system) VALUES (?, ?, ?, ?, ?, 0)",
    [$invite_id, $user_id, $player_id, $sender_name, $message]
);

if (!$msg_id) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to send message']);
    exit;
}

$msg = dbGetRow("SELECT id, invite_id, user_id, player_id, sender_name, message, is_system, created_at FROM invite_messages WHERE id = ?", [$msg_id]);

echo json_encode(['status' => 'success', 'data' => $msg]);
