<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$share_code = $_GET['share_code'] ?? '';

if (empty($share_code)) {
    echo json_encode(['status' => 'error', 'message' => 'Share code required']);
    exit;
}

// Get session
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

// Get all matches
$matches = dbGetAll(
    "SELECT * FROM matches 
     WHERE session_id = ?
     ORDER BY round_num ASC, court_num ASC",
    [$session['batch_id']]
);

// Get players with gender info for lookup
$playerLookup = [];
$players = dbGetAll(
    "SELECT player_key as id, first_name as name, gender 
     FROM players 
     WHERE group_id = ?
     ORDER BY first_name ASC",
    [$session['group_id']]
);
foreach ($players as $p) {
    $playerLookup[$p['id']] = $p;
}

// Build schedule from matches - group by rounds
$scheduleByRound = [];
foreach ($matches as $match) {
    $roundIdx = $match['round_num'] - 1; // Convert to 0-indexed
    
    if (!isset($scheduleByRound[$roundIdx])) {
        $scheduleByRound[$roundIdx] = [
            'type' => 'mixed', // Default type
            'games' => [],
            'byes' => [] // Players sitting out this round
        ];
    }
    
    // Get actual player data with correct gender
    $p1 = $playerLookup[$match['p1_key']] ?? ['id' => $match['p1_key'], 'name' => $match['p1_name'], 'gender' => 'male'];
    $p2 = $playerLookup[$match['p2_key']] ?? ['id' => $match['p2_key'], 'name' => $match['p2_name'], 'gender' => 'female'];
    $p3 = $playerLookup[$match['p3_key']] ?? ['id' => $match['p3_key'], 'name' => $match['p3_name'], 'gender' => 'male'];
    $p4 = $playerLookup[$match['p4_key']] ?? ['id' => $match['p4_key'], 'name' => $match['p4_name'], 'gender' => 'female'];
    
    $scheduleByRound[$roundIdx]['games'][] = [
        'team1' => [
            ['id' => $p1['id'], 'first_name' => $p1['name'], 'gender' => $p1['gender']],
            ['id' => $p2['id'], 'first_name' => $p2['name'], 'gender' => $p2['gender']]
        ],
        'team2' => [
            ['id' => $p3['id'], 'first_name' => $p3['name'], 'gender' => $p3['gender']],
            ['id' => $p4['id'], 'first_name' => $p4['name'], 'gender' => $p4['gender']]
        ],
        's1' => $match['s1'],
        's2' => $match['s2'],
        'round_num' => $match['round_num'],
        'court_num' => $match['court_num']
    ];
}

// Convert to sequential array
$schedule = array_values($scheduleByRound);

echo json_encode([
    'status' => 'success',
    'session' => [
        'batch_id' => $session['batch_id'],
        'share_code' => $session['share_code'],
        'group_name' => $session['group_name'],
        'group_key' => $session['group_key'],
        'title' => $session['title'],
        'user_id' => $session['user_id']
    ],
    'schedule' => $schedule,
    'players' => $players,
    'matches' => $matches
]);
?>
