<?php
// ============================================
// purge_data.php — CLEAN SLATE
// Purges ALL tables EXCEPT players and courts
// Access: yoursite.com/Chipleball/api/purge_data.php?confirm=YES
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

// Safety: require explicit confirmation
$confirm = $_GET['confirm'] ?? '';

if ($confirm !== 'YES') {
    echo json_encode([
        'status' => 'warning',
        'message' => 'This will DELETE all data except players and courts. Add ?confirm=YES to the URL to proceed.',
        'tables_to_purge' => [
            'matches', 'sessions', 'groups', 'reports',
            'round_byes', 'collab_sessions', 'collab_participants', 'collab_score_updates',
            'sms_verifications', 'verification_codes', 'feature_access',
            'payment_transactions', 'subscriptions', 'player_group_memberships',
            'user_sessions'
        ],
        'tables_preserved' => ['players', 'courts', 'users']
    ]);
    exit;
}

$conn = getDBConnection();

// Order matters for foreign keys — children first
$tablesToPurge = [
    'collab_score_updates',
    'collab_participants',
    'collab_sessions',
    'round_byes',
    'matches',
    'reports',
    'sessions',
    'player_group_memberships',
    'feature_access',
    'payment_transactions',
    'subscriptions',
    'sms_verifications',
    'verification_codes',
    'user_sessions',
    'groups',
];

$results = [];
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

foreach ($tablesToPurge as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows > 0) {
        $countResult = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
        $count = $countResult->fetch_assoc()['cnt'];
        $conn->query("TRUNCATE TABLE `$table`");
        $results[$table] = "Purged ($count rows)";
    } else {
        $results[$table] = "Table not found — skipped";
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");
$conn->close();

// Show what was preserved
$conn2 = getDBConnection();
$playerCount = $conn2->query("SELECT COUNT(*) as cnt FROM players")->fetch_assoc()['cnt'];
$courtCount = $conn2->query("SELECT COUNT(*) as cnt FROM courts")->fetch_assoc()['cnt'];
$userCount = $conn2->query("SELECT COUNT(*) as cnt FROM users")->fetch_assoc()['cnt'];
$conn2->close();

echo json_encode([
    'status' => 'success',
    'message' => 'All data purged except players, courts, and users.',
    'purged' => $results,
    'preserved' => [
        'players' => "$playerCount rows kept",
        'courts' => "$courtCount rows kept",
        'users' => "$userCount rows kept"
    ]
], JSON_PRETTY_PRINT);
