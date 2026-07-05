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
$host_user_id = $input['host_user_id'] ?? null;

if (!$lobby_id || !$host_user_id) {
    echo json_encode(['status' => 'error', 'message' => 'lobby_id and host_user_id are required']);
    exit;
}

$conn = getDBConnection();

// 1. Verify lobby exists and status='locked'
$stmt = $conn->prepare("SELECT * FROM beacon_lobbies WHERE id = ?");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$lobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lobby) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby not found']);
    exit;
}

if ($lobby['status'] !== 'locked') {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby must be locked before starting (status: ' . $lobby['status'] . ')']);
    exit;
}

// 2. Verify caller is the host
if ($lobby['host_user_id'] !== $host_user_id) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Only the host can start the match']);
    exit;
}

// 3. Generate a 6-character alphanumeric session code
$session_code = generateSessionCode();

// 4. Get the lobby's schedule_json
$schedule_json = $lobby['schedule_json'];

// 5. Get the court name
$stmt = $conn->prepare("
    SELECT c.name AS court_name
    FROM beacon_lobbies bl
    LEFT JOIN courts c ON c.id = bl.court_id
    WHERE bl.id = ?
");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$court_row = $stmt->get_result()->fetch_assoc();
$court_name = $court_row['court_name'] ?? 'Beacon Match';
$stmt->close();

// Begin transaction for atomicity
$conn->begin_transaction();

try {
    // 6. Create a collab_session
    $group_key = 'beacon_lobby_' . $lobby_id;
    $empty_str = '';
    $status_active = 'active';
    $stmt = $conn->prepare("
        INSERT INTO collab_sessions (session_code, host_device_id, host_user_id, group_key, group_name, schedule_json, status, created_at, expires_at)
        VALUES (?, '', ?, ?, ?, ?, 'active', NOW(), DATE_ADD(NOW(), INTERVAL 4 HOUR))
    ");
    $stmt->bind_param('sssss', $session_code, $host_user_id, $group_key, $court_name, $schedule_json);
    $stmt->execute();
    $session_id = (int)$conn->insert_id;
    $stmt->close();

    // 7. Add host as participant
    $role_host = 'host';
    $stmt = $conn->prepare("
        INSERT INTO collab_participants (session_id, device_id, user_id, role)
        VALUES (?, '', ?, 'host')
    ");
    $stmt->bind_param('is', $session_id, $host_user_id);
    $stmt->execute();
    $stmt->close();

    // 8. Update lobby to started with session info
    $stmt = $conn->prepare("
        UPDATE beacon_lobbies
        SET status = 'started', session_code = ?, collab_session_id = ?
        WHERE id = ?
    ");
    $stmt->bind_param('sii', $session_code, $session_id, $lobby_id);
    $stmt->execute();
    $stmt->close();

    // 9. Update the beacon status to 'expired' (match is starting)
    $stmt = $conn->prepare("UPDATE beacons SET status = 'expired' WHERE id = ?");
    $beacon_id = $lobby['beacon_id'];
    $stmt->bind_param('i', $beacon_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'session_code' => $session_code,
        'session_id' => $session_id,
        'schedule_json' => json_decode($schedule_json, true)
    ]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

/**
 * Generate a 6-character uppercase alphanumeric session code.
 * Avoids ambiguous characters (0/O, 1/I/L).
 */
function generateSessionCode(): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}
