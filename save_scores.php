<?php
// ============================================
// save_scores.php V3 — SMART DUPLICATE HANDLING
//
// matches table: id, group_id, session_id, batch_id, 
//   p1_key, p2_key, p3_key, p4_key,
//   p1_name, p2_name, p3_name, p4_name,
//   s1, s2, timestamp, match_date, device_id,
//   round_num, court_num, match_title, created_at, updated_at
//
// sessions table: id, group_id, batch_id, share_code, title,
//   device_id, user_id, session_date, scores_hash, created_at,
//   player_count, male_count, female_count
//
// RULES:
// 1. Same title + same session_date + same scores → "Already exists"
// 2. Same title + same session_date + different scores → Ask to update
// 3. Different title or date → save normally
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

try {

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'No data received']);
    exit;
}

$group_name       = $input['group_name'] ?? '';
$group_id         = $input['group_id'] ?? '';       // e.g. "group_1771312347_5"
$matches          = $input['matches'] ?? [];
$user_id          = $input['user_id'] ?? '';
$custom_timestamp = (int)($input['custom_timestamp'] ?? time());
$match_title      = $input['match_title'] ?? $group_name;
$force_update     = $input['force_update'] ?? false;
$share_code_input = strtoupper(trim($input['share_code'] ?? ''));

if (!$group_name || empty($matches) || !$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing group_name, matches, or user_id']);
    exit;
}

// Convert unix timestamp to MySQL datetime
$sessionDate = date('Y-m-d H:i:s', $custom_timestamp);

// ── 1. Find group by name (NEVER create duplicates) ────────
$existingGroup = dbGetRow(
    "SELECT id FROM `groups` WHERE name = ?",
    [$group_name]
);

if ($existingGroup) {
    $groupDbId = (int)$existingGroup['id'];
} else {
    // Only create if truly new — should rarely happen since groups are created in groups.tsx
    $groupDbId = dbInsert(
        "INSERT INTO `groups` (name, owner_user_id, group_key, device_id, created_at, updated_at) 
         VALUES (?, ?, ?, '', NOW(), NOW())",
        [$group_name, $user_id, $group_id]
    );
    if (!$groupDbId) {
        echo json_encode(['status' => 'error', 'message' => 'Could not create group']);
        exit;
    }
}

// ── 2. Build scores hash for duplicate comparison ────────────
$scoresHash = md5(json_encode($matches));

// ── 3. Check for existing session with same title + date ─────
$existingSession = dbGetRow(
    "SELECT id, scores_hash FROM sessions 
     WHERE group_id = ? AND session_date = ? AND title = ?",
    [$groupDbId, $sessionDate, $match_title]
);

if ($existingSession) {
    if ($existingSession['scores_hash'] === $scoresHash && !$force_update) {
        // RULE 1: Exact duplicate — same title, date, and scores
        echo json_encode([
            'status' => 'already_exists',
            'message' => 'This match has already been saved with identical scores.'
        ]);
        exit;
    }
    
    if ($existingSession['scores_hash'] !== $scoresHash && !$force_update) {
        // RULE 2: Same title+date but different scores — ask user
        echo json_encode([
            'status' => 'duplicate_diff_scores',
            'message' => 'A match with this title and date already exists but with different scores. Would you like to update it?',
            'existing_session_id' => $existingSession['id']
        ]);
        exit;
    }
    
    // force_update = true — delete old matches and re-insert
    $sessionId = (int)$existingSession['id'];
    $conn = getDBConnection();
    
    // Delete old match rows for this session
    $stmt = $conn->prepare("DELETE FROM matches WHERE session_id = ?");
    $sidStr = (string)$sessionId;
    $stmt->bind_param('s', $sidStr);
    $stmt->execute();
    $stmt->close();
    
    // Update session hash
    $stmt = $conn->prepare("UPDATE sessions SET scores_hash = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $scoresHash, $sessionId);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// ── 4. Create new session if not updating ────────────────────
if (!$existingSession || !$force_update) {
    $unique_batch_id = $group_id . '_' . time();
    $sessionId = dbInsert(
        "INSERT INTO sessions (group_id, batch_id, title, device_id, user_id, session_date, scores_hash, created_at, player_count, male_count, female_count)
         VALUES (?, ?, ?, '', ?, ?, ?, NOW(), 0, 0, 0)",
        [$groupDbId, $unique_batch_id, $match_title, $user_id, $sessionDate, $scoresHash]
    );
    
    if (!$sessionId) {
        echo json_encode(['status' => 'error', 'message' => 'Could not create session']);
        exit;
    }
}

// ── 5. Save individual matches ───────────────────────────────
$savedCount = 0;
$roundNum = 0;
$courtNum = 0;

foreach ($matches as $idx => $match) {
    $t1 = $match['t1'] ?? [];  // Array of player objects [{id, first_name}, {id, first_name}]
    $t2 = $match['t2'] ?? [];
    $s1 = (int)($match['s1'] ?? 0);
    $s2 = (int)($match['s2'] ?? 0);
    
    // Team 1: p1 and p2
    $p1_key  = isset($t1[0]) ? (string)($t1[0]['id'] ?? '') : '';
    $p1_name = isset($t1[0]) ? ($t1[0]['first_name'] ?? 'Unknown') : 'Unknown';
    $p2_key  = isset($t1[1]) ? (string)($t1[1]['id'] ?? '') : '';
    $p2_name = isset($t1[1]) ? ($t1[1]['first_name'] ?? 'Unknown') : 'Unknown';
    
    // Team 2: p3 and p4
    $p3_key  = isset($t2[0]) ? (string)($t2[0]['id'] ?? '') : '';
    $p3_name = isset($t2[0]) ? ($t2[0]['first_name'] ?? 'Unknown') : 'Unknown';
    $p4_key  = isset($t2[1]) ? (string)($t2[1]['id'] ?? '') : '';
    $p4_name = isset($t2[1]) ? ($t2[1]['first_name'] ?? 'Unknown') : 'Unknown';
    
    // Track round/court from the match data if provided, else increment
    $rNum = isset($match['round_num']) ? (int)$match['round_num'] : ($idx + 1);
    $cNum = isset($match['court_num']) ? (int)$match['court_num'] : 1;
    
    $inserted = dbInsert(
        "INSERT INTO matches (group_id, session_id, batch_id, 
            p1_key, p2_key, p3_key, p4_key, 
            p1_name, p2_name, p3_name, p4_name, 
            s1, s2, `timestamp`, match_date, device_id, 
            round_num, court_num, match_title, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, NOW(), NOW())",
        [
            $groupDbId,
            (string)$sessionId,
            $group_id,
            $p1_key, $p2_key, $p3_key, $p4_key,
            $p1_name, $p2_name, $p3_name, $p4_name,
            $s1, $s2,
            $custom_timestamp,
            $sessionDate,
            $rNum, $cNum,
            $match_title
        ]
    );
    
    if ($inserted) $savedCount++;
}

// ── 6. Recalculate player stats ──────────────────────────────
// Collect all unique player keys from this save
$playerKeys = [];
foreach ($matches as $match) {
    foreach (['t1', 't2'] as $team) {
        foreach ($match[$team] ?? [] as $p) {
            $pk = $p['id'] ?? '';
            if ($pk) $playerKeys[] = $pk;
        }
    }
}
$playerKeys = array_unique($playerKeys);

// Update stats for each player involved
foreach ($playerKeys as $pk) {
    // Team 1 matches
    $t1Matches = dbGetAll("SELECT s1, s2 FROM matches WHERE p1_key = ? OR p2_key = ?", [$pk, $pk]);
    $t2Matches = dbGetAll("SELECT s1, s2 FROM matches WHERE p3_key = ? OR p4_key = ?", [$pk, $pk]);
    
    $wins = 0; $losses = 0; $diff = 0;
    foreach ($t1Matches as $m) {
        $ms1 = (int)$m['s1']; $ms2 = (int)$m['s2'];
        if ($ms1 > $ms2) $wins++; elseif ($ms2 > $ms1) $losses++;
        $diff += ($ms1 - $ms2);
    }
    foreach ($t2Matches as $m) {
        $ms1 = (int)$m['s1']; $ms2 = (int)$m['s2'];
        if ($ms2 > $ms1) $wins++; elseif ($ms1 > $ms2) $losses++;
        $diff += ($ms2 - $ms1);
    }
    
    $total = $wins + $losses;
    $winPct = $total > 0 ? round(($wins / $total) * 100, 2) : 0.00;
    
    $conn2 = getDBConnection();
    $stmt2 = $conn2->prepare("UPDATE players SET wins = ?, losses = ?, diff = ?, win_pct = ? WHERE player_key = ?");
    $stmt2->bind_param('iiids', $wins, $losses, $diff, $winPct, $pk);
    $stmt2->execute();
    $stmt2->close();
    $conn2->close();
}

// ── 7. Mark collab session as finished (if collaborative) ────────
// Store the saved session_id so collaborators can navigate to the correct leaderboard session
if ($share_code_input) {
    $conn3 = getDBConnection();
    $stmt3 = $conn3->prepare(
        "UPDATE collab_sessions SET status = 'finished', saved_session_id = ? WHERE share_code = ? AND status = 'active'"
    );
    $stmt3->bind_param('is', $sessionId, $share_code_input);
    $stmt3->execute();
    $stmt3->close();
    $conn3->close();
}

echo json_encode([
    'status' => 'success',
    'session_id' => $sessionId,
    'matches_saved' => $savedCount,
    'stats_updated' => count($playerKeys),
    'message' => $force_update ? 'Scores updated successfully' : 'Match saved successfully'
]);

} catch (Exception $e) {
    error_log("SAVE_SCORES ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
