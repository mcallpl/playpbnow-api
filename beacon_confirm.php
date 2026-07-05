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

if (!$lobby_id || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'lobby_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

// 1. Verify lobby exists and status='gathering'
$stmt = $conn->prepare("SELECT id, status FROM beacon_lobbies WHERE id = ?");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$lobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lobby) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby not found']);
    exit;
}

if ($lobby['status'] !== 'gathering') {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby is no longer gathering (status: ' . $lobby['status'] . ')']);
    exit;
}

// 2. Update member status to 'confirmed'
$stmt = $conn->prepare("UPDATE beacon_lobby_members SET status = 'confirmed' WHERE lobby_id = ? AND user_id = ?");
$stmt->bind_param('is', $lobby_id, $user_id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

$conn->close();

// 3. If no rows affected, user is not a member
if ($affected === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Not a member of this lobby']);
    exit;
}

echo json_encode(['status' => 'success']);
