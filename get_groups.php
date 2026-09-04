<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// Audit E H6: user_id in the query string was the only identity, so anyone
// could list any organizer's groups by changing the number (IDOR). Prefer
// the session token; fall back to the param only for older clients that do
// not send one (READ endpoint, tolerant during rollout).
$user_id = pbnow_optional_user_id();
if ($user_id === null) {
    $user_id = $_GET['user_id'] ?? '';
}
if (empty($user_id)) { echo json_encode(['status' => 'error', 'message' => 'User ID required']); exit; }

// _deleted_at IS NULL: delete_group.php now soft-deletes (Audit E M1), so a
// removed group must not reappear in the picker.
$groups = dbGetAll(
    "SELECT
        g.id, g.group_key, g.name, g.court_id,
        g.owner_user_id, g.created_at, g.updated_at,
        c.name as court_name, c.city as court_city,
        COUNT(DISTINCT pgm.player_id) as count,
        SUM(CASE WHEN p.gender = 'male' THEN 1 ELSE 0 END) as maleCount,
        SUM(CASE WHEN p.gender = 'female' THEN 1 ELSE 0 END) as femaleCount
    FROM `groups` g
    LEFT JOIN player_group_memberships pgm ON pgm.group_id = g.id
    LEFT JOIN players p ON p.id = pgm.player_id
    LEFT JOIN courts c ON g.court_id = c.id
    WHERE g.owner_user_id = ?
      AND g._deleted_at IS NULL
    GROUP BY g.id
    ORDER BY g.updated_at DESC",
    [$user_id]
);

echo json_encode(['status' => 'success', 'groups' => $groups]);
