<?php
// ============================================
// purge_data.php — CLEAN SLATE
// Purges ALL tables EXCEPT players and courts
// Access: yoursite.com/Chipleball/api/purge_data.php?confirm=YES
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://peoplestar.com');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// Destructive: POST + authenticated admin only (no GET trigger).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}
require_admin();

// Safety: require explicit confirmation in the POST body
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$confirm = $input['confirm'] ?? '';

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
    // Use hardcoded table names to prevent SQL injection - no user input allowed
    if (!in_array($table, [
        'collab_score_updates', 'collab_participants', 'collab_sessions', 'round_byes',
        'matches', 'reports', 'sessions', 'player_group_memberships', 'feature_access',
        'payment_transactions', 'subscriptions', 'sms_verifications', 'verification_codes',
        'user_sessions', 'groups'
    ])) {
        $results[$table] = "Invalid table name — skipped for security";
        continue;
    }

    // Use prepared statement with INFORMATION_SCHEMA to check table existence
    $tableCheck = dbGetRow(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );

    if ($tableCheck) {
        // Count rows before truncate
        $countRow = dbGetRow("SELECT COUNT(*) as cnt FROM " . $conn->real_escape_string($table), []);
        $count = $countRow['cnt'] ?? 0;
        // Truncate is safe when table name is from hardcoded whitelist
        $conn->query("TRUNCATE TABLE " . $conn->real_escape_string($table));
        $results[$table] = "Purged ($count rows)";
    } else {
        $results[$table] = "Table not found — skipped";
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");
$conn->close();

// Show what was preserved
$playerRow = dbGetRow("SELECT COUNT(*) as cnt FROM players", []);
$playerCount = $playerRow['cnt'] ?? 0;
$courtRow = dbGetRow("SELECT COUNT(*) as cnt FROM courts", []);
$courtCount = $courtRow['cnt'] ?? 0;
$userRow = dbGetRow("SELECT COUNT(*) as cnt FROM users", []);
$userCount = $userRow['cnt'] ?? 0;

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
