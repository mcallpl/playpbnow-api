<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';
require_once 'beacon_common.php'; // H1 session adoption + H2 ISO dates

$input = json_decode(file_get_contents('php://input'), true);

$beacon_id = $input['beacon_id'] ?? null;
$user_id = $input['user_id'] ?? null;
$additional_minutes = $input['additional_minutes'] ?? null;

// H1: a valid session token overrides the client-supplied user id.
$user_id = beacon_adopt_session_user($user_id);
$user_id = $user_id !== null ? (string)$user_id : null;

if (!$beacon_id || !$user_id || !$additional_minutes) {
    echo json_encode(['status' => 'error', 'message' => 'beacon_id, user_id, and additional_minutes are required']);
    exit;
}

$additional_minutes = (int)$additional_minutes;
if ($additional_minutes < 1 || $additional_minutes > 240) {
    echo json_encode(['status' => 'error', 'message' => 'additional_minutes must be between 1 and 240']);
    exit;
}

$conn = getDBConnection();

// Verify beacon exists, is active, and belongs to user
$stmt = $conn->prepare("SELECT id, user_id, expires_at FROM beacons WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$beacon = $stmt->get_result()->fetch_assoc();
$stmt->close();

// M7: a beacon that lapsed while its lobby was still gathering/locked may be
// revived by its owner from the lobby screen (Extend). Only that case — a
// plainly expired beacon with no open lobby still cannot be extended.
$revive = false;
if (!$beacon) {
    $stmt = $conn->prepare(
        "SELECT b.id, b.user_id, b.expires_at
         FROM beacons b
         WHERE b.id = ? AND b.status = 'expired'
           AND EXISTS (SELECT 1 FROM beacon_lobbies bl WHERE bl.beacon_id = b.id AND bl.status IN ('gathering', 'locked'))"
    );
    $stmt->bind_param('i', $beacon_id);
    $stmt->execute();
    $beacon = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($beacon) $revive = true;
}

if (!$beacon) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Beacon not found or no longer active']);
    exit;
}

if ((string)$beacon['user_id'] !== (string)$user_id) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Only the beacon creator can extend it']);
    exit;
}

// Extend the beacon
if ($revive) {
    // Lapsed: extend from NOW (extending a past expiry could still be in the past)
    $stmt = $conn->prepare("UPDATE beacons SET status = 'active', expires_at = DATE_ADD(GREATEST(expires_at, NOW()), INTERVAL ? MINUTE) WHERE id = ?");
} else {
    $stmt = $conn->prepare("UPDATE beacons SET expires_at = DATE_ADD(expires_at, INTERVAL ? MINUTE) WHERE id = ?");
}
$stmt->bind_param('ii', $additional_minutes, $beacon_id);
$stmt->execute();
$stmt->close();

// Fetch updated beacon
$stmt = $conn->prepare("SELECT id, expires_at, status FROM beacons WHERE id = ?");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$updated = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

$updated = beacon_add_time_fields($updated);

echo json_encode(['status' => 'success', 'beacon' => $updated, 'revived' => $revive]);
