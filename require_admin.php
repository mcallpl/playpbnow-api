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

// ── Role helpers (Universal Player Identity, Phase 1) ────────────────────────
if (!function_exists('pbnow_is_admin')) {
    // super_admin — can edit/delete anything.
    function pbnow_is_admin(int $uid): bool {
        $u = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$uid]);
        return $u && (int)$u['is_admin'] === 1;
    }
}

if (!function_exists('pbnow_user_is_premium')) {
    // "Premium" = an active OR in-trial subscription that hasn't lapsed. Admins
    // always count as premium. Matches the app's isPro || isTrial gating (new
    // users are on a 30-day premium trial, so trial must be included).
    function pbnow_user_is_premium(int $uid): bool {
        $u = dbGetRow(
            "SELECT is_admin, subscription_status, subscription_end_date FROM users WHERE id = ?",
            [$uid]
        );
        if (!$u) return false;
        if ((int)$u['is_admin'] === 1) return true;
        if (in_array($u['subscription_status'] ?? '', ['active', 'trial'], true)) {
            $end = $u['subscription_end_date'] ?? null;
            if ($end === null || strtotime($end) > time()) return true;
        }
        return false;
    }
}

if (!function_exists('pbnow_require_premium')) {
    // Session user who is premium (or admin), else 402. Returns the user_id.
    function pbnow_require_premium(): int {
        $uid = pbnow_require_session_user();
        if (!pbnow_user_is_premium($uid)) {
            http_response_code(402); // Payment Required
            echo json_encode(['status' => 'error', 'message' => 'This feature requires PlayPBNow Pro.', 'code' => 'premium_required']);
            exit;
        }
        return $uid;
    }
}
