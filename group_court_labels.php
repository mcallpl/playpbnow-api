<?php
// ============================================================
// group_court_labels.php: the court names a group used last (2026-10-31).
//
// A group that plays at the same place every week is usually on the same
// courts. When the host names the courts for a match, the app saves them
// here; the next match for that group OFFERS them again ("Use CRT D, E, F
// again?"). The app never applies them by itself.
//
//   GET  ?group_key=...                       -> { status, labels: [...] }
//   POST { group_key, labels: [...] }         -> { status, labels: [...] }
//        labels: [] forgets them.
//
// Owner only, by Bearer session. A group the caller does not own answers
// with an empty list, never an error the screen has to explain.
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$user_id = pbnow_require_session_user();

dbQuery(
    "CREATE TABLE IF NOT EXISTS group_court_labels (
        group_id INT NOT NULL PRIMARY KEY,
        labels_json TEXT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
}
$group_key = (string)($input['group_key'] ?? ($_GET['group_key'] ?? ''));
if ($group_key === '') {
    echo json_encode(['status' => 'error', 'message' => 'Missing group']);
    exit;
}

// Owner-scoped, never by name (CLAUDE.md: group lookups are owner-scoped).
$group = dbGetRow(
    "SELECT id FROM `groups` WHERE group_key = ? AND owner_user_id = ? AND `_deleted_at` IS NULL",
    [$group_key, $user_id]
);
if (!$group) {
    echo json_encode(['status' => 'success', 'labels' => []]);
    exit;
}
$group_id = (int)$group['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $labels = [];
    foreach ((array)($input['labels'] ?? []) as $l) {
        $l = trim(mb_substr((string)$l, 0, 8));
        $labels[] = $l;
        if (count($labels) >= 64) break;
    }
    if (count(array_filter($labels, fn($l) => $l !== '')) === 0) {
        dbQuery("DELETE FROM group_court_labels WHERE group_id = ?", [$group_id]);
        echo json_encode(['status' => 'success', 'labels' => []]);
        exit;
    }
    dbQuery(
        "INSERT INTO group_court_labels (group_id, labels_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE labels_json = VALUES(labels_json)",
        [$group_id, json_encode(array_values($labels))]
    );
    echo json_encode(['status' => 'success', 'labels' => $labels]);
    exit;
}

$row = dbGetRow("SELECT labels_json FROM group_court_labels WHERE group_id = ?", [$group_id]);
$labels = $row ? json_decode($row['labels_json'], true) : [];
echo json_encode(['status' => 'success', 'labels' => is_array($labels) ? array_values($labels) : []]);
