-- 004_identity_claim.sql — Claim-your-profile (Phase 3)
--
-- A person can claim their own universal identity: they prove they control the
-- phone (SMS one-time code), and the identity is then linked to their user
-- account (player_identities.claimed_by_user_id, added in migration 003).
--
-- claim_code is the shareable handle behind the QR / claim link. It is NOT
-- derived from the phone (so it leaks nothing) and is unguessable. An organizer
-- shows it to the real person; the person opens playpbnow.com/claim?code=... and
-- verifies by SMS. The OTP itself reuses the existing verification_codes table.
--
-- Applied live 2026-07-04.

ALTER TABLE player_identities
    ADD COLUMN claim_code VARCHAR(20) NULL AFTER phone_hash,
    ADD UNIQUE KEY uq_claim_code (claim_code);

-- Existing identities are backfilled with codes by backfill_claim_codes.php
-- (app-level randomness + uniqueness). New identities get a code at creation
-- time in identity.php.
