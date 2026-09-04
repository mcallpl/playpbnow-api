<?php
// ============================================
// get_not_duplicates.php — Get all player pairs marked as NOT duplicates
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// Audit E H6: user_id from the query string was an IDOR. READ endpoint —
// prefer the session token, tolerate the param for older clients.
$user_id = pbnow_optional_user_id();
if ($user_id === null) {
    $user_id = $_GET['user_id'] ?? '';
}

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

    // Get all not-duplicate pairs for players belonging to this user's groups.
    // Original query joined the legacy players.group_id column; kept, and
    // UNIONed with the membership-table route so players who only have a
    // player_group_memberships row (the current model) are included too.
    $pairs = dbGetAll(
        "SELECT DISTINCT pnd.player_id_1, pnd.player_id_2
         FROM player_not_duplicates pnd
         INNER JOIN players p1 ON pnd.player_id_1 = p1.id
         INNER JOIN `groups` g1 ON p1.group_id = g1.id
         WHERE g1.owner_user_id = ?
         UNION
         SELECT DISTINCT pnd.player_id_1, pnd.player_id_2
         FROM player_not_duplicates pnd
         INNER JOIN player_group_memberships pgm ON pgm.player_id = pnd.player_id_1
         INNER JOIN `groups` g2 ON pgm.group_id = g2.id
         WHERE g2.owner_user_id = ?
         UNION
         SELECT DISTINCT pnd.player_id_1, pnd.player_id_2
         FROM player_not_duplicates pnd
         INNER JOIN players p3 ON pnd.player_id_1 = p3.id
         WHERE p3.created_by_user_id = ?",
        [$user_id, $user_id, $user_id]
    );

    echo json_encode([
        'status' => 'success',
        'pairs' => $pairs
    ]);

} catch (Exception $e) {
    error_log("❌ Get not duplicates error: " . $e->getMessage());
    echo json_encode(['status' => 'success', 'pairs' => []]);
}
