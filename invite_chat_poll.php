<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$invite_id = $_GET['invite_id'] ?? null;
$after_id = $_GET['after_id'] ?? null;

if (!$invite_id) {
    echo json_encode(['status' => 'error', 'message' => 'invite_id is required']);
    exit;
}

if ($after_id) {
    // Incremental: only messages after the given ID
    $messages = dbGetAll(
        "SELECT id, invite_id, user_id, player_id, sender_name, message, is_system, created_at
         FROM invite_messages
         WHERE invite_id = ? AND id > ?
         ORDER BY id ASC",
        [$invite_id, $after_id]
    );
} else {
    // Initial load: last 50 messages
    $messages = dbGetAll(
        "SELECT id, invite_id, user_id, player_id, sender_name, message, is_system, created_at
         FROM invite_messages
         WHERE invite_id = ?
         ORDER BY id DESC
         LIMIT 50",
        [$invite_id]
    );
    // Reverse so oldest first
    $messages = array_reverse($messages);
}

echo json_encode(['status' => 'success', 'messages' => $messages]);
