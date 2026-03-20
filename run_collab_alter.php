<?php
// ============================================
// run_collab_alter.php
// One-time migration: fixes collaboration schema
// DELETE THIS FILE after running once!
// ============================================

header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$conn = getDBConnection();
$results = [];

$statements = [
    "ALTER TABLE `collab_sessions` MODIFY COLUMN `status` enum('active','finished','expired','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active'",
    "ALTER TABLE `collab_score_updates` ADD COLUMN IF NOT EXISTS `s1_str` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
    "ALTER TABLE `collab_score_updates` ADD COLUMN IF NOT EXISTS `s2_str` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
    "UPDATE `collab_sessions` SET `status` = 'finished' WHERE `status` = ''",
    "ALTER TABLE `collab_sessions` ADD COLUMN IF NOT EXISTS `creator_user_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''",
];

foreach ($statements as $sql) {
    $ok = $conn->query($sql);
    $results[] = [
        'sql' => substr($sql, 0, 80) . '...',
        'ok' => (bool)$ok,
        'error' => $ok ? null : $conn->error,
    ];
}

// Show current state
$sessions = [];
$res = $conn->query("SELECT id, share_code, status, created_at, expires_at FROM collab_sessions ORDER BY created_at DESC LIMIT 20");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $sessions[] = $row;
    }
}

echo json_encode([
    'migrations' => $results,
    'recent_sessions' => $sessions,
    'collab_sessions_count' => count($sessions),
], JSON_PRETTY_PRINT);
