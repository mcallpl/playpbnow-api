<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://peoplestar.com');
require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$group_key = $input['group_key'] ?? '';
$user_id = $input['user_id'] ?? '';

if (empty($group_key)) {
    echo json_encode(['status' => 'error', 'message' => 'Group key required']);
    exit;
}

// user_id is REQUIRED — previously the ownership check was skipped when it was
// absent, letting anyone delete any group just by omitting user_id.
if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}

// Get group ID
$group = dbGetRow("SELECT id, owner_user_id FROM `groups` WHERE group_key = ?", [$group_key]);

if (!$group) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}

$group_id = $group['id'];

// Enforce ownership. Primary check: owner_user_id column. Fallback for legacy
// groups where owner_user_id is NULL: group_key is formatted
// "group_<timestamp>_<creatorUserId>", so the trailing segment identifies the
// creator. This keeps legacy groups deletable by their real owner while still
// blocking the previous bypass (omitting user_id / unowned groups).
$authorized = false;
if (!empty($group['owner_user_id'])) {
    $authorized = ((string) $group['owner_user_id'] === (string) $user_id);
} else {
    $parts = explode('_', $group_key);
    $keyOwner = end($parts);
    $authorized = ($keyOwner !== '' && (string) $keyOwner === (string) $user_id);
}
if (!$authorized) {
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
