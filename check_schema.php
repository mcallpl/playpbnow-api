<?php
// One-time: add is_admin column to users table. DELETE AFTER USE.
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';
$conn = getDBConnection();
$results = [];

$cols = $conn->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0 AFTER is_active");
    $results['is_admin'] = 'added';
} else {
    $results['is_admin'] = 'already exists';
}

// Set admin users
$conn->query("UPDATE users SET is_admin = 1 WHERE phone = '+19497359415'");
$results['admin_set'] = 'phone +19497359415 set as admin';

// Show all users so you can identify which ones should be admin
$users = [];
$r = $conn->query("SELECT id, device_id, first_name, last_name, phone, is_admin, subscription_status FROM users ORDER BY id LIMIT 20");
while ($row = $r->fetch_assoc()) { $users[] = $row; }
$results['users'] = $users;

echo json_encode($results, JSON_PRETTY_PRINT);
