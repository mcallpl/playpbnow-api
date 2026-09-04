<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$group_key = $input['group_key'] ?? '';
$new_name  = trim((string)($input['new_name'] ?? $input['name'] ?? ''));
$user_id   = $input['user_id'] ?? '';

// WRITE endpoint: when a session token is present it is the identity and the
// body user_id is ignored. Older clients without a token still work via the
// body value (tolerant rollout).
$session_uid = pbnow_optional_user_id();
if ($session_uid !== null) {
    $user_id = $session_uid;
}

// Audit E H2: the client sends court_id (groups.tsx:250) but it was dropped.
// null  = not sent (leave unchanged); 0 / '' = clear the court.
$court_provided = array_key_exists('court_id', $input);
$court_id = null;
if ($court_provided) {
    $court_id = ($input['court_id'] === null || $input['court_id'] === '' || (int)$input['court_id'] === 0)
        ? null
        : (int)$input['court_id'];
}

if (empty($group_key) || empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required data']);
    exit;
}
// A court-only update (no new name) is now allowed; a rename still needs a name.
if ($new_name === '' && !$court_provided) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required data']);
    exit;
}

try {
    // Verify ownership
    $group = dbGetRow(
        "SELECT id, name, court_id FROM `groups` WHERE group_key = ? AND owner_user_id = ?",
        [$group_key, $user_id]
    );

    if (!$group) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Group not found or you do not have permission to edit it'
        ]);
        exit;
    }

    // Update group name (original behaviour)
    if ($new_name !== '') {
        dbQuery(
            "UPDATE `groups` SET name = ?, updated_at = NOW() WHERE group_key = ? AND owner_user_id = ?",
            [$new_name, $group_key, $user_id]
        );
    }

    // Persist court_id when provided (Audit E H2).
    if ($court_provided) {
        $conn = getDBConnection();
        if ($court_id === null) {
            $stmt = $conn->prepare("UPDATE `groups` SET court_id = NULL, updated_at = NOW() WHERE group_key = ? AND owner_user_id = ?");
            $uidStr = (string)$user_id;
            $stmt->bind_param('ss', $group_key, $uidStr);
        } else {
            $stmt = $conn->prepare("UPDATE `groups` SET court_id = ?, updated_at = NOW() WHERE group_key = ? AND owner_user_id = ?");
            $uidStr = (string)$user_id;
            $stmt->bind_param('iss', $court_id, $group_key, $uidStr);
        }
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Group updated successfully',
        'group' => [
            'id' => (int)$group['id'],
            'group_key' => $group_key,
            'name' => $new_name !== '' ? $new_name : $group['name'],
            'court_id' => $court_provided ? $court_id : ($group['court_id'] !== null ? (int)$group['court_id'] : null),
        ]
    ]);
} catch (Exception $e) {
    error_log("Error updating group: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to update group'
    ]);
}
?>
