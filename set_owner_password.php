<?php
// One-time script to set owner's email/password — DELETE after use
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';
$email = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';

if (empty($phone) || empty($email) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'phone, email, and password are all required']);
    exit;
}

$clean_phone = cleanPhoneNumber($phone);
$user = dbGetRow("SELECT * FROM users WHERE phone = ?", [$clean_phone]);

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User not found for phone: ' . $clean_phone]);
    exit;
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);
dbQuery("UPDATE users SET email = ?, password_hash = ? WHERE id = ?", [$email, $password_hash, $user['id']]);

echo json_encode([
    'status' => 'success',
    'message' => 'Email and password set',
    'user_id' => $user['id'],
    'email' => $email
]);
?>
