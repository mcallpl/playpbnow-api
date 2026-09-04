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

// Parse input from query params (GET request)
$lobby_id = $_GET['lobby_id'] ?? null;
$user_id = $_GET['user_id'] ?? null;

// H1: a valid session token overrides the client-supplied user id.
$user_id = beacon_adopt_session_user($user_id);
$user_id = $user_id !== null ? (string)$user_id : null;

if (!$lobby_id) {
    echo json_encode(['status' => 'error', 'message' => 'lobby_id is required']);
    exit;
}

$conn = getDBConnection();

// M7: keep beacon status honest while a lobby is open
$conn->query("UPDATE beacons SET status='expired' WHERE expires_at < NOW() AND status='active'");

// 1. Get lobby data with court info (+ C3/M7: the parent beacon's status and expiry
//    so every poller can tear down on cancel and show a countdown / Extend)
$stmt = $conn->prepare("
    SELECT bl.*, c.name AS court_name,
           b.status AS beacon_status, b.expires_at AS beacon_expires_at, b.user_id AS beacon_user_id
    FROM beacon_lobbies bl
    LEFT JOIN courts c ON c.id = bl.court_id
    LEFT JOIN beacons b ON b.id = bl.beacon_id
    WHERE bl.id = ?
");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$lobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lobby) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby not found']);
    exit;
}

// 2. Get all members ordered by join time
$stmt = $conn->prepare("
    SELECT id, user_id, player_id, first_name, last_name, gender, status, reliability_pct, joined_at
    FROM beacon_lobby_members
    WHERE lobby_id = ?
    ORDER BY joined_at ASC
");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$result = $stmt->get_result();
$members = [];
while ($row = $result->fetch_assoc()) {
    $members[] = $row;
}
$stmt->close();

// 2b. Get open replacement requests for this lobby
$stmt = $conn->prepare("
    SELECT rr.id, rr.lobby_id, rr.departing_member_id, rr.departing_user_id,
           rr.replacement_user_id, rr.replacement_member_id, rr.status,
           rr.created_at, rr.filled_at,
           blm.first_name AS departing_first_name, blm.last_name AS departing_last_name
    FROM replacement_requests rr
    LEFT JOIN beacon_lobby_members blm ON rr.departing_member_id = blm.id
    WHERE rr.lobby_id = ? AND rr.status = 'open'
    ORDER BY rr.created_at ASC
");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$rrResult = $stmt->get_result();
$replacement_requests = [];
while ($rr = $rrResult->fetch_assoc()) {
    $replacement_requests[] = $rr;
}
$stmt->close();
$conn->close();

// 3. Count confirmed members and determine if all confirmed
$confirmed_count = 0;
$active_members = 0;
foreach ($members as $member) {
    if (!in_array($member['status'], ['left', 'replaced'])) {
        $active_members++;
    }
    if ($member['status'] === 'confirmed') {
        $confirmed_count++;
    }
}

$all_confirmed = ($active_members > 0 && $confirmed_count === $active_members);

// 4. Build lobby response object
$lobby_response = [
    'id' => (int)$lobby['id'],
    'beacon_id' => (int)$lobby['beacon_id'],
    'host_user_id' => $lobby['host_user_id'],
    'court_id' => (int)$lobby['court_id'],
    'court_name' => $lobby['court_name'] ?? null,
    'status' => $lobby['status'],
    'target_players' => (int)$lobby['target_players'],
    'schedule_json' => $lobby['schedule_json'] ? json_decode($lobby['schedule_json'], true) : null,
    'match_quality_percent' => $lobby['match_quality_percent'] !== null ? (int)$lobby['match_quality_percent'] : null,
    'session_code' => $lobby['session_code'],
    'collab_session_id' => $lobby['collab_session_id'] !== null ? (int)$lobby['collab_session_id'] : null,
    'created_at' => $lobby['created_at'],
    // H2 / M7 additions (old fields above are untouched)
    'created_at_iso' => beacon_iso($lobby['created_at']),
    'beacon_status' => $lobby['beacon_status'] ?? null,
    'beacon_expires_at' => $lobby['beacon_expires_at'] ?? null,
    'beacon_expires_at_iso' => beacon_iso($lobby['beacon_expires_at'] ?? null),
    'beacon_expires_in_sec' => beacon_expires_in_sec($lobby['beacon_expires_at'] ?? null),
    'active_member_count' => $active_members,
    'server_now_iso' => date('c'),
];

// Caller's own membership (C4 resume / self-heal)
$my_status = null;
if ($user_id !== null && $user_id !== '') {
    foreach ($members as $member) {
        if ((string)$member['user_id'] === (string)$user_id) { $my_status = $member['status']; break; }
    }
}

// 5. Build members response array
$members_response = [];
foreach ($members as $member) {
    $members_response[] = [
        'id' => (int)$member['id'],
        'user_id' => $member['user_id'],
        'player_id' => $member['player_id'] !== null ? (int)$member['player_id'] : null,
        'first_name' => $member['first_name'],
        'last_name' => $member['last_name'],
        'gender' => $member['gender'],
        'status' => $member['status'],
        'reliability_pct' => $member['reliability_pct'] !== null ? (float)$member['reliability_pct'] : null,
        'joined_at' => $member['joined_at'] ?? null,
        'joined_at_iso' => beacon_iso($member['joined_at'] ?? null),
    ];
}

foreach ($replacement_requests as $ri => $rr) {
    $replacement_requests[$ri]['created_at_iso'] = beacon_iso($rr['created_at'] ?? null);
    $replacement_requests[$ri]['filled_at_iso'] = beacon_iso($rr['filled_at'] ?? null);
}

echo json_encode([
    'status' => 'success',
    'lobby' => $lobby_response,
    'members' => $members_response,
    'replacement_requests' => $replacement_requests,
    'confirmed_count' => $confirmed_count,
    'all_confirmed' => $all_confirmed,
    // additions
    'active_member_count' => $active_members,
    'beacon_status' => $lobby['beacon_status'] ?? null,
    'beacon_expires_at' => $lobby['beacon_expires_at'] ?? null,
    'beacon_expires_at_iso' => beacon_iso($lobby['beacon_expires_at'] ?? null),
    'beacon_expires_in_sec' => beacon_expires_in_sec($lobby['beacon_expires_at'] ?? null),
    'my_status' => $my_status,
    'server_now_iso' => date('c'),
]);
