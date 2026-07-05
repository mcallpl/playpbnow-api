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

$beacon_id = $input['beacon_id'] ?? null;
$host_user_id = $input['host_user_id'] ?? null;
$target_players = $input['target_players'] ?? 4;
$player_id = $input['player_id'] ?? null;
$first_name = $input['first_name'] ?? null;
$last_name = $input['last_name'] ?? null;
$gender = $input['gender'] ?? null;

if (!$beacon_id || !$host_user_id) {
    echo json_encode(['status' => 'error', 'message' => 'beacon_id and host_user_id are required']);
    exit;
}

$conn = getDBConnection();

// 1. Verify beacon exists and is active
$stmt = $conn->prepare("SELECT id, court_id FROM beacons WHERE id = ? AND status = 'active'");
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

    // 3. Calculate host's reliability_pct
    $stmt = $conn->prepare(
        "SELECT COUNT(CASE WHEN bl.status = 'completed' THEN 1 END) / NULLIF(COUNT(*), 0) * 100 AS reliability_pct
         FROM beacon_lobby_members blm
         JOIN beacon_lobbies bl ON blm.lobby_id = bl.id
         WHERE blm.user_id = ? AND blm.status != 'replaced'"
    );
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

    // Fetch the lobby to return
    $stmt = $conn->prepare(
        "SELECT id, beacon_id, host_user_id, court_id, status, target_players, created_at
         FROM beacon_lobbies WHERE id = ?"
    );
    $stmt->bind_param('i', $lobby_id);
    $stmt->execute();
    $lobby = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $conn->close();

    echo json_encode(['status' => 'success', 'lobby' => $lobby]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
