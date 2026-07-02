<?php
// ============================================================
// require_admin.php — shared authentication for privileged endpoints.
//
// Requires a valid, non-expired session token (Authorization: Bearer <token>
// or X-Auth-Token header) belonging to a user with is_admin = 1. Endpoints that
// perform destructive or privilege-granting operations must call require_admin()
// immediately after including db_config.php.
//
// Depends on dbGetRow() from db_config.php and the user_sessions / users tables.
// ============================================================

if (!function_exists('pbnow_bearer_token')) {
    function pbnow_bearer_token(): string {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        // Header names are case-insensitive; normalize.
        $auth = '';
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
        }
        if ($auth && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-Auth-Token') === 0) { return trim($v); }
        }
        return '';
    }
}

if (!function_exists('pbnow_require_session_user')) {
    // Returns the authenticated user_id (int) or exits 401.
    function pbnow_require_session_user(): int {
        $token = pbnow_bearer_token();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
            exit;
        }
        $session = dbGetRow(
            "SELECT user_id, expires_at FROM user_sessions WHERE session_token = ?",
            [$token]
        );
        if (!$session || strtotime($session['expires_at']) < time()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired session']);
            exit;
        }
        return (int) $session['user_id'];
    }
}

if (!function_exists('pbnow_optional_user_id')) {
    // Returns the authenticated user_id (int) from the session token, or null
    // if no valid token was supplied. Does NOT exit — for endpoints that want to
    // prefer the token but tolerate its absence during rollout.
    function pbnow_optional_user_id(): ?int {
        $token = pbnow_bearer_token();
        if (!$token) return null;
        $session = dbGetRow(
            "SELECT user_id, expires_at FROM user_sessions WHERE session_token = ?",
            [$token]
        );
        if (!$session || strtotime($session['expires_at']) < time()) return null;
        return (int) $session['user_id'];
    }
}

if (!function_exists('require_admin')) {
    // Returns the authenticated admin's user_id (int) or exits 401/403.
    function require_admin(): int {
        $userId = pbnow_require_session_user();
        $u = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$userId]);
        if (!$u || !$u['is_admin']) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Admin privileges required']);
            exit;
        }
        return $userId;
    }
}
