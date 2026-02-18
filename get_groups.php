<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$user_id = $_GET['user_id'] ?? '';
if (empty($user_id)) { echo json_encode(['status' => 'error', 'message' => 'User ID required']); exit; }

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
    GROUP BY g.id
    ORDER BY g.updated_at DESC",
    [$user_id]
);

echo json_encode(['status' => 'success', 'groups' => $groups]);
