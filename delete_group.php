<?php
header('Content-Type: application/json');
// Served same-origin from playpbnow.com; identity is the Bearer token.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$group_key = $input['group_key'] ?? '';
$user_id = $input['user_id'] ?? '';

// DELETE endpoint: a session token, when present, is the identity and the
// body user_id is ignored. Older clients without a token still go through
// the body user_id + ownership check below.
$session_uid = pbnow_optional_user_id();
if ($session_uid !== null) {
    $user_id = $session_uid;
}

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
    // Audit E M1: SOFT delete when the schema supports it (migration_001 added
    // groups._deleted_at). The row stays, so sessions/matches keep a valid
    // group_id and the history is recoverable; get_groups / get_leaderboard /
    // save_scores all filter _deleted_at IS NULL so it disappears from the
    // app. If the column is missing the original hard delete still runs.
    $softDeleted = false;
    try {
        $conn = getDBConnection();
        $colCheck = $conn->query("SHOW COLUMNS FROM `groups` LIKE '_deleted_at'");
        $hasSoftDelete = ($colCheck && $colCheck->num_rows > 0);
        if ($hasSoftDelete) {
            $stmt = $conn->prepare("UPDATE `groups` SET _deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND _deleted_at IS NULL");
            $gid = (int)$group_id;
            $stmt->bind_param('i', $gid);
            $softDeleted = $stmt->execute();
            $stmt->close();
        }
        $conn->close();
    } catch (Throwable $softErr) {
        error_log("delete_group soft-delete unavailable, falling back to hard delete: " . $softErr->getMessage());
        $softDeleted = false;
    }

    if (!$softDeleted) {
        // ONLY delete the group record itself
        // Keep all players, matches, sessions, and byes for historical records
        dbQuery("DELETE FROM `groups` WHERE id = ?", [$group_id]);
    }

    error_log(($softDeleted ? "🗑️ Soft-deleted group (keeping all data): " : "🗑️ Deleted group (keeping all data): ") . $group_key);

    echo json_encode([
        'status' => 'success',
        'message' => 'Group deleted successfully',
        'soft_deleted' => (bool)$softDeleted
    ]);
    
} catch (Exception $e) {
    error_log("❌ Delete error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
