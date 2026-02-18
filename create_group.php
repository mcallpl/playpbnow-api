<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$name     = trim($input['name'] ?? '');
$user_id  = $input['user_id'] ?? '';
$court_id = isset($input['court_id']) ? (int)$input['court_id'] : null;

if (empty($name))    { echo json_encode(['status' => 'error', 'message' => 'Group name required']); exit; }
if (empty($user_id)) { echo json_encode(['status' => 'error', 'message' => 'User ID required']); exit; }

try {
    $existing = dbGetRow(
        "SELECT * FROM `groups` WHERE name = ? AND owner_user_id = ?",
        [$name, $user_id]
    );
    
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
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Group already exists - updated',
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
            'status' => 'success', 'message' => 'Group already exists',
            'group' => ['id' => $existing['id'], 'name' => $existing['name'], 'group_key' => $existing['group_key'], 'court_id' => $existing['court_id']]
        ]);
    } else {
        error_log("❌ Create group error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
