<?php
// ============================================================
// logout.php — revoke the caller's session token (UAT 2026-09-04)
// Logout used to be client-only: the user_sessions row lived on for 30 days.
// The client calls this best-effort before clearing local storage.
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$token = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) { $token = $m[1]; }
if ($token === '') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim((string)($input['session_token'] ?? ''));
}
if ($token === '') {
    echo json_encode(['status' => 'success', 'message' => 'Nothing to revoke']);
    exit;
}
try {
    dbQuery("DELETE FROM user_sessions WHERE session_token = ?", [$token]);
} catch (Throwable $e) {
    error_log('logout.php: ' . $e->getMessage());
}
echo json_encode(['status' => 'success', 'message' => 'Signed out']);
