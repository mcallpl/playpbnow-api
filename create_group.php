<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$name     = trim($input['name'] ?? '');
$user_id  = $input['user_id'] ?? '';
$court_id = isset($input['court_id']) ? (int)$input['court_id'] : null;

// WRITE endpoint: a session token, when present, is the identity; the body
// user_id is only honoured for older clients that send no token.
$session_uid = pbnow_optional_user_id();
if ($session_uid !== null) {
    $user_id = $session_uid;
}

if (empty($name))    { echo json_encode(['status' => 'error', 'message' => 'Group name required']); exit; }
if (empty($user_id)) { echo json_encode(['status' => 'error', 'message' => 'User ID required']); exit; }

try {
    // Only live groups count as "existing"; a soft-deleted group with the same
    // name must not be silently resurrected (delete_group.php soft-deletes).
    $existing = dbGetRow(
        "SELECT * FROM `groups` WHERE name = ? AND owner_user_id = ? AND _deleted_at IS NULL ORDER BY id ASC LIMIT 1",
        [$name, $user_id]
    );
    if (!$existing) {
        // Fallback in case _deleted_at does not exist on this schema.
        $existing = dbGetRow(
            "SELECT * FROM `groups` WHERE name = ? AND owner_user_id = ? ORDER BY id ASC LIMIT 1",
            [$name, $user_id]
        );
        if ($existing && array_key_exists('_deleted_at', $existing) && $existing['_deleted_at'] !== null) {
            $existing = null;   // soft-deleted — treat as not existing
        }
    }

    if ($existing) {
        // Update existing — also update court_id if provided
        $conn = getDBConnection();
        if ($court_id) {
            $stmt = $conn->prepare("UPDATE `groups` SET court_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ii', $court_id, $existing['id']);
        } else {
            $stmt = $conn->prepare("UPDATE `groups` SET updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $existing['id']);
        }
        $stmt->execute();
        $stmt->close();
        $conn->close();

        // Audit E M2: the name already exists for this owner. Say so with
        // status 'exists' + a message so the app can tell the organizer,
        // but STILL return the group so older clients keep working.
        echo json_encode([
            'status' => 'exists',
            'exists' => true,
            'message' => "You already have a group called \"{$name}\". Opening it instead.",
            'group' => [
                'id' => $existing['id'],
                'name' => $existing['name'],
                'group_key' => $existing['group_key'],
                'owner_user_id' => $existing['owner_user_id'],
                'court_id' => $court_id ?: $existing['court_id']
            ]
        ]);
    } else {
        $group_key = "group_" . time() . "_" . $user_id;
        
        $group_id = dbInsert(
            "INSERT INTO `groups` (name, group_key, owner_user_id, court_id, device_id, created_at, updated_at) 
             VALUES (?, ?, ?, ?, '', NOW(), NOW())",
            [$name, $group_key, $user_id, $court_id]
        );
        
        error_log("✅ Created group: {$name} (court: $court_id) for user {$user_id}");
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Group created successfully',
            'group' => [
                'id' => $group_id,
                'name' => $name,
                'group_key' => $group_key,
                'owner_user_id' => $user_id,
                'court_id' => $court_id
            ]
        ]);
    }
    
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        $existing = dbGetRow("SELECT * FROM `groups` WHERE name = ? AND owner_user_id = ?", [$name, $user_id]);
        echo json_encode([
            'status' => 'exists', 'exists' => true,
            'message' => "You already have a group called \"{$name}\". Opening it instead.",
            'group' => ['id' => $existing['id'], 'name' => $existing['name'], 'group_key' => $existing['group_key'], 'court_id' => $existing['court_id']]
        ]);
    } else {
        error_log("❌ Create group error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
