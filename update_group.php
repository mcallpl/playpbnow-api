<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);

$group_key = $input['group_key'] ?? '';
$new_name = $input['new_name'] ?? '';
$user_id = $input['user_id'] ?? '';

if (empty($group_key) || empty($new_name) || empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required data']);
    exit;
}

try {
    // Verify ownership
    $group = dbGetRow(
        "SELECT id FROM `groups` WHERE group_key = ? AND owner_user_id = ?",
        [$group_key, $user_id]
    );
    
    if (!$group) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Group not found or you do not have permission to edit it'
        ]);
        exit;
    }
    
    // Update group name
    dbQuery(
        "UPDATE `groups` SET name = ?, updated_at = NOW() WHERE group_key = ? AND owner_user_id = ?",
        [$new_name, $group_key, $user_id]
    );
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Group updated successfully'
    ]);
} catch (Exception $e) {
    error_log("Error updating group: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to update group'
    ]);
}
?>
