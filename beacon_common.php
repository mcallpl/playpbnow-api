<?php
// ============================================================
// beacon_common.php — shared helpers for every beacon_*.php endpoint.
//
//  * beacon_adopt_session_user()  — H1: tolerant session adoption. If the
//    request carries a valid Bearer / X-Auth-Token session, the session's
//    user id OVERRIDES any client-supplied user_id / host_user_id. Requests
//    without a token keep working exactly as before (rollout-tolerant).
//  * beacon_iso() / beacon_expires_in_sec() / beacon_add_time_fields() — H2:
//    ISO-8601 (with offset) copies of the LA-wall-clock DATETIME strings the
//    DB returns, plus a server-computed seconds-until-expiry so clients never
//    have to parse 'YYYY-MM-DD HH:MM:SS' themselves (Safari => Invalid Date).
//
// Existing response fields are never removed — new *_iso / *_in_sec fields are
// added alongside them.
// ============================================================

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

if (!function_exists('beacon_session_user_id')) {
    /** Session user id (string, matches the VARCHAR user_id columns) or null. */
    function beacon_session_user_id(): ?string {
        try {
            $uid = pbnow_optional_user_id();
        } catch (\Throwable $e) {
            return null;
        }
        return $uid === null ? null : (string)$uid;
    }
}

if (!function_exists('beacon_adopt_session_user')) {
    /**
     * Tolerant adoption: returns the session user id when a valid token is
     * present, otherwise the client-supplied fallback (which may be null).
     * Usage:  $user_id = beacon_adopt_session_user($input['user_id'] ?? null);
     */
    function beacon_adopt_session_user($clientSupplied) {
        $sess = beacon_session_user_id();
        if ($sess !== null && $sess !== '') {
            return $sess;
        }
        return $clientSupplied;
    }
}

if (!function_exists('beacon_iso')) {
    /** 'YYYY-MM-DD HH:MM:SS' (server tz = America/Los_Angeles) -> ISO-8601 with offset. */
    function beacon_iso($dt): ?string {
        if ($dt === null || $dt === '' ) return null;
        $ts = strtotime((string)$dt);
        if ($ts === false) return null;
        return date('c', $ts);
    }
}

if (!function_exists('beacon_expires_in_sec')) {
    /** Seconds until $dt (negative when already past); null when unparseable. */
    function beacon_expires_in_sec($dt): ?int {
        if ($dt === null || $dt === '') return null;
        $ts = strtotime((string)$dt);
        if ($ts === false) return null;
        return $ts - time();
    }
}

if (!function_exists('beacon_add_time_fields')) {
    /**
     * Adds <field>_iso for each known DATETIME field present on the row, and
     * expires_in_sec when the row has expires_at. Mutates and returns the row.
     */
    function beacon_add_time_fields(array $row, array $fields = ['expires_at', 'created_at', 'starts_at', 'joined_at', 'filled_at', 'updated_at']): array {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row) && !array_key_exists($f . '_iso', $row)) {
                $row[$f . '_iso'] = beacon_iso($row[$f]);
            }
        }
        if (array_key_exists('expires_at', $row) && !array_key_exists('expires_in_sec', $row)) {
            $row['expires_in_sec'] = beacon_expires_in_sec($row['expires_at']);
        }
        $row['server_now_iso'] = date('c');
        return $row;
    }
}

if (!function_exists('beacon_reliability_sql')) {
    /**
     * C3: reliability = share of a player's lobbies that actually reached a
     * match. Nothing ever writes 'completed' today, so 'started' must count
     * too or every player is 0% forever.
     */
    function beacon_reliability_sql(): string {
        return "SELECT COUNT(CASE WHEN bl.status IN ('started','completed') THEN 1 END) / NULLIF(COUNT(*), 0) * 100 AS reliability_pct
                FROM beacon_lobby_members blm
                JOIN beacon_lobbies bl ON blm.lobby_id = bl.id
                WHERE blm.user_id = ? AND blm.status != 'replaced'";
    }
}
