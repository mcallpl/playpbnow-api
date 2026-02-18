<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);

$share_code = strtoupper(trim($input['share_code'] ?? ''));
$user_id = $input['user_id'] ?? '';

if (empty($share_code)) {
    echo json_encode(['status' => 'error', 'message' => 'Share code required']);
    exit;
}

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

// Find session by share code
$session = dbGetRow(
    "SELECT s.*, g.name as group_name, g.group_key 
     FROM sessions s
     JOIN `groups` g ON s.group_id = g.id
     WHERE s.share_code = ?",
    [$share_code]
);

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid share code']);
    exit;
}

// Get all matches for this session
$matches = dbGetAll(
    "SELECT * FROM matches 
     WHERE session_id = ?
     ORDER BY round_num ASC, court_num ASC",
    [$session['batch_id']]
);

// Get players for this group
$players = dbGetAll(
    "SELECT player_key as id, first_name as name, gender 
     FROM players 
     WHERE group_id = ?
     ORDER BY first_name ASC",
    [$session['group_id']]
);

// Check if user is the owner
$isOwner = ($session['user_id'] == $user_id);

echo json_encode([
    'status' => 'success',
    'session' => [
        'batch_id' => $session['batch_id'],
        'title' => $session['title'],
        'group_name' => $session['group_name'],
        'group_key' => $session['group_key'],
        'session_date' => $session['session_date'],
        'share_code' => $session['share_code'],
        'isOwner' => $isOwner,
        'owner_user_id' => $session['user_id']
    ],
    'matches' => $matches,
    'players' => $players
]);
?>
