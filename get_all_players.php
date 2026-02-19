<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$user_id = $_GET['user_id'] ?? '';

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

try {
    // Get ALL unique players for this user across ALL their groups
    // Include group names for better duplicate identification
    $players = dbGetAll(
        "SELECT DISTINCT p.*,
                GROUP_CONCAT(DISTINCT g2.name ORDER BY g2.name SEPARATOR ', ') as group_names
         FROM players p
         INNER JOIN `groups` g ON p.group_id = g.id
         LEFT JOIN player_group_memberships pgm ON p.id = pgm.player_id
         LEFT JOIN `groups` g2 ON pgm.group_id = g2.id
         WHERE g.owner_user_id = ?
         GROUP BY p.id
         ORDER BY p.first_name ASC",
        [$user_id]
    );
    
    error_log("✅ Loaded " . count($players) . " total players for user $user_id");
    
    echo json_encode([
        'status' => 'success',
        'players' => $players
    ]);
    
} catch (Exception $e) {
    error_log("❌ Get all players error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
