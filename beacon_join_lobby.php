<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

$lobby_id = $input['lobby_id'] ?? null;
$user_id = $input['user_id'] ?? null;
$player_id = $input['player_id'] ?? null;
$first_name = $input['first_name'] ?? null;
$last_name = $input['last_name'] ?? null;
$gender = $input['gender'] ?? null;

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

// 3. Check user not already in lobby
$stmt = $conn->prepare("SELECT id FROM beacon_lobby_members WHERE lobby_id = ? AND user_id = ? AND status NOT IN ('left', 'replaced')");
$stmt->bind_param('is', $lobby_id, $user_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'User already in this lobby']);
    exit;
}

// 4. Calculate user's reliability_pct
$stmt = $conn->prepare(
    "SELECT COUNT(CASE WHEN bl.status = 'completed' THEN 1 END) / NULLIF(COUNT(*), 0) * 100 AS reliability_pct
     FROM beacon_lobby_members blm
     JOIN beacon_lobbies bl ON blm.lobby_id = bl.id
     WHERE blm.user_id = ? AND blm.status != 'replaced'"
);
$stmt->bind_param('s', $user_id);
$stmt->execute();
$reliability = $stmt->get_result()->fetch_assoc();
$reliability_pct = $reliability['reliability_pct'] !== null
    ? round((float)$reliability['reliability_pct'], 1)
    : null;
$stmt->close();

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
