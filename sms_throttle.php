<?php
/**
 * sms_throttle.php — shared, file-based rate limiter for anything that costs a
 * Twilio send (verification codes, RSVP notifications, waitlist promotions).
 *
 * Same design as the limiter that already guards send_verification_code.php:
 * one small file per key under sys_get_temp_dir()/pbnow_rl holding a list of
 * hit timestamps. FAIL-OPEN: any filesystem error allows the send, so a
 * legitimate user is never blocked by an infra hiccup — only confirmed abuse
 * (>= $max hits inside $windowSeconds) is stopped.
 *
 *   if (!sms_throttle('reg_' . $phone, 5, 3600)) { ...429... }
 *
 * Also carries two small helpers that several endpoints needed at once:
 *   pbnow_secure_code()              — CSPRNG 6-digit code (random_int, not rand)
 *   pbnow_friendly_twilio_error($m)  — maps raw Twilio exception text to copy a
 *                                      match organizer can act on
 *   pbnow_phone_match_sql()          — legacy-tolerant phone WHERE clause
 *
 * Every function is wrapped in function_exists() so this file can be included
 * from any endpoint without redeclaration errors.
 */

if (!function_exists('sms_throttle')) {
    /**
     * Record a hit for $key and report whether it is still under the limit.
     *
     * @param string $key           Any identifier (phone, invite id, ip...). Hashed, so raw values are fine.
     * @param int    $max           Maximum hits allowed inside the window.
     * @param int    $windowSeconds Window length in seconds.
     * @return bool  true = allowed (hit recorded), false = over the limit (hit NOT recorded).
     */
    function sms_throttle(string $key, int $max, int $windowSeconds): bool {
        try {
            $dir = sys_get_temp_dir() . '/pbnow_rl';
            if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
            $file = $dir . '/th_' . md5($key);
            $now = time();
            $hits = [];
            if (is_file($file)) {
                $raw = @file_get_contents($file);
                if ($raw !== false && $raw !== '') {
                    foreach (explode(',', $raw) as $t) {
                        $t = (int) $t;
                        if ($t > $now - $windowSeconds) { $hits[] = $t; }
                    }
                }
            }
            if (count($hits) >= $max) {
                return false;
            }
            $hits[] = $now;
            @file_put_contents($file, implode(',', $hits), LOCK_EX);
            return true;
        } catch (Throwable $e) {
            // fail open — never block a legitimate user because of a limiter error
            return true;
        }
    }
}

if (!function_exists('pbnow_secure_code')) {
    /** 6-digit verification code from a CSPRNG (rand() is predictable). */
    function pbnow_secure_code(int $length = 6): string {
        $max = (10 ** $length) - 1;
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pbnow_friendly_twilio_error')) {
    /**
     * Twilio exception messages are long and technical ("[HTTP 400] Unable to
     * create record: The 'To' number +1555... is not a valid phone number").
     * Map the common ones to something an organizer can act on. Unknown errors
     * fall back to a short generic line so raw SDK text never reaches the UI.
     */
    function pbnow_friendly_twilio_error(string $raw): string {
        $m = strtolower($raw);
        if (strpos($m, '21211') !== false || strpos($m, 'not a valid phone number') !== false) {
            return 'invalid phone number';
        }
        if (strpos($m, '21610') !== false || strpos($m, 'unsubscribed') !== false || (strpos($m, 'stop') !== false && strpos($m, 'opted') !== false)) {
            return 'this player has opted out of texts (replied STOP)';
        }
        if (strpos($m, '21614') !== false || strpos($m, 'not a mobile number') !== false || strpos($m, 'landline') !== false) {
            return 'not a mobile number';
        }
        if (strpos($m, '21408') !== false || strpos($m, 'permission to send') !== false) {
            return 'texting this region is not enabled';
        }
        if (strpos($m, '30007') !== false || strpos($m, 'filtered') !== false) {
            return 'message blocked by the carrier';
        }
        if (strpos($m, '20003') !== false || strpos($m, 'authenticate') !== false) {
            return 'texting service unavailable, try again shortly';
        }
        if (strpos($m, 'timed out') !== false || strpos($m, 'timeout') !== false || strpos($m, 'could not resolve') !== false) {
            return 'texting service timed out, try again';
        }
        return 'could not deliver the text';
    }
}

if (!function_exists('pbnow_phone_match_sql')) {
    /**
     * WHERE fragment that matches a phone column against a cleaned E.164 number
     * even when the stored value is a legacy format ("(714) 555-1212",
     * "714-555-1212", "17145551212"). Compares on the last 10 digits after
     * stripping punctuation — MySQL 5.7-safe (no REGEXP_REPLACE).
     *
     * Usage: [$sql, $params] = pbnow_phone_match_sql('phone', $cleanPhone);
     *        dbGetRow("SELECT id FROM pool_players WHERE $sql", $params);
     */
    function pbnow_phone_match_sql(string $column, string $cleanPhone): array {
        $digits = preg_replace('/[^0-9]/', '', $cleanPhone);
        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;
        $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($column,'-',''),' ',''),'(',''),')',''),'.',''),'+','')";
        $sql = "($column = ? OR RIGHT($stripped, 10) = ?)";
        return [$sql, [$cleanPhone, $last10]];
    }
}

if (!function_exists('pbnow_valid_nanp')) {
    /** True for a cleaned number that is a real North American mobile-shaped number. */
    function pbnow_valid_nanp(string $cleanPhone): bool {
        return (bool) preg_match('/^\+1[2-9]\d{9}$/', $cleanPhone);
    }
}
