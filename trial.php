<?php
/**
 * Usage-triggered trial.
 *
 * The 30-day trial used to start the moment someone registered, burning down on
 * calendar time whether or not they ever opened the app. Someone who signed up,
 * got busy, and organised their first game three weeks later arrived with a week
 * of trial left — and anyone who never got round to it had the whole trial
 * expire without ever seeing what they were being sold.
 *
 * The clock now starts on the first MEANINGFUL use: saving a session, i.e.
 * actually running and scoring a match. That is the point the product has
 * delivered its core value, so it is the fair place to start charging time.
 *
 * States, all on the users row:
 *   trial_start_date IS NULL  + status 'trial'  -> trial not started yet
 *   trial_start_date set      + status 'trial'  -> running, expires at
 *                                                  subscription_end_date
 *   status 'expired'                            -> used up
 *
 * A NULL subscription_end_date is what makes "not started" safe: every expiry
 * check is guarded on that column, so an unstarted trial can never lapse.
 */

if (!function_exists('pbnow_trial_days')) {
    function pbnow_trial_days(): int {
        return 30;
    }
}

if (!function_exists('pbnow_start_trial_on_first_use')) {
    /**
     * Start the trial clock if this user's trial has not begun yet.
     *
     * Safe to call on every session save — the WHERE clause makes it a no-op
     * once the trial is running, so there is no need for callers to know the
     * user's state. Deliberately does NOT touch users who are 'active'
     * (paying), 'expired' (already used it) or 'cancelled'.
     *
     * Never throws: a failure here must not take down a match save. The trial
     * simply starts on the next save instead.
     */
    function pbnow_start_trial_on_first_use($user_id): void {
        $uid = (int) $user_id;
        if ($uid <= 0) return;

        try {
            $days = pbnow_trial_days();
            dbQuery(
                "UPDATE users
                    SET trial_start_date = NOW(),
                        subscription_end_date = DATE_ADD(NOW(), INTERVAL ? DAY)
                  WHERE id = ?
                    AND subscription_status = 'trial'
                    AND trial_start_date IS NULL",
                [$days, $uid]
            );
        } catch (Exception $e) {
            error_log("pbnow_start_trial_on_first_use: " . $e->getMessage());
        } catch (Error $e) {
            // mysqli in strict mode raises Error, not Exception — and an Error
            // escaping here would kill the whole save.
            error_log("pbnow_start_trial_on_first_use: " . $e->getMessage());
        }
    }
}
