-- 2026-09-04 — Pool player verification: cap wrong-code guesses.
--
-- player_register.php (verify / verify_update) previously allowed unlimited
-- guesses against a 6-digit code inside its 10-minute window. This column lets
-- the endpoint count failed attempts per pending code and refuse after 5.
--
-- The PHP is guarded: it detects whether this column exists at request time
-- and simply skips the cap when it does not, so deploying the code before (or
-- without) running this migration is safe. Run once:
--
--   mysql playpbnow < playpbnow-api/migrations/2026_09_04_pool_verification_attempts.sql
--
-- (Plain ADD COLUMN on purpose — `ADD COLUMN IF NOT EXISTS` is MariaDB-only and
-- is flagged by the governance scanner. If the column already exists this
-- statement errors harmlessly with 1060 "Duplicate column name".)

ALTER TABLE player_verification_codes
    ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
