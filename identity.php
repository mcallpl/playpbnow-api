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

if (!function_exists('pbnow_universal_stats')) {
    // Cross-organizer record for a master identity: aggregates every match played
    // by ANY local copy that shares this identity_id, plus how many distinct
    // organizers they've played under. Returns stats only — never the phone/hash.
    function pbnow_universal_stats(int $identity_id): array {
        $copies = dbGetAll(
            "SELECT player_key, created_by_user_id FROM players WHERE identity_id = ?",
            [$identity_id]
        );
        $keys       = array_values(array_filter(array_column($copies, 'player_key')));
        $organizers = count(array_unique(array_filter(array_column($copies, 'created_by_user_id'))));

        $wins = 0; $losses = 0; $diff = 0;
        if (!empty($keys)) {
            $ph = implode(',', array_fill(0, count($keys), '?'));
            foreach (dbGetAll("SELECT s1, s2 FROM matches WHERE p1_key IN ($ph) OR p2_key IN ($ph)", array_merge($keys, $keys)) as $m) {
                $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
                if ($s1 > $s2) $wins++; elseif ($s2 > $s1) $losses++;
                $diff += ($s1 - $s2);
            }
            foreach (dbGetAll("SELECT s1, s2 FROM matches WHERE p3_key IN ($ph) OR p4_key IN ($ph)", array_merge($keys, $keys)) as $m) {
                $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
                if ($s2 > $s1) $wins++; elseif ($s1 > $s2) $losses++;
                $diff += ($s2 - $s1);
            }
        }
        $games   = $wins + $losses;
        $win_pct = $games > 0 ? round($wins / $games * 100, 1) : 0.0;

        return [
            'organizers'    => $organizers,
            'linked_copies' => count($copies),
            'wins'          => $wins,
            'losses'        => $losses,
            'diff'          => $diff,
            'games'         => $games,
            'win_pct'       => $win_pct,
        ];
    }
}

if (!function_exists('pbnow_generate_claim_code')) {
    // Shareable, unguessable handle behind the QR / claim link. NOT derived from
    // the phone, so it leaks nothing. Charset excludes look-alikes (0/O, 1/I/L)
    // so it survives being read off a printed card or spoken aloud.
    // Format: PB-XXXX-XXXX (~28^8 ≈ 3.8e11 space). Uniqueness enforced by the
    // caller against the uq_claim_code index; this just mints candidates.
    function pbnow_generate_claim_code(): string {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no O,0,I,1,L
        $n = strlen($alphabet);
        $pick = function () use ($alphabet, $n) { return $alphabet[random_int(0, $n - 1)]; };
        $block = function () use ($pick) { return $pick() . $pick() . $pick() . $pick(); };
        return 'PB-' . $block() . '-' . $block();
    }
}

if (!function_exists('pbnow_unique_claim_code')) {
    // A claim_code guaranteed not to collide with an existing identity.
    function pbnow_unique_claim_code(): string {
        for ($i = 0; $i < 8; $i++) {
            $code = pbnow_generate_claim_code();
            $exists = dbGetRow("SELECT id FROM player_identities WHERE claim_code = ?", [$code]);
            if (!$exists) return $code;
        }
        // Astronomically unlikely; widen with a suffix rather than fail.
        return pbnow_generate_claim_code() . '-' . bin2hex(random_bytes(2));
    }
}

if (!function_exists('pbnow_resolve_identity')) {
    // Find-or-create the master identity for a phone. Returns identity_id or null
    // (null = no usable phone, so the player simply stays local/unlinked).
    function pbnow_resolve_identity(?string $phone, ?string $display_name = null): ?int {
        $hash = pbnow_phone_hash($phone);
        if ($hash === null) return null;

        $row = dbGetRow("SELECT id, claim_code FROM player_identities WHERE phone_hash = ?", [$hash]);
        if ($row) {
            // Fill a display name if the identity didn't have one yet.
            if ($display_name) {
                dbQuery(
                    "UPDATE player_identities SET display_name = ? WHERE id = ? AND (display_name IS NULL OR display_name = '')",
                    [$display_name, (int)$row['id']]
                );
            }
            // Backfill a claim_code for any legacy identity that predates Phase 3.
            if (empty($row['claim_code'])) {
                dbQuery(
                    "UPDATE player_identities SET claim_code = ? WHERE id = ? AND (claim_code IS NULL OR claim_code = '')",
                    [pbnow_unique_claim_code(), (int)$row['id']]
                );
            }
            return (int)$row['id'];
        }
        $id = dbInsert(
            "INSERT INTO player_identities (phone_hash, claim_code, display_name, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())",
            [$hash, pbnow_unique_claim_code(), $display_name]
        );
        return $id ? (int)$id : null;
    }
}
