<?php
// Quick diagnostic: show all players for a user with match counts
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$user_id = $_GET['user_id'] ?? '';
if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'user_id required']);
    exit;
}

$conn = getDBConnection();

// Get all players
$stmt = $conn->prepare(
    "SELECT p.id, p.player_key, p.first_name, p.last_name, p.cell_phone,
            p.wins, p.losses, p.diff, p.win_pct, p.dupr_rating, p.group_id
     FROM players p
     INNER JOIN `groups` g ON p.group_id = g.id
     WHERE g.owner_user_id = ?
     ORDER BY p.first_name ASC, p.id ASC"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

$players = [];
while ($row = $result->fetch_assoc()) {
    // Count actual matches for this player
    $pk = $conn->real_escape_string($row['player_key']);
    $matchCount = $conn->query(
        "SELECT COUNT(*) as cnt FROM matches
         WHERE p1_key = '$pk' OR p2_key = '$pk' OR p3_key = '$pk' OR p4_key = '$pk'"
    )->fetch_assoc()['cnt'];

    $row['actual_match_count'] = (int)$matchCount;
    $players[] = $row;
}
$stmt->close();

// Find duplicates by first_name
$nameCounts = [];
foreach ($players as $p) {
    $key = strtolower(trim($p['first_name']));
    $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
}
$duplicateNames = array_filter($nameCounts, fn($c) => $c > 1);

$conn->close();

echo json_encode([
    'status' => 'success',
    'total_players' => count($players),
    'duplicate_names' => $duplicateNames,
    'players' => $players
], JSON_PRETTY_PRINT);
?>
