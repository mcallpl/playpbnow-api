<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Version: leaderboard-v5-dedup');
require_once __DIR__ . '/db_config.php';

$group_key = $_GET['group_key'] ?? $_GET['group'] ?? '';
$user_id   = $_GET['user_id'] ?? '';
$is_global = $_GET['is_global'] ?? 'false';
$batch_id  = $_GET['batch_id'] ?? 'all';

$isGlobal = ($is_global === 'true' || $is_global === '1' || $is_global === 1);

if (empty($group_key)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing group']);
    exit;
}
if (empty($user_id) && !$isGlobal) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user_id']);
    exit;
}

// Get group by NAME (the frontend sends group name, not key)
$group = dbGetRow("SELECT id FROM `groups` WHERE name = ?", [$group_key]);
if (!$group) {
    echo json_encode(['status' => 'success', 'leaderboard' => [], 'history' => [], 'roster' => []]);
    exit;
}
$group_id = $group['id'];

// BUILD QUERY — join matches to sessions via sessions.id (not batch_id)
$whereClause = "WHERE m.group_id = ?";
$params = [$group_id];

if (!$isGlobal) {
    $whereClause .= " AND s.user_id = ?";
    $params[] = $user_id;
}

if ($batch_id !== 'all') {
    $whereClause .= " AND m.session_id = ?";
    $params[] = $batch_id;
}

$sql = "SELECT m.*, s.user_id as session_user_id, s.title as session_title,
               UNIX_TIMESTAMP(s.session_date) as session_timestamp
        FROM matches m
        LEFT JOIN sessions s ON m.session_id = s.id
        $whereClause
        ORDER BY m.round_num ASC, m.court_num ASC";

$rawHistory = dbGetAll($sql, $params);

// Deduplicate by match id (safety net against JOIN producing duplicate rows)
$seen = [];
$history = [];
foreach ($rawHistory as $row) {
    $mid = $row['id'];
    if (isset($seen[$mid])) continue;
    $seen[$mid] = true;
    $history[] = $row;
}

// Add ownership + map keys
foreach ($history as &$match) {
    $match['isYours'] = (strval($match['session_user_id'] ?? '') === strval($user_id));
    $match['p1'] = $match['p1_key'] ?? '';
    $match['p2'] = $match['p2_key'] ?? '';
    $match['p3'] = $match['p3_key'] ?? '';
    $match['p4'] = $match['p4_key'] ?? '';
    $match['match_id'] = $match['id'];
}
unset($match); // CRITICAL: break the reference before next foreach

// Build leaderboard from match data
$stats = [];
foreach ($history as $match) {
    $players_in_match = [
        ['name' => $match['p1_name'], 'team' => 1],
        ['name' => $match['p2_name'], 'team' => 1],
        ['name' => $match['p3_name'], 'team' => 2],
        ['name' => $match['p4_name'], 'team' => 2],
    ];
    
    $s1 = (int)$match['s1'];
    $s2 = (int)$match['s2'];
    if ($s1 === 0 && $s2 === 0) continue;
    
    foreach ($players_in_match as $p) {
        $name = $p['name'];
        if (!$name || $name === 'Unknown') continue;
        if (!isset($stats[$name])) {
            $stats[$name] = ['w' => 0, 'l' => 0, 'pf' => 0, 'pa' => 0, 'games' => 0, 'got_zero' => false];
        }
        
        $stats[$name]['games']++;
        
        if ($p['team'] === 1) {
            $stats[$name]['pf'] += $s1;
            $stats[$name]['pa'] += $s2;
            if ($s1 > $s2) $stats[$name]['w']++;
            else $stats[$name]['l']++;
            if ($s1 === 0) $stats[$name]['got_zero'] = true;
        } else {
            $stats[$name]['pf'] += $s2;
            $stats[$name]['pa'] += $s1;
            if ($s2 > $s1) $stats[$name]['w']++;
            else $stats[$name]['l']++;
            if ($s2 === 0) $stats[$name]['got_zero'] = true;
        }
    }
}

// Build leaderboard with badges
$leaderboard = [];
foreach ($stats as $name => $s) {
    $total = $s['w'] + $s['l'];
    $pct = $total > 0 ? round(($s['w'] / $total) * 100) : 0;
    $diff = $s['pf'] - $s['pa'];
    
    // BADGE LOGIC (mutually exclusive, except turkey combines with any):
    // 💠 Platinum = won ALL games (100%) — blue diamond emoji for platinum
    // 💎 Diamond = lost exactly 1 game
    // 🔥 Fire = won 3+ games
    // 🦃 Turkey = got scored 0 in any game (COMBINABLE with any above)
    //
    // Priority: Platinum > Diamond > Fire (only ONE of these, plus optional turkey)
    $badges = [];
    
    if ($total > 0) {
        if ($s['w'] === $total) {
            $badges[] = '💠'; // Platinum - perfect record (blue diamond)
        } elseif ($s['l'] === 1) {
            $badges[] = '💎'; // Diamond - lost exactly one
        } elseif ($s['w'] >= 3) {
            $badges[] = '🔥'; // Fire - 3+ wins
        }
        
        // Turkey combinable with any badge above
        if ($s['got_zero']) {
            $badges[] = '🦃'; // Turkey - got pickled
        }
    }
    
    $leaderboard[] = [
        'id' => $name,
        'name' => $name,
        'w' => $s['w'],
        'l' => $s['l'],
        'pct' => $pct,
        'diff' => $diff,
        'pf' => $s['pf'],
        'pa' => $s['pa'],
        'badges' => $badges
    ];
}

// Sort by win pct desc, then diff desc
usort($leaderboard, function($a, $b) {
    if ($b['pct'] !== $a['pct']) return $b['pct'] - $a['pct'];
    return $b['diff'] - $a['diff'];
});

// Get roster from memberships (universal players) — include DUPR
$roster = dbGetAll(
    "SELECT p.player_key as id, p.first_name as name, p.dupr_rating
     FROM players p
     INNER JOIN player_group_memberships pgm ON p.id = pgm.player_id
     WHERE pgm.group_id = ?",
    [$group_id]
);

// Build name-to-DUPR lookup for leaderboard enrichment
$duprLookup = [];
foreach ($roster as $r) {
    if (!empty($r['dupr_rating'])) {
        $duprLookup[$r['name']] = floatval($r['dupr_rating']);
    }
}

// Enrich leaderboard with DUPR ratings
foreach ($leaderboard as &$entry) {
    $entry['dupr'] = $duprLookup[$entry['name']] ?? null;
}
unset($entry);

echo json_encode([
    'status' => 'success',
    'leaderboard' => $leaderboard,
    'history' => $history,
    'roster' => $roster
]);
