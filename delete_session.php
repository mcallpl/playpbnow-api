<?php
// ============================================
// delete_session.php - DATABASE VERSION
// Deletes an entire session (all matches in batch)
//
// UAT 2026-09-04 (Audit C H4 / Audit E C3): the app sends
//   { group: <group NAME>, batch_id: <sessions.id> }
// but this file looked the group up by group_key only ("Group not found")
// and deleted matches WHERE batch_id = <sessions.id>, which never matched.
// It also had no identity check at all.
//
// Now:
//   - requires a logged-in user (Authorization: Bearer); the body user_id /
//     device_id are ignored when a session is present.
//   - group is resolved by group_key OR name, scoped to the caller.
//   - session is resolved by sessions.id OR sessions.batch_id, scoped to the
//     group and the caller.
//   - matches are deleted by session_id (and by batch_id for legacy rows
//     that predate sessions.id linkage), the session row is deleted, and
//     the affected players' cached stats are recalculated.
// ============================================

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// WRITE/DELETE endpoint: the session user is authoritative.
$auth_uid = pbnow_require_session_user();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$group_key = trim((string)($input['group'] ?? $input['group_key'] ?? $input['group_name'] ?? ''));
$batch_id  = trim((string)($input['batch_id'] ?? $input['session_id'] ?? ''));
$device_id = $input['device_id'] ?? '';   // legacy field, no longer trusted for identity

if (!$group_key || !$batch_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

// 1. FIND THE GROUP — by key OR name, owned by the caller.
$group = dbGetRow(
    "SELECT id FROM `groups`
     WHERE (group_key = ? OR name = ?) AND owner_user_id = ? AND _deleted_at IS NULL
     ORDER BY id ASC LIMIT 1",
    [$group_key, $group_key, $auth_uid]
);

// Legacy rows that predate owner_user_id: allow when the session itself is
// owned by the caller (checked in step 2).
if (!$group) {
    $group = dbGetRow(
        "SELECT id FROM `groups`
         WHERE (group_key = ? OR name = ?) AND (owner_user_id IS NULL OR owner_user_id = 0 OR owner_user_id = '')
         ORDER BY id ASC LIMIT 1",
        [$group_key, $group_key]
    );
}

if (!$group) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}

$group_id = (int)$group['id'];

// 2. FIND THE SESSION — by sessions.id (what the app sends) or batch_id (legacy),
//    in this group, owned by the caller.
$session = null;
if (ctype_digit($batch_id)) {
    $session = dbGetRow(
        "SELECT id, batch_id, user_id, device_id FROM sessions WHERE id = ? AND group_id = ?",
        [(int)$batch_id, $group_id]
    );
}
if (!$session) {
    $session = dbGetRow(
        "SELECT id, batch_id, user_id, device_id FROM sessions WHERE batch_id = ? AND group_id = ? ORDER BY id DESC LIMIT 1",
        [$batch_id, $group_id]
    );
}

if ($session) {
    // Ownership: the session's user must be the caller. (The old device_id
    // comparison is kept as an additional legacy check when user_id is empty.)
    $sessionOwner = (string)($session['user_id'] ?? '');
    if ($sessionOwner !== '' && $sessionOwner !== '0') {
        if ($sessionOwner !== (string)$auth_uid) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'You can only delete sessions you created']);
            exit;
        }
    } elseif (!empty($session['device_id']) && !empty($device_id) && $session['device_id'] !== $device_id) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        exit;
    }
}

$session_db_id  = $session ? (int)$session['id'] : null;
$session_batch  = $session ? (string)($session['batch_id'] ?? '') : '';

// Collect the players involved BEFORE deleting so their stats can be recalculated.
$affectedKeys = [];
try {
    $rows = [];
    if ($session_db_id !== null) {
        $rows = array_merge($rows, dbGetAll(
            "SELECT p1_key, p2_key, p3_key, p4_key FROM matches WHERE session_id = ? AND group_id = ?",
            [(string)$session_db_id, $group_id]
        ));
    }
    $legacyBatch = $session_batch !== '' ? $session_batch : $batch_id;
    $rows = array_merge($rows, dbGetAll(
        "SELECT p1_key, p2_key, p3_key, p4_key FROM matches WHERE batch_id = ? AND group_id = ?",
        [$legacyBatch, $group_id]
    ));
    if (function_exists('pbnow_match_rows_player_keys')) {
        $affectedKeys = pbnow_match_rows_player_keys($rows);
    }
} catch (Throwable $e) {
    error_log("delete_session: could not collect affected players: " . $e->getMessage());
}

$deleted_matches = 0;
$conn = getDBConnection();

// 3a. DELETE ALL MATCHES LINKED TO THIS SESSION (sessions.id)
if ($session_db_id !== null) {
    $stmt = $conn->prepare("DELETE FROM matches WHERE session_id = ? AND group_id = ?");
    $sidStr = (string)$session_db_id;
    $stmt->bind_param('si', $sidStr, $group_id);
    $stmt->execute();
    $deleted_matches += $stmt->affected_rows;
    $stmt->close();
}

// 3b. LEGACY: matches that only carry the batch_id (pre sessions.id linkage).
//     This is the original delete path, kept so old data still cleans up.
$legacyBatch = $session_batch !== '' ? $session_batch : $batch_id;
if ($legacyBatch !== '') {
    $stmt = $conn->prepare("DELETE FROM matches WHERE batch_id = ? AND group_id = ?");
    $stmt->bind_param('si', $legacyBatch, $group_id);
    $stmt->execute();
    $deleted_matches += $stmt->affected_rows;
    $stmt->close();
}

// 4. DELETE THE SESSION ROW
$deleted_sessions = 0;
if ($session_db_id !== null) {
    $stmt = $conn->prepare("DELETE FROM sessions WHERE id = ? AND group_id = ?");
    $stmt->bind_param('ii', $session_db_id, $group_id);
    $stmt->execute();
    $deleted_sessions += $stmt->affected_rows;
    $stmt->close();
} else {
    // Legacy: no sessions.id match — fall back to batch_id (original behaviour),
    // but only for rows the caller owns.
    $stmt = $conn->prepare("DELETE FROM sessions WHERE batch_id = ? AND group_id = ? AND (user_id = ? OR user_id IS NULL OR user_id = '')");
    $uidStr = (string)$auth_uid;
    $stmt->bind_param('sis', $batch_id, $group_id, $uidStr);
    $stmt->execute();
    $deleted_sessions += $stmt->affected_rows;
    $stmt->close();
}
$conn->close();

// 5. RECALCULATE PLAYER STATS for everyone who was in the deleted matches.
$stats_updated = 0;
if (!empty($affectedKeys) && function_exists('pbnow_recalc_player_stats')) {
    $stats_updated = pbnow_recalc_player_stats($affectedKeys);
}

if ($deleted_matches > 0 || $deleted_sessions > 0) {
    echo json_encode([
        'status' => 'success',
        'deleted_matches' => $deleted_matches,
        'deleted_sessions' => $deleted_sessions,
        'stats_updated' => $stats_updated
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Session not found or already deleted']);
}
?>
