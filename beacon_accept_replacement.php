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
require_once 'beacon_schedule.php'; // H4: shared generateSchedule / calculateMatchQuality (with ids)

$input = json_decode(file_get_contents('php://input'), true);

$request_id = $input['replacement_request_id'] ?? null;
$user_id = $input['user_id'] ?? null;
$player_id = $input['player_id'] ?? null;
$first_name = $input['first_name'] ?? null;
$last_name = $input['last_name'] ?? '';
$gender = $input['gender'] ?? 'M';

// H1: a valid session token overrides the client-supplied user id.
$user_id = beacon_adopt_session_user($user_id);
$user_id = $user_id !== null ? (string)$user_id : null;

if (!$request_id || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'replacement_request_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

// Verify user has a verified profile (phone + name)
$stmt = $conn->prepare("SELECT first_name, last_name, phone FROM user_profiles WHERE user_id = ?");
$stmt->bind_param('s', $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile || !$profile['first_name'] || !$profile['phone']) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'You must verify your phone number and enter your name before accepting a replacement spot']);
    exit;
}

// Use server-verified name
$first_name = $profile['first_name'];
$last_name = $profile['last_name'] ?? '';

// H5: the request row is read and filled inside ONE transaction with a row
// lock, so two simultaneous accepts cannot both pass the 'open' check.
$conn->begin_transaction();

// Fetch the replacement request (FOR UPDATE serializes concurrent accepts)
$stmt = $conn->prepare(
    "SELECT rr.*, bl.status AS lobby_status, bl.host_user_id, bl.id AS lobby_id, bl.court_id, bl.target_players, bl.schedule_json
     FROM replacement_requests rr
     JOIN beacon_lobbies bl ON rr.lobby_id = bl.id
     WHERE rr.id = ?
     FOR UPDATE"
);
$stmt->bind_param('i', $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Replacement request not found']);
    exit;
}

if ($request['status'] !== 'open') {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'This replacement has already been filled']);
    exit;
}

if (!in_array($request['lobby_status'], ['gathering', 'locked'])) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'This lobby is no longer accepting replacements']);
    exit;
}

// Check user is not already in the lobby (active). L7: UNIQUE(lobby_id,user_id)
// means a player who previously left/was replaced has a row we must UPDATE,
// not INSERT again.
$stmt = $conn->prepare(
    "SELECT id, status FROM beacon_lobby_members WHERE lobby_id = ? AND user_id = ?"
);
$lobby_id = $request['lobby_id'];
$stmt->bind_param('is', $lobby_id, $user_id);
$stmt->execute();
$existingAnyMember = $stmt->get_result()->fetch_assoc();
$stmt->close();

$existingMember = ($existingAnyMember && !in_array($existingAnyMember['status'], ['left', 'replaced'], true)) ? $existingAnyMember : null;

if ($existingMember) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'You are already in this lobby']);
    exit;
}

// Calculate replacement player's reliability (C3: 'started' counts)
$reliability_pct = null;
$stmt = $conn->prepare(beacon_reliability_sql());
$stmt->bind_param('s', $user_id);
$stmt->execute();
$relRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($relRow && $relRow['reliability_pct'] !== null) {
    $reliability_pct = round((float)$relRow['reliability_pct'], 1);
}

// Perform the replacement (still inside the transaction opened above)
try {
    // 1. Insert new member as confirmed — or re-activate the prior row (L7)
    if ($existingAnyMember) {
        $stmt = $conn->prepare(
            "UPDATE beacon_lobby_members
             SET status = 'confirmed', player_id = ?, first_name = ?, last_name = ?, gender = ?, reliability_pct = ?, joined_at = NOW()
             WHERE id = ?"
        );
        $existingAnyMemberId = (int)$existingAnyMember['id'];
        $stmt->bind_param('isssdi', $player_id, $first_name, $last_name, $gender, $reliability_pct, $existingAnyMemberId);
        $stmt->execute();
        $new_member_id = $existingAnyMemberId;
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO beacon_lobby_members (lobby_id, user_id, player_id, first_name, last_name, gender, status, reliability_pct)
             VALUES (?, ?, ?, ?, ?, ?, 'confirmed', ?)"
        );
        $stmt->bind_param('isisssd', $lobby_id, $user_id, $player_id, $first_name, $last_name, $gender, $reliability_pct);
        $stmt->execute();
        $new_member_id = $conn->insert_id;
        $stmt->close();
    }

    // 2. Update departing member status to 'replaced'
    $departing_member_id = $request['departing_member_id'];
    $stmt = $conn->prepare("UPDATE beacon_lobby_members SET status = 'replaced' WHERE id = ?");
    $stmt->bind_param('i', $departing_member_id);
    $stmt->execute();
    $stmt->close();

    // 3. Update replacement request to 'filled'
    $stmt = $conn->prepare(
        "UPDATE replacement_requests SET status = 'filled', replacement_user_id = ?, replacement_member_id = ?, filled_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('sii', $user_id, $new_member_id, $request_id);
    $stmt->execute();
    $stmt->close();

    // 4. If lobby was locked, regenerate schedule with new roster
    if ($request['lobby_status'] === 'locked') {
        // Get all active members — H4: WITH ids (player_id / user_id), exactly
        // like the lock path, so the scoring screen can key every player.
        $stmt = $conn->prepare(
            "SELECT id, user_id, player_id, first_name, last_name, gender, status, reliability_pct
             FROM beacon_lobby_members WHERE lobby_id = ? AND status NOT IN ('left', 'replaced') ORDER BY joined_at ASC"
        );
        $stmt->bind_param('i', $lobby_id);
        $stmt->execute();
        $membersResult = $stmt->get_result();
        $memberRows = [];
        while ($row = $membersResult->fetch_assoc()) {
            $memberRows[] = $row;
        }
        $stmt->close();
        $players = beacon_schedule_players($memberRows);

        // Schedule regeneration via the shared generator
        $schedule = generateSchedule($players);
        $matchQuality = calculateMatchQuality($memberRows);
        $scheduleJson = json_encode($schedule);

        $stmt = $conn->prepare("UPDATE beacon_lobbies SET schedule_json = ?, match_quality_percent = ? WHERE id = ?");
        $stmt->bind_param('sii', $scheduleJson, $matchQuality, $lobby_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Failed to process replacement: ' . $e->getMessage()]);
    exit;
}

// Fetch updated lobby data to return
$stmt = $conn->prepare(
    "SELECT id, beacon_id, host_user_id, court_id, status, target_players, schedule_json, match_quality_percent, session_code, collab_session_id, created_at
     FROM beacon_lobbies WHERE id = ?"
);
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$lobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

$lobby = beacon_add_time_fields($lobby);
if (!empty($lobby['schedule_json']) && is_string($lobby['schedule_json'])) {
    $lobby['schedule_json'] = json_decode($lobby['schedule_json'], true);
}

echo json_encode(['status' => 'success', 'lobby' => $lobby, 'member_id' => $new_member_id]);


// --- Schedule generation (legacy per-file copy, kept as a fallback; the
//     shared beacon_schedule.php versions loaded at the top are what run) ---

if (!function_exists('generateSchedule')) {
function generateSchedule($players) {
    $count = count($players);
    if ($count < 2) return [];

    if ($count < 4) {
        // Simple 1v1 or uneven
        return [['games' => [['team1' => [$players[0]], 'team2' => [$players[1] ?? $players[0]]]], 'byes' => array_slice($players, 2)]];
    }

    if ($count === 4) {
        return [
            ['games' => [['team1' => [$players[0], $players[1]], 'team2' => [$players[2], $players[3]]]], 'byes' => []],
            ['games' => [['team1' => [$players[0], $players[2]], 'team2' => [$players[1], $players[3]]]], 'byes' => []],
            ['games' => [['team1' => [$players[0], $players[3]], 'team2' => [$players[1], $players[2]]]], 'byes' => []],
        ];
    }

    // N players: round-robin with byes
    $schedule = [];
    $used = [];
    $maxRounds = min(6, $count - 1);
    for ($r = 0; $r < $maxRounds; $r++) {
        $available = $players;
        shuffle($available);
        $games = [];
        $byes = [];
        while (count($available) >= 4) {
            $team1 = [array_shift($available), array_shift($available)];
            $team2 = [array_shift($available), array_shift($available)];
            $games[] = ['team1' => $team1, 'team2' => $team2];
        }
        $byes = $available;
        $schedule[] = ['games' => $games, 'byes' => $byes];
    }
    return $schedule;
}
}

if (!function_exists('calculateMatchQuality')) {
function calculateMatchQuality($players) {
    $base = 75;
    $count = count($players);
    if ($count % 4 === 0) $base += 10;
    $males = 0; $females = 0;
    foreach ($players as $p) {
        if (($p['gender'] ?? 'M') === 'F') $females++;
        else $males++;
    }
    if (abs($males - $females) <= 1) $base += 5;
    return min(100, $base + 10); // +10 for replacement scenario (all confirmed)
}
}
