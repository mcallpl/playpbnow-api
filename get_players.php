<?php
// ============================================
// get_players.php V2 — Returns stats + home_court
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$group_key = $_GET['group_key'] ?? '';

if (empty($group_key)) {
    echo json_encode(['status' => 'error', 'message' => 'Group key required']);
    exit;
}

try {
    $group = dbGetRow("SELECT id FROM `groups` WHERE group_key = ?", [$group_key]);
    if (!$group) { echo json_encode(['status' => 'error', 'message' => 'Group not found']); exit; }
    $group_id = $group['id'];

    $players = dbGetAll(
        "SELECT p.*, pgm.order_index, p.player_key, c.name as home_court_name
         FROM players p
         INNER JOIN player_group_memberships pgm ON p.id = pgm.player_id
         LEFT JOIN courts c ON p.home_court_id = c.id
         WHERE pgm.group_id = ?
         ORDER BY pgm.order_index ASC",
        [$group_id]
    );

    $formatted_players = array_map(function($p) {
        return [
            'id' => (string)$p['player_key'],
            'first_name' => $p['first_name'],
            'last_name' => $p['last_name'] ?? '',
            'gender' => $p['gender'],
            'home_court_id' => $p['home_court_id'],
            'home_court_name' => $p['home_court_name'] ?? null,
            'wins' => (int)$p['wins'],
            'losses' => (int)$p['losses'],
            'diff' => (int)$p['diff'],
            'win_pct' => (float)$p['win_pct'],
            'order_index' => (int)$p['order_index']
        ];
    }, $players);

    error_log("✅ Loaded " . count($formatted_players) . " players for group $group_key");

    echo json_encode([
        'status' => 'success',
        'players' => $formatted_players
    ]);

} catch (Exception $e) {
    error_log("❌ Get players error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
