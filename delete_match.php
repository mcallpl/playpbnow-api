<?php
// ============================================
// delete_match.php - Delete a single match
// Was previously an empty (0-byte) file, so every match deletion silently
// failed with a "Network error" on the client. Reimplemented modeled on the
// working delete_session.php, keying ownership off matches.device_id (which,
// per the app, stores the creating user's id).
//
// UAT 2026-09-04 (Audit C M7 / Audit E H6): matches.device_id is '' on every
// row save_scores.php writes, so the device_id check never fired and the body
// device_id could be spoofed. Now a logged-in session is REQUIRED and
// ownership comes from sessions.user_id (joined via matches.session_id), with
// the group owner as the fallback for legacy rows without a session. The
// affected players' cached stats are recalculated afterwards (Audit C M6).
// ============================================

// Served same-origin from playpbnow.com; the old peoplestar.com-only origin
// made browsers drop the response (Audit C H7). Auth is the Bearer token.
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// WRITE/DELETE endpoint: the session user is authoritative; body device_id is
// no longer trusted for identity.
$auth_uid = pbnow_require_session_user();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$match_id  = $input['id'] ?? $input['match_id'] ?? '';
$device_id = $input['device_id'] ?? '';   // legacy: holds the requesting user's id
$group_key = $input['group'] ?? '';

if (!$match_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing match id']);
    exit;
}

// Look up the match and its recorded creator.
$match = dbGetRow(
    "SELECT id, device_id, group_id, session_id, p1_key, p2_key, p3_key, p4_key FROM matches WHERE id = ?",
    [(int)$match_id]
);

if (!$match) {
    echo json_encode(['status' => 'error', 'message' => 'Match not found']);
    exit;
}

// Ownership (legacy check, kept): if the match records a creator, the
// requester must match it. Compared against the SESSION user now, not the
// spoofable body device_id.
if (!empty($match['device_id'])) {
    if ((string) $match['device_id'] !== (string) $auth_uid) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You can only delete matches you created']);
        exit;
    }
}

// Ownership (primary): sessions.user_id via matches.session_id.
$ownerVerified = false;
$session_id = $match['session_id'] ?? '';
if (!empty($session_id) && ctype_digit((string)$session_id)) {
    $sess = dbGetRow("SELECT id, user_id FROM sessions WHERE id = ?", [(int)$session_id]);
    if ($sess) {
        if (!empty($sess['user_id']) && (string)$sess['user_id'] !== (string)$auth_uid) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'You can only delete matches you created']);
            exit;
        }
        if (!empty($sess['user_id'])) $ownerVerified = true;
    }
}

// Fallback for rows with no session owner: the caller must own the group.
if (!$ownerVerified && !empty($match['group_id'])) {
    $g = dbGetRow("SELECT id, owner_user_id FROM `groups` WHERE id = ?", [(int)$match['group_id']]);
    if ($g && !empty($g['owner_user_id'])) {
        if ((string)$g['owner_user_id'] !== (string)$auth_uid) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'You can only delete matches you created']);
            exit;
        }
        $ownerVerified = true;
    }
}

// Optional group sanity check when a group is supplied (kept from V1, now
// owner-scoped so a same-named group of another organizer cannot collide).
if (!empty($group_key) && !empty($match['group_id'])) {
    $group = dbGetRow(
        "SELECT id FROM `groups` WHERE (group_key = ? OR name = ?) AND owner_user_id = ? AND _deleted_at IS NULL ORDER BY id ASC LIMIT 1",
        [$group_key, $group_key, $auth_uid]
    );
    if ($group && (int) $group['id'] !== (int) $match['group_id']) {
        echo json_encode(['status' => 'error', 'message' => 'Group mismatch']);
        exit;
    }
}

$affectedKeys = function_exists('pbnow_match_rows_player_keys') ? pbnow_match_rows_player_keys([$match]) : [];

$conn = getDBConnection();
$stmt = $conn->prepare("DELETE FROM matches WHERE id = ?");
$mid = (int) $match_id;
$stmt->bind_param('i', $mid);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();
$conn->close();

// Keep players.wins/losses honest after the row is gone (Audit C M6).
$stats_updated = 0;
if ($deleted > 0 && !empty($affectedKeys) && function_exists('pbnow_recalc_player_stats')) {
    $stats_updated = pbnow_recalc_player_stats($affectedKeys);
}

if ($deleted > 0) {
    echo json_encode(['status' => 'success', 'deleted' => $deleted, 'stats_updated' => $stats_updated]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Match not found or already deleted']);
}
