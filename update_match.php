<?php

// ============================================
// update_match.php - DATABASE VERSION
// Updates match scores and player names
// Propagates name changes across the session
// ============================================

header('Content-Type: application/json');
// The app is served from playpbnow.com (same-origin) and legacy hosts; a
// peoplestar.com-only allow-origin made the browser drop the response
// everywhere else (Audit C H7). Authentication is the Bearer token, not the
// origin, so '*' is safe here.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// Must be a logged-in user (previously this endpoint had no identity check at
// all, so anyone who knew a match_id + group name could rewrite the scores).
$auth_uid = pbnow_require_session_user();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$match_id  = (int)($input['match_id'] ?? 0);
$group_key = $input['group'] ?? '';

if (!$match_id || !$group_key) {
    echo json_encode(['status' => 'error', 'message' => 'Missing identifiers']);
    exit;
}

// Resolve group_id - try by group_key first, then fall back to name.
// Audit C M12 / Audit E M15: the lookup is OWNER-SCOPED. Without it, two
// organizers who both have a group called "Tuesday" resolved to whichever
// row came first, and the wrong organizer's match could be edited.
$group = dbGetRow(
    "SELECT id FROM `groups` WHERE (group_key = ? OR name = ?) AND owner_user_id = ? AND _deleted_at IS NULL ORDER BY id ASC LIMIT 1",
    [$group_key, $group_key, $auth_uid]
);
if (!$group) {
    // Legacy rows that predate owner_user_id — the session ownership check
    // below still protects them.
    $group = dbGetRow(
        "SELECT id FROM `groups` WHERE (group_key = ? OR name = ?) AND (owner_user_id IS NULL OR owner_user_id = 0 OR owner_user_id = '') ORDER BY id ASC LIMIT 1",
        [$group_key, $group_key]
    );
}
if (!$group) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}
$group_id = (int)$group['id'];

// Find match by ID
$match = dbGetRow("SELECT * FROM matches WHERE id = ? AND group_id = ?", [$match_id, $group_id]);
if (!$match) {
    echo json_encode(['status' => 'error', 'message' => 'Match not found']);
    exit;
}

// Ownership: if the match records a creator, only they may edit it.
if (!empty($match['device_id']) && (string) $match['device_id'] !== (string) $auth_uid) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You can only edit matches you created']);
    exit;
}

$session_id = $match['session_id'] ?? null;

// Ownership via the session (Audit C M7): matches.device_id is always '' in
// practice, so the real owner is sessions.user_id.
if (!empty($session_id) && ctype_digit((string)$session_id)) {
    $sessOwner = dbGetRow("SELECT user_id FROM sessions WHERE id = ?", [(int)$session_id]);
    if ($sessOwner && !empty($sessOwner['user_id']) && (string)$sessOwner['user_id'] !== (string)$auth_uid) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You can only edit matches you created']);
        exit;
    }
}

// Players whose cached stats must be recalculated after the edit.
$affectedKeys = function_exists('pbnow_match_rows_player_keys') ? pbnow_match_rows_player_keys([$match]) : [];

// Gather inputs
$p1_id = $input['p1'] ?? '';
$p2_id = $input['p2'] ?? '';
$p3_id = $input['p3'] ?? '';
$p4_id = $input['p4'] ?? '';

$new_s1 = (int)($input['new_s1'] ?? 0);
$new_s2 = (int)($input['new_s2'] ?? 0);

// Build new_names safely
$new_names = [];
if (!empty($p1_id) && isset($input['p1_name'])) $new_names[$p1_id] = trim($input['p1_name']);
if (!empty($p2_id) && isset($input['p2_name'])) $new_names[$p2_id] = trim($input['p2_name']);
if (!empty($p3_id) && isset($input['p3_name'])) $new_names[$p3_id] = trim($input['p3_name']);
if (!empty($p4_id) && isset($input['p4_name'])) $new_names[$p4_id] = trim($input['p4_name']);

// Preserve existing names if no valid mapping provided
$p1_name_final = (!empty($p1_id) && isset($new_names[$p1_id])) ? $new_names[$p1_id] : $match['p1_name'];
$p2_name_final = (!empty($p2_id) && isset($new_names[$p2_id])) ? $new_names[$p2_id] : $match['p2_name'];
$p3_name_final = (!empty($p3_id) && isset($new_names[$p3_id])) ? $new_names[$p3_id] : $match['p3_name'];
$p4_name_final = (!empty($p4_id) && isset($new_names[$p4_id])) ? $new_names[$p4_id] : $match['p4_name'];

// Update the match (Scores + Names)
$conn = getDBConnection();
$stmt = $conn->prepare(
    "UPDATE matches 
     SET s1 = ?, s2 = ?, p1_name = ?, p2_name = ?, p3_name = ?, p4_name = ?
     WHERE id = ?"
);
$stmt->bind_param('iissssi', $new_s1, $new_s2, $p1_name_final, $p2_name_final, $p3_name_final, $p4_name_final, $match_id);
$stmt->execute();
$stmt->close();
$conn->close();

// Propagate name changes across the session
if ($session_id && !empty($new_names)) {
    foreach ($new_names as $player_key => $new_name) {
        if (empty($new_name) || empty($player_key)) continue;
        
        // Update p1
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p1_name = ? WHERE session_id = ? AND p1_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Update p2
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p2_name = ? WHERE session_id = ? AND p2_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Update p3
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p3_name = ? WHERE session_id = ? AND p3_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Update p4
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p4_name = ? WHERE session_id = ? AND p4_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
}

// Update master roster
if (!empty($new_names)) {
    foreach ($new_names as $player_key => $new_name) {
        if (empty($new_name) || empty($player_key)) continue;
        
        $conn = getDBConnection();
        // Scope the rename through player_group_memberships rather than the
        // legacy players.group_id column — renaming a player in a group they
        // joined via membership was previously a silent no-op.
        $stmt = $conn->prepare(
            "UPDATE players p
             INNER JOIN player_group_memberships pgm ON pgm.player_id = p.id
             SET p.first_name = ?
             WHERE pgm.group_id = ? AND p.player_key = ?"
        );
        $stmt->bind_param('sis', $new_name, $group_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
}

// Recalculate players.wins/losses/diff/win_pct for everyone in this match
// (Audit C M6 — previously only save_scores ever refreshed them).
$stats_updated = 0;
if (!empty($affectedKeys) && function_exists('pbnow_recalc_player_stats')) {
    $stats_updated = pbnow_recalc_player_stats($affectedKeys);
}

echo json_encode([
    'status'  => 'success',
    'message' => 'Match updated',
    'stats_updated' => $stats_updated
]);
?>