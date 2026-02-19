<?php
// ============================================
// get_not_duplicates.php — Get all player pairs marked as NOT duplicates
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$user_id = $_GET['user_id'] ?? '';

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

try {
    // Check if table exists first
    $conn = getDBConnection();
    $tableCheck = $conn->query("SHOW TABLES LIKE 'player_not_duplicates'");
    if ($tableCheck->num_rows === 0) {
        // Table doesn't exist yet — return empty
        $conn->close();
        echo json_encode(['status' => 'success', 'pairs' => []]);
        exit;
    }
    $conn->close();

    // Get all not-duplicate pairs for players belonging to this user's groups
    $pairs = dbGetAll(
        "SELECT DISTINCT pnd.player_id_1, pnd.player_id_2
         FROM player_not_duplicates pnd
         INNER JOIN players p1 ON pnd.player_id_1 = p1.id
         INNER JOIN `groups` g1 ON p1.group_id = g1.id
         WHERE g1.owner_user_id = ?",
        [$user_id]
    );

    echo json_encode([
        'status' => 'success',
        'pairs' => $pairs
    ]);

} catch (Exception $e) {
    error_log("❌ Get not duplicates error: " . $e->getMessage());
    echo json_encode(['status' => 'success', 'pairs' => []]);
}
