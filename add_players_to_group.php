<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// Audit E H6: this endpoint had NO identity at all — anyone could add any
// player id to any group id. WRITE endpoint: the session user is required
// and must own the target group.
$auth_uid = pbnow_require_session_user();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$player_ids = $input['player_ids'] ?? [];
$group_id = $input['group_id'] ?? '';

if (empty($player_ids) || empty($group_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Player IDs and group ID required']);
    exit;
}
if (!is_array($player_ids)) $player_ids = [$player_ids];

// Resolve the group: numeric id, or group_key for clients that send that.
$groupRow = null;
if (ctype_digit((string)$group_id)) {
    $groupRow = dbGetRow("SELECT id, owner_user_id FROM `groups` WHERE id = ?", [(int)$group_id]);
}
if (!$groupRow) {
    $groupRow = dbGetRow("SELECT id, owner_user_id FROM `groups` WHERE group_key = ?", [(string)$group_id]);
}
if (!$groupRow) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}
// Owner check. Legacy groups with no owner: accept when the trailing segment
// of the group_key is the caller (same rule delete_group.php uses); otherwise
// only admins may touch them.
$ownerOk = false;
if (!empty($groupRow['owner_user_id'])) {
    $ownerOk = ((string)$groupRow['owner_user_id'] === (string)$auth_uid);
} else {
    $ownerOk = pbnow_is_admin($auth_uid);
}
if (!$ownerOk) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You can only add players to your own groups']);
    exit;
}
$group_id = (int)$groupRow['id'];

try {
    $added_count = 0;
    $skipped_count = 0;

    foreach ($player_ids as $player_id) {
        $player_id = (int)$player_id;
        if ($player_id <= 0) { $skipped_count++; continue; }

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
