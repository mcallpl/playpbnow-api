<?php
// ============================================
// mark_not_duplicate.php — Mark two players as NOT the same person
// This prevents the duplicate banner from flagging them again.
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$player_id_1 = (int)($input['player_id_1'] ?? 0);
$player_id_2 = (int)($input['player_id_2'] ?? 0);

if (!$player_id_1 || !$player_id_2 || $player_id_1 === $player_id_2) {
    echo json_encode(['status' => 'error', 'message' => 'Two different player IDs required']);
    exit;
}

// Always store with smaller ID first for consistency
$id1 = min($player_id_1, $player_id_2);
$id2 = max($player_id_1, $player_id_2);

try {
    // Auto-create table if it doesn't exist
    $conn = getDBConnection();
    $conn->query("CREATE TABLE IF NOT EXISTS player_not_duplicates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        player_id_1 INT NOT NULL,
        player_id_2 INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_pair (player_id_1, player_id_2)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->close();

    // Check if already marked
    $existing = dbGetRow(
        "SELECT id FROM player_not_duplicates WHERE player_id_1 = ? AND player_id_2 = ?",
        [$id1, $id2]
    );

    if ($existing) {
        echo json_encode(['status' => 'success', 'message' => 'Already marked as different players']);
        exit;
    }

    dbInsert(
        "INSERT INTO player_not_duplicates (player_id_1, player_id_2) VALUES (?, ?)",
        [$id1, $id2]
    );

    echo json_encode(['status' => 'success', 'message' => 'Players marked as different people']);

} catch (Exception $e) {
    error_log("❌ Mark not duplicate error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
