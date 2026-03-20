<?php
// One-time migration script — DELETE AFTER USE
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS password_reset_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_code (user_id, code),
    INDEX idx_expires (expires_at)
)";

if ($conn->query($sql)) {
    echo json_encode(['status' => 'success', 'message' => 'password_reset_codes table created']);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}
