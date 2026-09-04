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

$lobby_id = $input['lobby_id'] ?? null;
$user_id = $input['user_id'] ?? null;
$player_id = $input['player_id'] ?? null;
$first_name = $input['first_name'] ?? null;
$last_name = $input['last_name'] ?? null;
$gender = $input['gender'] ?? null;

// H1: a valid session token overrides the client-supplied user id.
$user_id = beacon_adopt_session_user($user_id);
$user_id = $user_id !== null ? (string)$user_id : null;

if (!$lobby_id || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'lobby_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

// Transaction with a row lock on the lobby so two simultaneous joins can't
// both pass the capacity check and overfill the lobby.
$conn->begin_transaction();

// 1. Verify lobby exists and status='gathering' (FOR UPDATE serializes joins)
$stmt = $conn->prepare("SELECT id, target_players FROM beacon_lobbies WHERE id = ? AND status = 'gathering' FOR UPDATE");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$lobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lobby) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby not found or not accepting players']);
    exit;
}

// 2. Check current member count < target_players
$stmt = $conn->prepare("SELECT COUNT(*) AS member_count FROM beacon_lobby_members WHERE lobby_id = ? AND status NOT IN ('left', 'replaced')");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$count = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ((int)$count['member_count'] >= (int)$lobby['target_players']) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby is full']);
    exit;
}

// 3. Check user not already in lobby.
//    L7: UNIQUE(lobby_id, user_id) means a player who LEFT (or was replaced)
//    can never INSERT again — so any prior row is re-activated with UPDATE.
$stmt = $conn->prepare("SELECT id, status FROM beacon_lobby_members WHERE lobby_id = ? AND user_id = ?");
$stmt->bind_param('is', $lobby_id, $user_id);
$stmt->execute();
$existingAny = $stmt->get_result()->fetch_assoc();
$stmt->close();

$existing = ($existingAny && !in_array($existingAny['status'], ['left', 'replaced'], true)) ? $existingAny : null;

if ($existing) {
    $conn->rollback();
    // Not a failure for the client: they are already in — return the member
    // row so the app can resume the lobby (C4 owner-resume / self-heal).
    $stmt = $conn->prepare(
        "SELECT id, lobby_id, user_id, player_id, status, reliability_pct
         FROM beacon_lobby_members WHERE id = ?"
    );
    $existingId = (int)$existing['id'];
    $stmt->bind_param('i', $existingId);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    echo json_encode([
        'status' => 'success',
        'already_member' => true,
        'member' => $member,
        'message' => 'User already in this lobby'
    ]);
    exit;
}

// 4. Calculate user's reliability_pct (C3: 'started' counts as reached-a-match)
$stmt = $conn->prepare(beacon_reliability_sql());
$stmt->bind_param('s', $user_id);
$stmt->execute();
$reliability = $stmt->get_result()->fetch_assoc();
$reliability_pct = $reliability['reliability_pct'] !== null
    ? round((float)$reliability['reliability_pct'], 1)
    : null;
$stmt->close();

if ($existingAny) {
    // 5a. Re-join: UPDATE the existing (left/replaced) row back to 'joined'
    $stmt = $conn->prepare(
        "UPDATE beacon_lobby_members
         SET status = 'joined', player_id = ?, first_name = ?, last_name = ?, gender = ?, reliability_pct = ?, joined_at = NOW()
         WHERE id = ?"
    );
    $existingAnyId = (int)$existingAny['id'];
    $stmt->bind_param('isssdi', $player_id, $first_name, $last_name, $gender, $reliability_pct, $existingAnyId);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->rollback();
        $conn->close();
        echo json_encode(['status' => 'error', 'message' => 'Failed to rejoin lobby']);
        exit;
    }
    $member_id = $existingAnyId;
    $stmt->close();
} else {
    // 5. INSERT into beacon_lobby_members with status='joined'
    $stmt = $conn->prepare(
        "INSERT INTO beacon_lobby_members (lobby_id, user_id, player_id, first_name, last_name, gender, status, reliability_pct)
         VALUES (?, ?, ?, ?, ?, ?, 'joined', ?)"
    );
    $stmt->bind_param('isisssd', $lobby_id, $user_id, $player_id, $first_name, $last_name, $gender, $reliability_pct);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->rollback();
        $conn->close();
        echo json_encode(['status' => 'error', 'message' => 'Failed to join lobby']);
        exit;
    }
    $member_id = $conn->insert_id;
    $stmt->close();
}

$conn->commit();

// Fetch the member to return
$stmt = $conn->prepare(
    "SELECT id, lobby_id, user_id, player_id, status, reliability_pct
     FROM beacon_lobby_members WHERE id = ?"
);
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

echo json_encode(['status' => 'success', 'member' => $member]);
