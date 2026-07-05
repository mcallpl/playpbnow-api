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

$input = json_decode(file_get_contents('php://input'), true);

$request_id = $input['replacement_request_id'] ?? null;
$user_id = $input['user_id'] ?? null;

if (!$request_id || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'replacement_request_id and user_id are required']);
    exit;
}

$conn = getDBConnection();

// Verify request exists, is open, and belongs to this user
$stmt = $conn->prepare("SELECT id, departing_user_id, departing_member_id, status FROM replacement_requests WHERE id = ?");
$stmt->bind_param('i', $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Replacement request not found']);
    exit;
}

if ($request['departing_user_id'] !== $user_id) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'You can only cancel your own replacement request']);
    exit;
}

if ($request['status'] !== 'open') {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'This replacement request has already been ' . $request['status']]);
    exit;
}

// Cancel the request and restore member status
$conn->begin_transaction();
try {
    // Cancel the request
    $stmt = $conn->prepare("UPDATE replacement_requests SET status = 'cancelled' WHERE id = ?");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $stmt->close();

    // Restore member status to confirmed
    $member_id = $request['departing_member_id'];
    $stmt = $conn->prepare("UPDATE beacon_lobby_members SET status = 'confirmed' WHERE id = ?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Failed to cancel replacement request']);
    exit;
}

$conn->close();

echo json_encode(['status' => 'success']);
