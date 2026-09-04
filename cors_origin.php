<?php
// ============================================================
// cors_origin.php — shared CORS origin allowlist (UAT 2026-09-04)
//
// Ten endpoints hard-coded `Access-Control-Allow-Origin: https://peoplestar.com`,
// which is the LEGACY host. The canonical web app is https://playpbnow.com, so
// any of these called cross-origin from the real app were blocked outright
// (delete_player.php was one — deleting a player failed on the web build).
//
// This reflects the caller's origin when it is one we trust, and falls back to
// the legacy host so nothing that worked before stops working.
// ============================================================
if (!function_exists('pbnow_send_cors_headers')) {
    function pbnow_send_cors_headers(array $extraOrigins = []): void {
        $allowed = array_merge([
            'https://playpbnow.com',
            'https://www.playpbnow.com',
            'https://peoplestar.com',
            'https://www.peoplestar.com',
            'https://playpbnow.peoplestar.com',
        ], $extraOrigins);

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            // Preserve the previous default for non-browser and legacy callers.
            header('Access-Control-Allow-Origin: https://peoplestar.com');
        }
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}
