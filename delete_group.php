<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$group_key = $input['group_key'] ?? '';
$user_id = $input['user_id'] ?? '';

if (empty($group_key)) {
    echo json_encode(['status' => 'error', 'message' => 'Group key required']);
    exit;
}

// Get group ID
$group = dbGetRow("SELECT id, owner_user_id FROM `groups` WHERE group_key = ?", [$group_key]);

if (!$group) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}

$group_id = $group['id'];

// Verify ownership if user_id provided
if ($user_id && $group['owner_user_id'] != $user_id) {
    echo json_encode(['status' => 'error', 'message' => 'Not authorized to delete this group']);
    exit;
}

try {
    // ONLY delete the group record itself
    // Keep all players, matches, sessions, and byes for historical records
    dbQuery("DELETE FROM `groups` WHERE id = ?", [$group_id]);
    
    error_log("🗑️ Deleted group (keeping all data): {$group_key}");
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Group deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("❌ Delete error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
