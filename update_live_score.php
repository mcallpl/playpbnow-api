<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);

$session_id = $input['session_id'] ?? '';
$round_num = $input['round_num'] ?? 0;
$court_num = $input['court_num'] ?? 0;
$s1 = $input['s1'] ?? 0;
$s2 = $input['s2'] ?? 0;

if (empty($session_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID required']);
    exit;
}

// Update the match score
$result = dbQuery(
    "UPDATE matches 
     SET s1 = ?, s2 = ?, updated_at = NOW()
     WHERE session_id = ? AND round_num = ? AND court_num = ?",
    [$s1, $s2, $session_id, $round_num, $court_num]
);

echo json_encode([
    'status' => 'success',
    'message' => 'Score updated'
]);
?>
