<?php
// ============================================================
// beacon_leave_lobby.php — C3: a real "leave" for structured lobbies.
//
// Before this endpoint existed, "Leave Lobby" only reset local state: the
// member row stayed 'joined' forever, blocked Lock ("X has not confirmed"),
// and a host who backed out left a gathering lobby (and its members polling)
// behind with no way to close it.
//
//   member  -> beacon_lobby_members.status = 'left' (+ their open replacement
//              request, if any, is cancelled)
//   host    -> lobby cancelled + beacon cancelled + open replacement requests
//              expired (so every poller tears down via lobby.status)
//
// POST JSON: { lobby_id, user_id }
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';
require_once 'beacon_common.php'; // H1 session adoption + H2 ISO dates

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$lobby_id = $input['lobby_id'] ?? null;
$user_id = $input['user_id'] ?? null;

// H1: a valid session token overrides the client-supplied user id.
$user_id = beacon_adopt_session_user($user_id);
$user_id = $user_id !== null ? (string)$user_id : null;

if (!$lobby_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'lobby_id and user_id are required']);
    exit;
}

$lobby_id = (int)$lobby_id;
$conn = getDBConnection();

if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

$stmt = $conn->prepare("SELECT id, beacon_id, host_user_id, status FROM beacon_lobbies WHERE id = ?");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$lobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lobby) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby not found']);
    exit;
}

// Already closed — idempotent success so a stale client can clean up.
if (!in_array($lobby['status'], ['gathering', 'locked'], true)) {
    $conn->close();
    echo json_encode([
        'status' => 'success',
        'role' => ((string)$lobby['host_user_id'] === (string)$user_id) ? 'host' : 'member',
        'lobby_status' => $lobby['status'],
        'already_closed' => true
    ]);
    exit;
}

$is_host = ((string)$lobby['host_user_id'] === (string)$user_id);
$beacon_id = (int)$lobby['beacon_id'];

$conn->begin_transaction();
try {
    if ($is_host) {
        // Host leaving = the game is off. Cancel lobby + beacon; expire requests.
        $stmt = $conn->prepare("UPDATE beacon_lobbies SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $lobby_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE beacons SET status = 'cancelled' WHERE id = ? AND status = 'active'");
        $stmt->bind_param('i', $beacon_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE replacement_requests SET status = 'expired' WHERE lobby_id = ? AND status = 'open'");
        $stmt->bind_param('i', $lobby_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $conn->close();
        echo json_encode(['status' => 'success', 'role' => 'host', 'lobby_status' => 'cancelled', 'beacon_status' => 'cancelled']);
        exit;
    }

    // Member leaving
    $stmt = $conn->prepare("SELECT id, status FROM beacon_lobby_members WHERE lobby_id = ? AND user_id = ? FOR UPDATE");
    $stmt->bind_param('is', $lobby_id, $user_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member) {
        $conn->rollback();
        $conn->close();
        // Not a member — nothing to leave; treat as success so the client resets.
        echo json_encode(['status' => 'success', 'role' => 'member', 'lobby_status' => $lobby['status'], 'not_a_member' => true]);
        exit;
    }

    if (in_array($member['status'], ['left', 'replaced'], true)) {
        $conn->rollback();
        $conn->close();
        echo json_encode(['status' => 'success', 'role' => 'member', 'lobby_status' => $lobby['status'], 'already_left' => true]);
        exit;
    }

    if ($lobby['status'] === 'locked') {
        // The schedule is built around this player — a silent exit would corrupt
        // it. Route them through Player Replacement Protection instead.
        $conn->rollback();
        $conn->close();
        echo json_encode([
            'status' => 'error',
            'code' => 'locked',
            'message' => "The match is locked. Use \"Can't Make It\" so a replacement can take your spot."
        ]);
        exit;
    }

    $member_id = (int)$member['id'];
    $stmt = $conn->prepare("UPDATE beacon_lobby_members SET status = 'left' WHERE id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $stmt->close();

    // If they were mid-replacement, close that request too.
    $stmt = $conn->prepare("UPDATE replacement_requests SET status = 'cancelled' WHERE lobby_id = ? AND departing_member_id = ? AND status = 'open'");
    $stmt->bind_param('ii', $lobby_id, $member_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Failed to leave lobby: ' . $e->getMessage()]);
    exit;
}

$conn->close();

echo json_encode(['status' => 'success', 'role' => 'member', 'lobby_status' => $lobby['status'], 'member_status' => 'left']);
