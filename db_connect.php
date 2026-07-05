<?php
// ============================================
// db_connect.php — PlayPBNow host bootstrap for the SHARED beacon module
// (served at playpbnow.com/shared/beacon/api; the module's _config.php
// requires this file via its default ../../../api/db_connect.php path).
//
// The shared casual-beacon feed lives in the cross-app `dink` database —
// both DinkConnections and PlayPBNow read/write the same beacons there, so
// this connection deliberately targets that DB (NOT `playpbnow`, whose own
// `beacons` table is the separate structured-lobby feature).
//
// PlayPBNow gates beacon creation on its own side, so the module's
// DinkConnections membership-tier check is skipped. No session_validation.php
// is loaded here, so the module's auth helpers run in legacy user_id mode
// (the module's documented behavior for host apps without session tokens).
// ============================================

error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('America/Los_Angeles');

// PlayPBNow manages its own access gating — skip the DinkConnections tier check.
if (!defined('BEACON_SKIP_TIER_CHECK')) {
    define('BEACON_SKIP_TIER_CHECK', true);
}

// Load shared vault (same one db_config.php uses).
$vault_paths = [
    __DIR__ . '/../vault/secrets.php',      // server: /var/www/html/vault
    __DIR__ . '/../../vault/secrets.php',   // local dev layout
];
foreach ($vault_paths as $vp) {
    if (file_exists($vp)) { require_once $vp; break; }
}

$db_host = isset($vault_db_host) ? $vault_db_host : 'localhost';
$db_user = isset($vault_db_user) ? $vault_db_user : 'mcallpl';
$db_pass = isset($vault_db_pass) ? $vault_db_pass : 'amazing123';
$db_name = 'dink'; // shared cross-app beacon DB — see header comment

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');
