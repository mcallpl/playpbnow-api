<?php
header('Content-Type: application/json');
// Wildcard like every other app endpoint: the app is served from
// playpbnow.com (same-origin) and identity comes from the Bearer token, not
// from cookies, so origin pinning bought nothing and broke the web build's
// chat (audit C1).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// Chat is app-only (the public invite page has no chat), so require a valid
// login. Previously anyone could read any invite's full chat history by
// guessing the sequential invite_id.
$auth_uid = pbnow_require_session_user();

$invite_id = $_GET['invite_id'] ?? null;
$after_id = $_GET['after_id'] ?? null;
$player_id = $_GET['player_id'] ?? null;

if (!$invite_id) {
    echo json_encode(['status' => 'error', 'message' => 'invite_id is required']);
    exit;
}

// ── Ownership (audit H7) ──
// A valid login is not the same as a right to THIS invite's chat. Organizers
// see their own invites (admins see all); a caller reading on behalf of an
// invited player must name a player who actually has a response row here.
$invite_id = (int) $invite_id;
$owns = dbGetRow("SELECT id FROM match_invites WHERE id = ? AND user_id = ?", [$invite_id, $auth_uid]);
if (!$owns && !pbnow_is_admin($auth_uid)) {
    $invitedRow = null;
    if ($player_id) {
        $invitedRow = dbGetRow(
            "SELECT id FROM invite_responses WHERE invite_id = ? AND player_id = ? LIMIT 1",
            [$invite_id, (int) $player_id]
        );
    }
    if (!$invitedRow) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Not authorized for this invite']);
        exit;
    }
}

if ($after_id) {
    // Incremental: only messages after the given ID
    $messages = dbGetAll(
        "SELECT id, invite_id, user_id, player_id, sender_name, message, is_system, created_at
         FROM invite_messages
         WHERE invite_id = ? AND id > ?
         ORDER BY id ASC",
        [$invite_id, (int) $after_id]
    );
} else {
    // Initial load: last 50 messages
    $messages = dbGetAll(
        "SELECT id, invite_id, user_id, player_id, sender_name, message, is_system, created_at
         FROM invite_messages
         WHERE invite_id = ?
         ORDER BY id DESC
         LIMIT 50",
        [$invite_id]
    );
    // Reverse so oldest first
    $messages = array_reverse($messages);
}

echo json_encode(['status' => 'success', 'messages' => $messages]);
