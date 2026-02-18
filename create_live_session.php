<?php
error_reporting(0); // Suppress warnings
ini_set('display_errors', '0');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

$group_name = $input['group_name'] ?? '';
$group_key = $input['group_key'] ?? '';
$user_id = $input['user_id'] ?? '';
$players = $input['players'] ?? [];
$schedule = $input['schedule'] ?? [];

if (empty($group_name) || empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Group name and user ID required']);
    exit;
}

// Get or create group - match the unique constraint (owner_user_id + name)
$group = dbGetRow("SELECT id FROM `groups` WHERE owner_user_id = ? AND name = ?", [$user_id, $group_name]);

if (!$group) {
    // Only create if it doesn't exist
    try {
        $group_id = dbInsert(
            "INSERT INTO `groups` (group_key, name, owner_user_id, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW())",
            [$group_key, $group_name, $user_id]
        );
    } catch (Exception $e) {
        // If duplicate, just fetch the existing one
        $group = dbGetRow("SELECT id FROM `groups` WHERE owner_user_id = ? AND name = ?", [$user_id, $group_name]);
        $group_id = $group['id'];
    }
} else {
    $group_id = $group['id'];
}

// Generate unique share code
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
$share_code = '';
for ($attempt = 0; $attempt < 10; $attempt++) {
    $share_code = '';
    for ($i = 0; $i < 6; $i++) {
        $share_code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    
    $existing = dbGetRow("SELECT id FROM sessions WHERE share_code = ?", [$share_code]);
    if (!$existing) break;
}

// Create batch ID
$batch_id = "session_" . uniqid() . ".user_" . $user_id;

// Count players
$male_count = 0;
$female_count = 0;
foreach ($players as $p) {
    $gender = strtolower($p['gender'] ?? 'male');
    if (strpos($gender, 'f') === 0) $female_count++;
    else $male_count++;
}

// Create session
dbInsert(
    "INSERT INTO sessions (group_id, batch_id, share_code, title, session_date, user_id, player_count, male_count, female_count) 
     VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)",
    [$group_id, $batch_id, $share_code, $group_name, $user_id, count($players), $male_count, $female_count]
);

// Create empty match placeholders for the schedule
foreach ($schedule as $roundIdx => $round) {
    $round_num = $roundIdx + 1; // 1-indexed
    $games = $round['games'] ?? [];
    
    foreach ($games as $courtIdx => $game) {
        $court_num = $courtIdx + 1; // 1-indexed
        
        $p1 = $game['team1'][0] ?? null;
        $p2 = $game['team1'][1] ?? null;
        $p3 = $game['team2'][0] ?? null;
        $p4 = $game['team2'][1] ?? null;
        
        // Insert match with correct round number
        dbInsert(
            "INSERT INTO matches 
             (group_id, session_id, match_date, round_num, court_num,
              p1_key, p1_name, p2_key, p2_name, p3_key, p3_name, p4_key, p4_name, 
              s1, s2) 
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)",
            [
                $group_id, $batch_id, $round_num, $court_num,
                $p1['id'] ?? '', $p1['first_name'] ?? '',
                $p2['id'] ?? '', $p2['first_name'] ?? '',
                $p3['id'] ?? '', $p3['first_name'] ?? '',
                $p4['id'] ?? '', $p4['first_name'] ?? ''
            ]
        );
    }
}

error_log("🎾 Created live session: {$share_code} with " . count($schedule) . " rounds");

    echo json_encode([
        'status' => 'success',
        'session' => [
            'batch_id' => $batch_id,
            'share_code' => $share_code,
            'group_id' => $group_id,
            'group_key' => $group_key
        ]
    ]);
    
} catch (Exception $e) {
    error_log("❌ Create session error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
