<?php
// One-time: create invite_messages table for invite chat
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$results = [];
$conn = getDBConnection();

$conn->query("CREATE TABLE IF NOT EXISTS invite_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invite_id INT NOT NULL,
    user_id VARCHAR(50) DEFAULT NULL,
    player_id INT DEFAULT NULL,
    sender_name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    is_system TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invite_id (invite_id),
    INDEX idx_created (invite_id, id)
)");
$results['invite_messages'] = $conn->error ?: 'created';

$conn->close();
echo json_encode($results, JSON_PRETTY_PRINT);
