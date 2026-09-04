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

// H1: a valid session token overrides the client-supplied user id.
$user_id = beacon_adopt_session_user($user_id);
$user_id = $user_id !== null ? (string)$user_id : null;

if (!$beacon_id || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'beacon_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

// Verify beacon exists, is active, and belongs to user
$stmt = $conn->prepare("SELECT id, user_id FROM beacons WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$beacon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$beacon) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Beacon not found or no longer active']);
    exit;
}

if ((string)$beacon['user_id'] !== (string)$user_id) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Only the beacon creator can cancel it']);
    exit;
}

// Cancel the beacon
$stmt = $conn->prepare("UPDATE beacons SET status = 'cancelled' WHERE id = ?");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$stmt->close();

// C3: cascade — an open lobby on a cancelled beacon used to stay 'gathering'
// forever while its members kept polling. Close it and expire any open
// replacement requests so every poller tears down.
$stmt = $conn->prepare("UPDATE beacon_lobbies SET status = 'cancelled' WHERE beacon_id = ? AND status IN ('gathering', 'locked')");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$lobbies_cancelled = $stmt->affected_rows;
$stmt->close();

$conn->query(
    "UPDATE replacement_requests rr
     JOIN beacon_lobbies bl ON rr.lobby_id = bl.id
     SET rr.status = 'expired'
     WHERE rr.status = 'open' AND bl.beacon_id = " . (int)$beacon_id
);

$conn->close();

echo json_encode(['status' => 'success', 'lobbies_cancelled' => (int)$lobbies_cancelled]);
