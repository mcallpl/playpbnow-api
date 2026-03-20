<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

$users = dbGetAll("SELECT id, email, first_name, subscription_status, subscription_tier, subscription_end_date, trial_start_date FROM users ORDER BY id DESC LIMIT 10");
$groups = dbGetAll("SELECT id, name, owner_user_id FROM `groups` ORDER BY id DESC LIMIT 20");
echo json_encode(['users' => $users, 'groups' => $groups], JSON_PRETTY_PRINT);
