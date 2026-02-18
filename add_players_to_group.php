<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$player_ids = $input['player_ids'] ?? [];
$group_id = $input['group_id'] ?? '';

if (empty($player_ids) || empty($group_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Player IDs and group ID required']);
    exit;
}

try {
    $added_count = 0;
    $skipped_count = 0;
    
    foreach ($player_ids as $player_id) {
        // Check if membership already exists
        $existing = dbGetRow(
            "SELECT id FROM player_group_memberships 
             WHERE player_id = ? AND group_id = ?",
            [$player_id, $group_id]
        );
        
        if ($existing) {
            $skipped_count++;
            continue;
        }
        
        // Get next order_index
        $max_order = dbGetRow(
            "SELECT COALESCE(MAX(order_index), -1) as max_order 
             FROM player_group_memberships 
             WHERE group_id = ?",
            [$group_id]
        );
        
        $next_order = $max_order['max_order'] + 1;
        
        // Add membership
        dbInsert(
            "INSERT INTO player_group_memberships (player_id, group_id, order_index, joined_at) 
             VALUES (?, ?, ?, NOW())",
            [$player_id, $group_id, $next_order]
        );
        
        $added_count++;
    }
    
    error_log("✅ Added $added_count players to group $group_id ($skipped_count already existed)");
    
    echo json_encode([
        'status' => 'success',
        'message' => "Added $added_count player(s) to group",
        'added_count' => $added_count,
        'skipped_count' => $skipped_count
    ]);
    
} catch (Exception $e) {
    error_log("❌ Add players to group error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
