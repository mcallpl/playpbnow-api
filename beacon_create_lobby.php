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

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

$beacon_id = $input['beacon_id'] ?? null;
$host_user_id = $input['host_user_id'] ?? null;
$target_players = $input['target_players'] ?? 4;
$player_id = $input['player_id'] ?? null;
$first_name = $input['first_name'] ?? null;
$last_name = $input['last_name'] ?? null;
$gender = $input['gender'] ?? null;

// H1: a valid session token overrides the client-supplied host id.
$host_user_id = beacon_adopt_session_user($host_user_id);
$host_user_id = $host_user_id !== null ? (string)$host_user_id : null;

if (!$beacon_id || !$host_user_id) {
    echo json_encode(['status' => 'error', 'message' => 'beacon_id and host_user_id are required']);
    exit;
}

// C2: target_players INCLUDES the host (the host is auto-added as the first
// member), so anything below 2 is a lobby nobody could ever join.
$target_players = (int)$target_players;
if ($target_players < 2) {
    echo json_encode(['status' => 'error', 'message' => 'target_players must be at least 2 (the host plus one more player)']);
    exit;
}

$conn = getDBConnection();

// 1. Verify beacon exists and is active
$stmt = $conn->prepare("SELECT id, court_id, user_id, expires_at FROM beacons WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$beacon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$beacon) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Beacon not found or no longer active']);
    exit;
}

$court_id = $beacon['court_id'];

// C1: only the beacon owner may open its lobby. Responders must join the
// beacon's existing lobby (beacon.active_lobby_id -> beacon_join_lobby.php).
if ((string)$beacon['user_id'] !== (string)$host_user_id) {
    $conn->close();
    echo json_encode([
        'status' => 'error',
        'code' => 'not_owner',
        'message' => 'Only the beacon owner can open its lobby. Join the existing lobby instead.'
    ]);
    exit;
}

// C1 / M8: idempotent — if this beacon already has an open lobby, return it
// instead of creating a second one (every re-press/restart used to strand
// the previous lobby and its members).
$stmt = $conn->prepare(
    "SELECT bl.id, bl.beacon_id, bl.host_user_id, bl.court_id, bl.status, bl.target_players, bl.created_at,
            c.name AS court_name
     FROM beacon_lobbies bl
     LEFT JOIN courts c ON c.id = bl.court_id
     WHERE bl.beacon_id = ? AND bl.status IN ('gathering', 'locked')
     ORDER BY bl.id DESC LIMIT 1"
);
$stmt->bind_param('i', $beacon_id);
$stmt->execute();
$existingLobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existingLobby) {
    // Self-heal: make sure the host is still an active member of their own lobby.
    $stmt = $conn->prepare("SELECT id, status FROM beacon_lobby_members WHERE lobby_id = ? AND user_id = ?");
    $existingLobbyId = (int)$existingLobby['id'];
    $stmt->bind_param('is', $existingLobbyId, $host_user_id);
    $stmt->execute();
    $hostRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$hostRow) {
        $stmt = $conn->prepare(
            "INSERT INTO beacon_lobby_members (lobby_id, user_id, player_id, first_name, last_name, gender, status)
             VALUES (?, ?, ?, ?, ?, ?, 'confirmed')"
        );
        $stmt->bind_param('isisss', $existingLobbyId, $host_user_id, $player_id, $first_name, $last_name, $gender);
        $stmt->execute();
        $stmt->close();
    } elseif (in_array($hostRow['status'], ['left', 'replaced'], true)) {
        $stmt = $conn->prepare("UPDATE beacon_lobby_members SET status = 'confirmed' WHERE id = ?");
        $hostRowId = (int)$hostRow['id'];
        $stmt->bind_param('i', $hostRowId);
        $stmt->execute();
        $stmt->close();
    }
    $conn->close();
    $existingLobby = beacon_add_time_fields($existingLobby);
    $existingLobby['beacon_expires_at'] = $beacon['expires_at'];
    $existingLobby['beacon_expires_at_iso'] = beacon_iso($beacon['expires_at']);
    $existingLobby['beacon_expires_in_sec'] = beacon_expires_in_sec($beacon['expires_at']);
    echo json_encode(['status' => 'success', 'lobby' => $existingLobby, 'existing' => true]);
    exit;
}

// Begin transaction
$conn->begin_transaction();

try {
    // 2. Create lobby
    $stmt = $conn->prepare(
        "INSERT INTO beacon_lobbies (beacon_id, host_user_id, court_id, status, target_players)
         VALUES (?, ?, ?, 'gathering', ?)"
    );
    $stmt->bind_param('isii', $beacon_id, $host_user_id, $court_id, $target_players);
    $stmt->execute();
    $lobby_id = $conn->insert_id;
    $stmt->close();

    // 3. Calculate host's reliability_pct (C3: 'started' counts as reached-a-match)
    $stmt = $conn->prepare(beacon_reliability_sql());
    $stmt->bind_param('s', $host_user_id);
    $stmt->execute();
    $reliability = $stmt->get_result()->fetch_assoc();
    $reliability_pct = $reliability['reliability_pct'] !== null
        ? round((float)$reliability['reliability_pct'], 1)
        : null;
    $stmt->close();

    // 4. Add host as first member with status='confirmed'
    $stmt = $conn->prepare(
        "INSERT INTO beacon_lobby_members (lobby_id, user_id, player_id, first_name, last_name, gender, status, reliability_pct)
         VALUES (?, ?, ?, ?, ?, ?, 'confirmed', ?)"
    );
    $stmt->bind_param('isisssd', $lobby_id, $host_user_id, $player_id, $first_name, $last_name, $gender, $reliability_pct);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // Fetch the lobby to return (L9: include court_name so the lobby header
    // is populated before the first poll lands)
    $stmt = $conn->prepare(
        "SELECT bl.id, bl.beacon_id, bl.host_user_id, bl.court_id, bl.status, bl.target_players, bl.created_at,
                c.name AS court_name
         FROM beacon_lobbies bl
         LEFT JOIN courts c ON c.id = bl.court_id
         WHERE bl.id = ?"
    );
    $stmt->bind_param('i', $lobby_id);
    $stmt->execute();
    $lobby = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $conn->close();

    $lobby = beacon_add_time_fields($lobby);
    $lobby['beacon_expires_at'] = $beacon['expires_at'];
    $lobby['beacon_expires_at_iso'] = beacon_iso($beacon['expires_at']);
    $lobby['beacon_expires_in_sec'] = beacon_expires_in_sec($beacon['expires_at']);

    echo json_encode(['status' => 'success', 'lobby' => $lobby, 'existing' => false]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
