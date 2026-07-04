<?php
/**
 * Universal Player Identity (Phase 2) — phone-keyed master identities.
 *
 * A phone number is a MATCHING KEY, never a shared field. We store only a
 * peppered SHA-256 hash on the master identity, so:
 *   - two organizers who each know a person's number resolve to the SAME master;
 *   - the raw number is never written to the shared identity table and can't be
 *     reversed out of it (the pepper defeats a phone-space rainbow table).
 *
 * The raw number still lives on each organizer's own local player row (their own
 * data, for their own invites) — it is never surfaced to another user.
 *
 * Depends on dbGetRow()/dbInsert() (db_config.php) and $vault_phone_pepper.
 */

if (!function_exists('pbnow_normalize_phone')) {
    // Digits-only, US-centric E.164-ish normalization. Returns null if it can't
    // be a real number, so junk never mints an identity.
    function pbnow_normalize_phone(?string $phone): ?string {
        if ($phone === null) return null;
        $d = preg_replace('/\D+/', '', $phone);
        if ($d === '' || strlen($d) < 10) return null;
        if (strlen($d) === 10) $d = '1' . $d;            // bare US 10-digit → +1
        if (strlen($d) === 11 && $d[0] !== '1') return $d; // some intl 11-digit
        return $d;
    }
}

if (!function_exists('pbnow_phone_hash')) {
    // One-way peppered hash used as the identity key. null if phone unusable.
    function pbnow_phone_hash(?string $phone): ?string {
        $n = pbnow_normalize_phone($phone);
        if ($n === null) return null;
        $pepper = $GLOBALS['vault_phone_pepper'] ?? getenv('PBNOW_PHONE_PEPPER') ?: 'pbnow-fallback-pepper';
        return hash('sha256', $pepper . '|' . $n);
    }
}

if (!function_exists('pbnow_resolve_identity')) {
    // Find-or-create the master identity for a phone. Returns identity_id or null
    // (null = no usable phone, so the player simply stays local/unlinked).
    function pbnow_resolve_identity(?string $phone, ?string $display_name = null): ?int {
        $hash = pbnow_phone_hash($phone);
        if ($hash === null) return null;

        $row = dbGetRow("SELECT id FROM player_identities WHERE phone_hash = ?", [$hash]);
        if ($row) {
            // Fill a display name if the identity didn't have one yet.
            if ($display_name) {
                dbQuery(
                    "UPDATE player_identities SET display_name = ? WHERE id = ? AND (display_name IS NULL OR display_name = '')",
                    [$display_name, (int)$row['id']]
                );
            }
            return (int)$row['id'];
        }
        $id = dbInsert(
            "INSERT INTO player_identities (phone_hash, display_name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())",
            [$hash, $display_name]
        );
        return $id ? (int)$id : null;
    }
}
