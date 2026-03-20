<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? '';

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

$updates = [];
$params = [];

if (isset($input['first_name'])) {
    $updates[] = "first_name = ?";
    $params[] = trim($input['first_name']);
}

if (isset($input['last_name'])) {
    $updates[] = "last_name = ?";
    $params[] = trim($input['last_name']);
}

if (isset($input['email'])) {
    $email = strtolower(trim($input['email']));
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email address']);
        exit;
    }
    // Check for duplicate email
    if (!empty($email)) {
        $existing = dbGetRow("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $user_id]);
        if ($existing) {
            echo json_encode(['status' => 'error', 'message' => 'This email is already in use by another account']);
            exit;
        }
    }
    $updates[] = "email = ?";
    $params[] = $email;
}

if (isset($input['phone'])) {
    $phone = trim($input['phone']);
    if (!empty($phone)) {
        $phone = cleanPhoneNumber($phone);
    } else {
        $phone = null;
    }
    if ($phone) {
        $updates[] = "phone = ?";
        $params[] = $phone;
    } else {
        $updates[] = "phone = NULL";
    }
}

if (isset($input['dupr_rating'])) {
    $dupr = trim($input['dupr_rating']);
    if (!empty($dupr)) {
        $rating = floatval($dupr);
        if ($rating >= 1.0 && $rating <= 8.0) {
            $updates[] = "dupr_rating = ?";
            $params[] = $rating;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'DUPR rating must be between 1.0 and 8.0']);
            exit;
        }
    } else {
        $updates[] = "dupr_rating = NULL";
    }
}

if (empty($updates)) {
    echo json_encode(['status' => 'error', 'message' => 'No fields to update']);
    exit;
}

$params[] = $user_id;
$sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
dbQuery($sql, $params);

// Return updated user
$user = dbGetRow("SELECT id, email, first_name, last_name, phone, dupr_rating FROM users WHERE id = ?", [$user_id]);

echo json_encode([
    'status' => 'success',
    'message' => 'Profile updated',
    'user' => [
        'id' => $user['id'],
        'email' => $user['email'] ?? '',
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'phone' => $user['phone'] ?? '',
        'dupr_rating' => $user['dupr_rating'] ?? '',
    ]
]);
