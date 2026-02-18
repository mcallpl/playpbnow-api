<?php
// ============================================
// search_players.php V2 — GLOBAL PLAYER SEARCH
// Searches ALL players across all groups/courts
// Returns player info + stats + home court + groups they belong to
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$query = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 20), 50);

if (strlen($query) < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Search query too short']);
    exit;
}

try {
    $searchTerm = "%{$query}%";
    
    $players = dbGetAll(
        "SELECT p.id, p.player_key, p.first_name, p.last_name, p.gender,
                p.cell_phone, p.is_verified, p.home_court_id,
                p.wins, p.losses, p.diff, p.win_pct,
                c.name as home_court_name, c.city as home_court_city
         FROM players p
         LEFT JOIN courts c ON p.home_court_id = c.id
         WHERE p.first_name LIKE ? OR p.last_name LIKE ? OR p.cell_phone LIKE ?
         ORDER BY p.first_name ASC
         LIMIT ?",
        [$searchTerm, $searchTerm, $searchTerm, $limit]
    );
    
    // For each player, get their group memberships
    $results = [];
    foreach ($players as $p) {
        $groups = dbGetAll(
            "SELECT g.name as group_name, g.group_key, c.name as court_name
             FROM player_group_memberships pgm
             INNER JOIN `groups` g ON pgm.group_id = g.id
             LEFT JOIN courts c ON g.court_id = c.id
             WHERE pgm.player_id = ?",
            [$p['id']]
        );
        
        $groupNames = array_map(function($g) { return $g['group_name']; }, $groups);
        
        $results[] = [
            'id' => (int)$p['id'],
            'player_key' => $p['player_key'],
            'first_name' => $p['first_name'],
            'last_name' => $p['last_name'] ?? '',
            'gender' => $p['gender'],
            'cell_phone' => $p['cell_phone'] ?? '',
            'is_verified' => (bool)$p['is_verified'],
            'home_court_id' => $p['home_court_id'],
            'home_court_name' => $p['home_court_name'],
            'home_court_city' => $p['home_court_city'],
            'wins' => (int)$p['wins'],
            'losses' => (int)$p['losses'],
            'diff' => (int)$p['diff'],
            'win_pct' => (float)$p['win_pct'],
            'groups' => $groupNames,
            'source' => $p['home_court_name'] ?? 'No court'
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'results' => $results,
        'count' => count($results)
    ]);
    
} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
