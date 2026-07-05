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

$user_id = $input['user_id'] ?? null;
$phone = $input['phone'] ?? null;
$first_name = $input['first_name'] ?? null;
$last_name = $input['last_name'] ?? null;
$gender = $input['gender'] ?? null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
    exit;
}

if (!$phone || !trim($phone)) {
    echo json_encode(['status' => 'error', 'message' => 'Verified phone number is required']);
    exit;
}

if (!$first_name || !trim($first_name)) {
    echo json_encode(['status' => 'error', 'message' => 'first_name is required']);
    exit;
}

$first_name = trim($first_name);
$last_name = trim($last_name ?? '');

$conn = getDBConnection();

// Upsert: insert or update on duplicate user_id
$stmt = $conn->prepare(
    "INSERT INTO user_profiles (user_id, phone, first_name, last_name, gender)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       phone = COALESCE(VALUES(phone), phone),
       first_name = VALUES(first_name),
       last_name = VALUES(last_name),
       gender = COALESCE(VALUES(gender), gender),
       updated_at = NOW()"
);
$stmt->bind_param('sssss', $user_id, $phone, $first_name, $last_name, $gender);
$stmt->execute();
$stmt->close();

// Fetch the saved profile to return
$stmt = $conn->prepare(
    "SELECT user_id, phone, first_name, last_name, gender, created_at
     FROM user_profiles WHERE user_id = ?"
);
$stmt->bind_param('s', $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

echo json_encode(['status' => 'success', 'profile' => $profile]);
