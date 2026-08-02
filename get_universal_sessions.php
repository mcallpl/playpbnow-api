<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// GLOBAL mode removed alongside the leaderboard's. It listed every session from
// every organizer in the database, exposing their group names and session
// titles. Sessions are now always the caller's own.
// Prefer the session token over the spoofable user_id param.
$user_id = pbnow_optional_user_id();
if ($user_id === null) {
    $user_id = $_GET['user_id'] ?? '';
}

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user_id']);
    exit;
}

$sessions = dbGetAll(
    "SELECT s.id, s.user_id, s.title, g.name as `group`,
            UNIX_TIMESTAMP(s.session_date) as timestamp, s.created_at
     FROM sessions s
     JOIN `groups` g ON s.group_id = g.id
     WHERE s.user_id = ?
     ORDER BY s.session_date DESC",
    [$user_id]
);

$formatted = [];
foreach ($sessions as $s) {
    $date = date('M j, Y g:i A', $s['timestamp']);
    $isYours = (strval($s['user_id']) === strval($user_id));
    
    $formatted[] = [
        'id' => (string)$s['id'],  // Use sessions.id (numeric), cast to string for frontend
        'group' => $s['group'],
        'timestamp' => (int)$s['timestamp'],
        'label' => ($s['title'] ?? $s['group']) . " - {$date}",
        'user_id' => $s['user_id'],
        'isYours' => $isYours
    ];
}

echo json_encode([
    'status' => 'success',
    'sessions' => $formatted
]);
