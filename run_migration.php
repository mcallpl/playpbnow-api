<?php
// ============================================================
// One-time migration runner — DELETE after use
// Adds password_hash column and sets owner password
// ============================================================
header('Content-Type: application/json');

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'migrate') {
    $conn = getDBConnection();

    // Check if password_hash column already exists
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'password_hash column already exists']);
        $conn->close();
        exit;
    }

    // Add column
    $ok = $conn->query("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL");
    $conn->close();

    if ($ok) {
        echo json_encode(['status' => 'success', 'message' => 'password_hash column added successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to add column: ' . $conn->error]);
    }

} elseif ($action === 'set_password') {
    // Set password for an existing user by phone or user_id
    $user_id = $input['user_id'] ?? null;
    $phone = $input['phone'] ?? null;
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Password required']);
        exit;
    }

    if (empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'Email required']);
        exit;
    }

    // Find user
    $user = null;
    if ($user_id) {
        $user = dbGetRow("SELECT * FROM users WHERE id = ?", [$user_id]);
    } elseif ($phone) {
        $clean_phone = cleanPhoneNumber($phone);
        $user = dbGetRow("SELECT * FROM users WHERE phone = ?", [$clean_phone]);
    }

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit;
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    dbQuery("UPDATE users SET email = ?, password_hash = ? WHERE id = ?", [$email, $password_hash, $user['id']]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Email and password set for user ' . $user['id'],
        'user_id' => $user['id'],
        'email' => $email
    ]);

} else {
    echo json_encode([
        'status' => 'info',
        'message' => 'Use action: "migrate" to add password_hash column, or "set_password" to set a user password',
        'usage' => [
            'migrate' => '{"action": "migrate"}',
            'set_password' => '{"action": "set_password", "phone": "+19497359415", "email": "you@email.com", "password": "yourpass"}'
        ]
    ]);
}
?>
