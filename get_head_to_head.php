<?php
// api/get_head_to_head.php
// V2: SMART MATCHING (ID or NAME)
// - Fixes "0 Wins" bug by matching against normalized names if IDs don't match.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// 1. GET PARAMS
$group_name = $_GET['group'] ?? '';
$p1_query = strtolower(trim($_GET['p1'] ?? '')); // Expecting lowercase name or ID
$p2_query = strtolower(trim($_GET['p2'] ?? ''));
$device_id = $_GET['device_id'] ?? '';

if (!$group_name || !$p1_query || !$p2_query) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

// 2. LOAD DATA
$file = __DIR__ . '/pickleball_data.json';
if (!file_exists($file)) {
    echo json_encode(['status' => 'error', 'message' => 'Database not found']);
    exit;
}
$data = json_decode(file_get_contents($file), true);

// 3. LOCATE GROUP
$group_data = null;
foreach ($data as $key => $content) {
    $this_name = $content['meta']['name'] ?? $key;
    if ($this_name === $group_name || $key === $group_name) {
        $group_data = $content;
        break;
    }
}

if (!$group_data) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}

// 4. CALCULATE HEAD TO HEAD
$stats = [
    'p1_wins' => 0,
    'p2_wins' => 0,
    'total' => 0,
    'diff' => 0
];

$history = $group_data['history'] ?? [];

foreach ($history as $game) {
    // A. FILTER: "Mine" vs "Global"
    if (!empty($device_id)) {
        $creator = $game['device_id'] ?? '';
        if ($creator !== '' && $creator !== $device_id) continue;
    }

    // B. NORMALIZE GAME DATA
    // We create a map of "Slot Index" -> "Normalized Name"
    // Slot 0=p1, 1=p2 (Team 1) | Slot 2=p3, 3=p4 (Team 2)
    $game_players = [
        0 => strtolower(trim($game['p1_name'] ?? '')),
        1 => strtolower(trim($game['p2_name'] ?? '')),
        2 => strtolower(trim($game['p3_name'] ?? '')),
        3 => strtolower(trim($game['p4_name'] ?? ''))
    ];
    
    $game_ids = [
        0 => $game['p1'] ?? '',
        1 => $game['p2'] ?? '',
        2 => $game['p3'] ?? '',
        3 => $game['p4'] ?? ''
    ];

    // C. LOCATE P1 and P2 IN THIS GAME
    // We check both ID and NAME to be safe
    $p1_slot = -1;
    $p2_slot = -1;

    foreach ($game_players as $slot => $g_name) {
        $g_id = $game_ids[$slot];
        
        // Check P1
        if ($g_name === $p1_query || $g_id === $p1_query) $p1_slot = $slot;
        // Check P2
        if ($g_name === $p2_query || $g_id === $p2_query) $p2_slot = $slot;
    }

    // If either player is missing from this game, skip it
    if ($p1_slot === -1 || $p2_slot === -1) continue;

    // D. DETERMINE TEAMS
    // Slots 0 & 1 are Team 1. Slots 2 & 3 are Team 2.
    $p1_team = ($p1_slot <= 1) ? 1 : 2;
    $p2_team = ($p2_slot <= 1) ? 1 : 2;

    // E. ONLY COUNT IF THEY ARE OPPONENTS (Versus)
    if ($p1_team === $p2_team) continue; // Partners, ignore

    // F. CALCULATE RESULT
    $stats['total']++;
    
    $s1 = (int)$game['s1'];
    $s2 = (int)$game['s2'];
    
    // Who won the game?
    $winning_team = ($s1 > $s2) ? 1 : 2;

    if ($p1_team === $winning_team) $stats['p1_wins']++;
    else $stats['p2_wins']++;

    // Calculate Diff (P1 perspective)
    // If P1 is Team 1, they get (s1 - s2). If P1 is Team 2, they get (s2 - s1).
    $score_diff = ($p1_team === 1) ? ($s1 - $s2) : ($s2 - $s1);
    $stats['diff'] += $score_diff;
}

echo json_encode(['status' => 'success', 'data' => $stats]);
?>