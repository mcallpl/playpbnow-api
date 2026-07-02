<?php
// ============================================
// delete_match.php - Delete a single match
// Was previously an empty (0-byte) file, so every match deletion silently
// failed with a "Network error" on the client. Reimplemented modeled on the
// working delete_session.php, keying ownership off matches.device_id (which,
// per the app, stores the creating user's id).
// ============================================

header("Access-Control-Allow-Origin: https://peoplestar.com");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);

$match_id  = $input['id'] ?? $input['match_id'] ?? '';
$device_id = $input['device_id'] ?? '';   // holds the requesting user's id
$group_key = $input['group'] ?? '';

if (!$match_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing match id']);
    exit;
}

// Look up the match and its recorded creator.
$match = dbGetRow(
    "SELECT id, device_id, group_id FROM matches WHERE id = ?",
    [$match_id]
);

if (!$match) {
    echo json_encode(['status' => 'error', 'message' => 'Match not found']);
    exit;
}

// Ownership: if the match records a creator, the requester must match it.
// (Mirrors delete_session.php's device_id permission check.)
if (!empty($match['device_id'])) {
    if (empty($device_id) || (string) $match['device_id'] !== (string) $device_id) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You can only delete matches you created']);
        exit;
    }
}

// Optional group sanity check when a group is supplied.
if (!empty($group_key) && !empty($match['group_id'])) {
    $group = dbGetRow(
        "SELECT id FROM `groups` WHERE group_key = ? OR name = ?",
        [$group_key, $group_key]
    );
    if ($group && (int) $group['id'] !== (int) $match['group_id']) {
        echo json_encode(['status' => 'error', 'message' => 'Group mismatch']);
        exit;
    }
}

$conn = getDBConnection();
$stmt = $conn->prepare("DELETE FROM matches WHERE id = ?");
$mid = (int) $match_id;
$stmt->bind_param('i', $mid);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();
$conn->close();

if ($deleted > 0) {
    echo json_encode(['status' => 'success', 'deleted' => $deleted]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Match not found or already deleted']);
}
