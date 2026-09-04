<?php
// ============================================================================
// collab_auth.php
//
// Shared authentication + concurrency helpers for the connected-scoring
// endpoints (collab_create_session / collab_join_match / collab_sync_scores /
// collab_sync_batch / collab_get_scores / collab_update_schedule).
//
// Added 2026-09-04 by Fix Agent C1 for audit findings:
//   #2  write ordering (server-issued monotonic sequence)
//   #8  atomic shuffle reset (schedule_version / scores_reset_at)
//   #9  simultaneous finish ('finishing' claim status)
//   #11 authentication (nobody was checking WHO was calling)
//   #14 participant duplicates / roster
//   #16 batch sync
//
// TWO NON-NEGOTIABLE PROPERTIES OF THIS FILE
// ------------------------------------------
// 1. EVERY new database column it touches is FEATURE-DETECTED. If
//    migrations/006_collab_hardening.sql has not been applied, every helper
//    falls back to exactly the behaviour the endpoints had before, so
//    deploying the PHP ahead of the migration is safe.
//
// 2. AUTHENTICATION IS TOLERANT, NOT FAIL-CLOSED. The shipped app build does
//    NOT send an Authorization header (or even a user_id) on
//    collab_sync_scores.php / collab_update_schedule.php. Locking those calls
//    down would brick every phone in the field mid-match. So:
//       - Bearer session present  -> that is the caller, membership enforced.
//       - No Bearer, body user_id -> that is the caller, membership enforced.
//       - Neither                 -> LEGACY CALLER: allowed through and logged.
//    Once the new client ships everywhere, flip PBNOW_COLLAB_STRICT_AUTH to
//    true (below) and the third case becomes a 401. Nothing else changes.
// ============================================================================

if (!function_exists('dbGetRow')) {
    require_once __DIR__ . '/db_config.php';
}
// pbnow_optional_user_id() lives here. Guarded: some deploys may lag.
if (file_exists(__DIR__ . '/require_admin.php')) {
    require_once __DIR__ . '/require_admin.php';
}

// Flip to true ONLY when every shipped client sends an identity on the collab
// endpoints. Until then an unidentified caller is allowed (and logged).
if (!defined('PBNOW_COLLAB_STRICT_AUTH')) {
    define('PBNOW_COLLAB_STRICT_AUTH', false);
}

// ─────────────────────────────────────────────────────────────────────────────
// Schema feature detection
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('pbnow_collab_has_column')) {
    /**
     * Does $table.$column exist? Cached for the life of the request.
     * Returns false on any error — callers then take the legacy path.
     */
    function pbnow_collab_has_column(string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $row = dbGetRow(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            $cache[$key] = ($row && (int)$row['c'] > 0);
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('pbnow_collab_status_supports')) {
    /**
     * Does collab_sessions.status accept $value?
     *
     * This matters a great deal: writing a value an ENUM does not contain
     * either throws (strict mode) or silently stores '' (non-strict) — the
     * exact bug that once corrupted live rows to status='' and is repaired in
     * collab_alter_mysql8.sql. Never write a status without asking here first.
     */
    function pbnow_collab_status_supports(string $value): bool {
        static $cache = [];
        if (isset($cache[$value])) return $cache[$value];
        try {
            $row = dbGetRow(
                "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collab_sessions'
                   AND COLUMN_NAME = 'status'"
            );
            $type = $row['t'] ?? '';
            $cache[$value] = ($type !== '' && strpos($type, "'" . $value . "'") !== false);
        } catch (Throwable $e) {
            $cache[$value] = false;
        }
        return $cache[$value];
    }
}

if (!function_exists('pbnow_collab_participants_alive_sql')) {
    /**
     * The `AND _deleted_at IS NULL` fragment, only when that column exists.
     * collab_participants carries a soft-delete column on production.
     */
    function pbnow_collab_participants_alive_sql(string $alias = ''): string {
        $p = $alias !== '' ? $alias . '.' : '';
        return pbnow_collab_has_column('collab_participants', '_deleted_at')
            ? " AND {$p}_deleted_at IS NULL"
            : '';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Identity + membership
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('pbnow_collab_identity')) {
    /**
     * Who is calling?
     *
     * @return array{user_id:string, source:string, device_id:string}
     *         source is 'session' (Bearer token), 'body' (payload user_id)
     *         or 'none' (legacy caller — tolerated, see file header).
     */
    function pbnow_collab_identity(array $input): array {
        $userId = '';
        $source = 'none';

        if (function_exists('pbnow_optional_user_id')) {
            try {
                $uid = pbnow_optional_user_id();
                if ($uid !== null && $uid > 0) {
                    $userId = (string)$uid;
                    $source = 'session';
                }
            } catch (Throwable $e) { /* fall through to the body */ }
        }

        if ($userId === '') {
            $bodyId = trim((string)($input['user_id'] ?? $_GET['user_id'] ?? ''));
            if ($bodyId !== '') {
                $userId = $bodyId;
                $source = 'body';
            }
        }

        $deviceId = trim((string)($input['device_id'] ?? $_GET['device_id'] ?? ''));
        // Fall back to the USER as the writer identity when the client sends no
        // device id. Without this every writer looked like the empty string, so
        // two different scorers on two different phones were indistinguishable,
        // the "was this written by someone else?" ordering test could never
        // fire, and a delayed retry still clobbered a newer score from another
        // person — the exact bug the sequence guard exists to stop.
        // (UAT 2026-09-04: verified failing against production before this.)
        // A single user on two devices that both send no device_id still looks
        // like one writer, which is the harmless direction to be wrong in.
        if ($deviceId === '' && $userId !== '') {
            $deviceId = 'u:' . $userId;
        }
        if (strlen($deviceId) > 100) $deviceId = substr($deviceId, 0, 100);

        return ['user_id' => $userId, 'source' => $source, 'device_id' => $deviceId];
    }
}

if (!function_exists('pbnow_collab_deny')) {
    function pbnow_collab_deny(string $message, int $httpCode = 403, string $code = 'forbidden'): void {
        http_response_code($httpCode);
        echo json_encode(['status' => 'error', 'message' => $message, 'code' => $code]);
        exit;
    }
}

if (!function_exists('pbnow_collab_is_participant')) {
    function pbnow_collab_is_participant(int $sessionId, string $userId): bool {
        if ($userId === '') return false;
        try {
            $row = dbGetRow(
                "SELECT id FROM collab_participants
                 WHERE session_id = ? AND user_id = ?" . pbnow_collab_participants_alive_sql() . "
                 ORDER BY id ASC LIMIT 1",
                [$sessionId, $userId]
            );
            return (bool)$row;
        } catch (Throwable $e) {
            // Never let a broken membership lookup lock the match out.
            error_log('collab_auth participant lookup failed: ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('pbnow_collab_require_participant')) {
    /**
     * Caller must be the session creator OR a registered participant.
     * An unidentified (legacy) caller is allowed unless strict mode is on.
     *
     * @param array $session row from collab_sessions (needs id, creator_user_id)
     */
    function pbnow_collab_require_participant(array $session, array $identity): void {
        $sid     = (int)($session['id'] ?? 0);
        $userId  = (string)($identity['user_id'] ?? '');
        $source  = (string)($identity['source'] ?? 'none');
        $creator = (string)($session['creator_user_id'] ?? '');

        if ($userId === '') {
            if (PBNOW_COLLAB_STRICT_AUTH) {
                pbnow_collab_deny('Sign in to score this match.', 401, 'auth_required');
            }
            error_log("collab_auth: unidentified caller allowed (legacy client) session=$sid");
            return;
        }

        if ($creator !== '' && $creator === $userId) return;   // the host
        if (pbnow_collab_is_participant($sid, $userId)) return; // joined already

        pbnow_collab_deny(
            'You are not part of this live match. Join it with the share code first.',
            403, 'not_a_participant'
        );
    }
}

if (!function_exists('pbnow_collab_require_host')) {
    /**
     * Caller must be the session creator. Used by collab_update_schedule.php,
     * which can wipe every score in a live match.
     *
     * Tolerances that exist so a real host is NEVER locked out:
     *   - creator_user_id empty (sessions created before it was stored)
     *     -> allowed as 'unverified'.
     *   - caller unidentified (legacy client sends no user_id)
     *     -> allowed as 'unverified' unless PBNOW_COLLAB_STRICT_AUTH.
     *
     * @return string 'host'       — proven to be the session creator.
     *                'unverified' — tolerated legacy caller. THE CALLER MUST
     *                               refuse destructive operations in this case
     *                               (see collab_update_schedule.php: an
     *                               unverified caller can never wipe scores).
     */
    function pbnow_collab_require_host(array $session, array $identity): string {
        $sid     = (int)($session['id'] ?? 0);
        $userId  = (string)($identity['user_id'] ?? '');
        $creator = (string)($session['creator_user_id'] ?? '');

        if ($creator === '') {
            error_log("collab_auth: session $sid has no creator_user_id — host UNVERIFIED");
            return 'unverified';
        }
        if ($userId === '') {
            if (PBNOW_COLLAB_STRICT_AUTH) {
                pbnow_collab_deny('Sign in to change the schedule.', 401, 'auth_required');
            }
            error_log("collab_auth: unidentified caller on host-only path (legacy client) session=$sid — host UNVERIFIED");
            return 'unverified';
        }
        if ($userId === $creator) return 'host';

        pbnow_collab_deny(
            'Only the host who started this live match can change the schedule.',
            403, 'host_only'
        );
        return 'host'; // unreachable — pbnow_collab_deny() exits.
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Proof-of-knowledge for UNIDENTIFIED callers (#11)
//
// CONFIRMED EXPLOITABLE against production 2026-09-04: with nothing but the
// 6-character share code printed on screen for everyone at the court to read,
//
//   POST /api/collab_update_schedule.php
//   {"share_code":"JCSPJC","session_id":17,"schedule":[{"games":[]}],"reset_scores":true}
//
// returned {"status":"success","scores_cleared":1} and every device polling
// that match adopted the wipe. The share code is a convenience, never a secret.
//
// The complete fix is the host check, but the shipped app build sends NO
// identity at all on this endpoint, so a hard requirement would brick real
// hosts mid-match. For that transitional window an unidentified caller must
// instead PROVE it knows the match: the schedule it is pushing has to share at
// least one player id with the schedule already stored. Player ids are
// internal and never displayed, so a stranger with only the share code cannot
// produce one — and an empty or fabricated schedule (exactly the payload
// above) fails outright. A real host's shuffle or swap always keeps most of
// the roster, so it passes.
//
// This is a stopgap for legacy clients, not the security model. Once every
// client sends an identity, set PBNOW_COLLAB_STRICT_AUTH = true and the
// unidentified path disappears entirely.
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('pbnow_collab_schedule_player_ids')) {
    /**
     * Every player id anywhere in a schedule structure, as a set of strings.
     * Walks the tree generically so it survives schedule-shape changes
     * (rounds -> games -> team1/team2 -> {id, first_name}, plus byes).
     */
    function pbnow_collab_schedule_player_ids($schedule, int $depth = 0): array {
        if ($depth > 12 || !is_array($schedule)) return [];
        $ids = [];
        foreach ($schedule as $key => $value) {
            if ($key === 'id' && (is_string($value) || is_int($value))) {
                $v = trim((string)$value);
                if ($v !== '') $ids[$v] = true;
            } elseif (is_array($value)) {
                foreach (pbnow_collab_schedule_player_ids($value, $depth + 1) as $v => $_) {
                    $ids[$v] = true;
                }
            }
        }
        return $ids;
    }
}

if (!function_exists('pbnow_collab_schedule_proves_knowledge')) {
    /**
     * Does $incoming look like a genuine edit of $storedJson?
     *
     * True when the incoming schedule is non-empty and shares at least one
     * player id with the stored one. If the stored schedule has no ids at all
     * (unparseable / legacy shape) we cannot judge, so we return true rather
     * than block a real host — the host check is still the primary control.
     */
    function pbnow_collab_schedule_proves_knowledge(array $incoming, ?string $storedJson): bool {
        if (empty($incoming)) return false;

        $stored = json_decode((string)$storedJson, true);
        if (!is_array($stored)) return true;          // cannot judge — do not block

        $storedIds = pbnow_collab_schedule_player_ids($stored);
        if (empty($storedIds)) return true;           // cannot judge — do not block

        $incomingIds = pbnow_collab_schedule_player_ids($incoming);
        if (empty($incomingIds)) return false;        // an empty round set: the exploit

        foreach ($incomingIds as $id => $_) {
            if (isset($storedIds[$id])) return true;
        }
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Participants roster (#14)
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('pbnow_collab_touch_participant')) {
    /**
     * Register / heartbeat a participant with ONE statement.
     *
     * Replaces the SELECT-then-INSERT that had no transaction and therefore
     * inserted duplicate rows, permanently inflating "connected_users".
     * Requires the UNIQUE key from migration 006 to be duplicate-proof; without
     * it, this degrades to the old read-then-write, which is what happens today.
     *
     * $role is only applied on INSERT — an existing 'host' row is never
     * demoted to 'collaborator' by a later heartbeat.
     */
    function pbnow_collab_touch_participant(int $sessionId, string $userId, string $deviceId = '', string $role = 'collaborator'): void {
        if ($sessionId <= 0 || $userId === '') return;
        if ($role !== 'host' && $role !== 'collaborator') $role = 'collaborator';

        $hasDevice = pbnow_collab_has_column('collab_participants', 'device_id');
        $hasDeleted = pbnow_collab_has_column('collab_participants', '_deleted_at');

        try {
            if ($hasDevice) {
                // Upsert on (session_id, user_id, device_id).
                $sql = "INSERT INTO collab_participants (session_id, user_id, device_id, role, joined_at, last_seen)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE last_seen = NOW()"
                     . ($hasDeleted ? ", _deleted_at = NULL" : "")
                     . ", role = IF(VALUES(role) = 'host', 'host', role)";
                dbQuery($sql, [$sessionId, $userId, $deviceId, $role]);
                return;
            }

            // ── Legacy path (migration not applied): keep today's behaviour ──
            $existing = dbGetRow(
                "SELECT id FROM collab_participants WHERE session_id = ? AND user_id = ?"
                . pbnow_collab_participants_alive_sql() . " ORDER BY id ASC LIMIT 1",
                [$sessionId, $userId]
            );
            if ($existing) {
                dbQuery("UPDATE collab_participants SET last_seen = NOW() WHERE id = ?", [(int)$existing['id']]);
            } else {
                dbInsert(
                    "INSERT INTO collab_participants (session_id, user_id, role, joined_at, last_seen)
                     VALUES (?, ?, ?, NOW(), NOW())",
                    [$sessionId, $userId, $role]
                );
            }
        } catch (Throwable $e) {
            // A heartbeat must never break scoring.
            error_log('collab_auth touch_participant failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pbnow_collab_roster')) {
    /**
     * Who is connected right now — the UI promises "see who's connected" but
     * the API only ever returned an integer.
     *
     * @return array<int, array{user_id:string, role:string, first_name:string, last_seen:string, seconds_ago:int}>
     */
    function pbnow_collab_roster(int $sessionId, int $windowSeconds = 30): array {
        if ($sessionId <= 0) return [];
        try {
            $rows = dbGetAll(
                "SELECT p.user_id, p.role, p.last_seen,
                        TIMESTAMPDIFF(SECOND, p.last_seen, NOW()) AS seconds_ago
                 FROM collab_participants p
                 WHERE p.session_id = ?
                   AND p.last_seen > DATE_SUB(NOW(), INTERVAL ? SECOND)"
                 . pbnow_collab_participants_alive_sql('p') . "
                 ORDER BY (p.role = 'host') DESC, p.joined_at ASC",
                [$sessionId, $windowSeconds]
            );
        } catch (Throwable $e) {
            error_log('collab_auth roster failed: ' . $e->getMessage());
            return [];
        }

        // Attach first names where the user_id maps to a real account. Kept
        // separate and guarded so an unexpected users schema can't break the
        // scoring poll.
        $names = [];
        $ids = [];
        foreach ($rows as $r) {
            $uid = (string)$r['user_id'];
            if ($uid !== '' && ctype_digit($uid)) $ids[] = (int)$uid;
        }
        if (!empty($ids)) {
            try {
                $in = implode(',', array_map('intval', array_unique($ids)));
                $userRows = dbGetAll("SELECT id, first_name FROM users WHERE id IN ($in)");
                foreach ($userRows as $u) { $names[(string)$u['id']] = (string)($u['first_name'] ?? ''); }
            } catch (Throwable $e) { /* names are a nicety, not a requirement */ }
        }

        $out = [];
        foreach ($rows as $r) {
            $uid = (string)$r['user_id'];
            $out[] = [
                'user_id'     => $uid,
                'role'        => (string)($r['role'] ?? 'collaborator'),
                'first_name'  => $names[$uid] ?? '',
                'last_seen'   => (string)($r['last_seen'] ?? ''),
                'seconds_ago' => (int)($r['seconds_ago'] ?? 0),
            ];
        }
        return $out;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Ordering (#2) — PURE functions, unit-tested in tests/collab_concurrency_test.php
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('pbnow_collab_should_accept_field')) {
    /**
     * Should an incoming write to ONE score field be applied?
     *
     * The client cannot know the server's sequence numbers, so it tells us the
     * highest seq it has SEEN (last_seen_seq, echoed back from
     * collab_get_scores.php). The rule:
     *
     *   REJECT only when the stored value was written by a DIFFERENT device
     *   AND that write is NEWER than anything this caller has seen.
     *
     * Everything else is accepted, which means:
     *   - no last_seen_seq (older app build)  -> accept  (no regression)
     *   - the caller's own earlier write      -> accept  (typing 1 then 11)
     *   - a write the caller has already seen -> accept  (deliberate override)
     *   - nothing stored yet (stored_seq 0)   -> accept
     *
     * That is exactly the "delayed retry must not clobber a newer value from
     * another scorer" case from the audit, and nothing more.
     */
    function pbnow_collab_should_accept_field(int $storedSeq, string $storedDevice, string $incomingDevice, ?int $lastSeenSeq): bool {
        if ($lastSeenSeq === null) return true;   // legacy client
        if ($storedSeq <= 0) return true;         // never written under the new scheme
        if ($storedDevice === '' ) return true;   // pre-migration row, unknown author
        if ($incomingDevice !== '' && $storedDevice === $incomingDevice) return true; // own write
        return $storedSeq <= $lastSeenSeq;        // stale ONLY if someone else got there first
    }
}

if (!function_exists('pbnow_collab_merge_snapshot')) {
    /**
     * Merge one game's FINAL values into the scores_json snapshot blob.
     *
     * Preserves the 2026-09-04 fix exactly: a field that was PROVIDED but is
     * empty means "the scorer erased this", so the key is REMOVED — otherwise
     * the server keeps re-broadcasting a value the user deleted. A field that
     * was not provided is left completely alone.
     *
     * Callers pass the FINAL (post-ordering-guard) value, so a rejected write
     * writes the stored value back rather than the stale incoming one.
     */
    function pbnow_collab_merge_snapshot(array $scores, int $roundIdx, int $gameIdx, bool $s1Provided, string $s1Final, bool $s2Provided, string $s2Final): array {
        if ($s1Provided) {
            if ($s1Final !== '') { $scores["{$roundIdx}_{$gameIdx}_t1"] = $s1Final; }
            else { unset($scores["{$roundIdx}_{$gameIdx}_t1"]); }
        }
        if ($s2Provided) {
            if ($s2Final !== '') { $scores["{$roundIdx}_{$gameIdx}_t2"] = $s2Final; }
            else { unset($scores["{$roundIdx}_{$gameIdx}_t2"]); }
        }
        return $scores;
    }
}

if (!function_exists('pbnow_collab_claim_outcome')) {
    /**
     * Interpret the result of the atomic finish claim (#9), i.e.
     *   UPDATE collab_sessions SET status='finishing'
     *   WHERE share_code = ? AND status = 'active'
     *
     * @param int    $affectedRows  from the UPDATE above
     * @param ?string $currentStatus status now, when the claim got 0 rows
     * @return array{claimed:bool, outcome:string}
     *         outcome: 'claimed' | 'already_saved' | 'saving_elsewhere' | 'proceed'
     *
     * 'proceed' is the important tolerance: if there is no collab row at all,
     * or it expired, saving must NOT be blocked — that would lose the match.
     */
    function pbnow_collab_claim_outcome(int $affectedRows, ?string $currentStatus): array {
        if ($affectedRows > 0) return ['claimed' => true, 'outcome' => 'claimed'];
        if ($currentStatus === 'finished')  return ['claimed' => false, 'outcome' => 'already_saved'];
        if ($currentStatus === 'finishing') return ['claimed' => false, 'outcome' => 'saving_elsewhere'];
        return ['claimed' => false, 'outcome' => 'proceed'];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Sequenced score writes (#2) — used by collab_sync_scores + collab_sync_batch
// ─────────────────────────────────────────────────────────────────────────────

if (!function_exists('pbnow_collab_next_seq')) {
    /**
     * Take the next server-issued sequence number for a session.
     * MUST be called while the session row is held by SELECT ... FOR UPDATE.
     * Returns 0 when the column does not exist (migration not applied) —
     * callers then behave exactly as before.
     */
    function pbnow_collab_next_seq($conn, int $sessionId): int {
        if (!pbnow_collab_has_column('collab_sessions', 'seq_counter')) return 0;
        try {
            $stmt = $conn->prepare("UPDATE collab_sessions SET seq_counter = seq_counter + 1 WHERE id = ?");
            if (!$stmt) return 0;
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("SELECT seq_counter FROM collab_sessions WHERE id = ?");
            if (!$stmt) return 0;
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int)($row['seq_counter'] ?? 0);
        } catch (Throwable $e) {
            error_log('collab_auth next_seq failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('pbnow_collab_apply_score_write')) {
    /**
     * Upsert ONE game's score row, applying the ordering guard per field.
     *
     * The session row MUST already be locked (SELECT ... FOR UPDATE) by the
     * caller; this function does no locking of its own.
     *
     * @return array{
     *   s1_final:string, s2_final:string,
     *   s1_applied:bool, s2_applied:bool,
     *   s1_seq:int, s2_seq:int,
     *   rejected:bool
     * }
     */
    function pbnow_collab_apply_score_write(
        $conn, int $sessionId, int $roundIdx, int $gameIdx,
        bool $s1Provided, string $s1Str, bool $s2Provided, string $s2Str,
        ?int $lastSeenSeq, string $deviceId, int $seq
    ): array {
        $hasSeq = pbnow_collab_has_column('collab_score_updates', 's1_seq')
               && pbnow_collab_has_column('collab_score_updates', 's1_device');

        $cols = $hasSeq
            ? "id, s1_str, s2_str, s1_seq, s2_seq, s1_device, s2_device"
            : "id, s1_str, s2_str";

        $stmt = $conn->prepare(
            "SELECT $cols FROM collab_score_updates
             WHERE session_id = ? AND round_idx = ? AND game_idx = ?"
        );
        $stmt->bind_param('iii', $sessionId, $roundIdx, $gameIdx);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $s1Applied = $s1Provided;
        $s2Applied = $s2Provided;

        if ($existing && $hasSeq) {
            if ($s1Provided) {
                $s1Applied = pbnow_collab_should_accept_field(
                    (int)$existing['s1_seq'], (string)$existing['s1_device'], $deviceId, $lastSeenSeq
                );
            }
            if ($s2Provided) {
                $s2Applied = pbnow_collab_should_accept_field(
                    (int)$existing['s2_seq'], (string)$existing['s2_device'], $deviceId, $lastSeenSeq
                );
            }
        }

        // Final values: the accepted incoming value, else whatever is stored.
        $s1Final = $s1Applied ? $s1Str : (string)($existing['s1_str'] ?? '');
        $s2Final = $s2Applied ? $s2Str : (string)($existing['s2_str'] ?? '');
        $s1Int = ($s1Final !== '') ? (int)$s1Final : 0;
        $s2Int = ($s2Final !== '') ? (int)$s2Final : 0;

        $s1Seq = $s1Applied ? $seq : (int)($existing['s1_seq'] ?? 0);
        $s2Seq = $s2Applied ? $seq : (int)($existing['s2_seq'] ?? 0);
        $s1Dev = $s1Applied ? $deviceId : (string)($existing['s1_device'] ?? '');
        $s2Dev = $s2Applied ? $deviceId : (string)($existing['s2_device'] ?? '');

        if ($existing) {
            if ($hasSeq) {
                $stmt = $conn->prepare(
                    "UPDATE collab_score_updates
                     SET s1 = ?, s2 = ?, s1_str = ?, s2_str = ?,
                         s1_seq = ?, s2_seq = ?, s1_device = ?, s2_device = ?,
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $eid = (int)$existing['id'];
                $stmt->bind_param('iissiissi', $s1Int, $s2Int, $s1Final, $s2Final, $s1Seq, $s2Seq, $s1Dev, $s2Dev, $eid);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE collab_score_updates
                     SET s1 = ?, s2 = ?, s1_str = ?, s2_str = ?, updated_at = NOW()
                     WHERE id = ?"
                );
                $eid = (int)$existing['id'];
                $stmt->bind_param('iissi', $s1Int, $s2Int, $s1Final, $s2Final, $eid);
            }
            $stmt->execute();
            $stmt->close();
        } else {
            if ($hasSeq) {
                $stmt = $conn->prepare(
                    "INSERT INTO collab_score_updates
                        (session_id, round_idx, game_idx, s1, s2, s1_str, s2_str,
                         s1_seq, s2_seq, s1_device, s2_device, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        s1 = VALUES(s1), s2 = VALUES(s2),
                        s1_str = VALUES(s1_str), s2_str = VALUES(s2_str),
                        s1_seq = VALUES(s1_seq), s2_seq = VALUES(s2_seq),
                        s1_device = VALUES(s1_device), s2_device = VALUES(s2_device),
                        updated_at = NOW()"
                );
                $stmt->bind_param(
                    'iiiiissiiss',
                    $sessionId, $roundIdx, $gameIdx, $s1Int, $s2Int, $s1Final, $s2Final,
                    $s1Seq, $s2Seq, $s1Dev, $s2Dev
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO collab_score_updates
                        (session_id, round_idx, game_idx, s1, s2, s1_str, s2_str, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        s1 = VALUES(s1), s2 = VALUES(s2),
                        s1_str = VALUES(s1_str), s2_str = VALUES(s2_str),
                        updated_at = NOW()"
                );
                $stmt->bind_param('iiiiiss', $sessionId, $roundIdx, $gameIdx, $s1Int, $s2Int, $s1Final, $s2Final);
            }
            $stmt->execute();
            $stmt->close();
        }

        return [
            's1_final'   => $s1Final,
            's2_final'   => $s2Final,
            's1_applied' => $s1Applied,
            's2_applied' => $s2Applied,
            's1_seq'     => $s1Seq,
            's2_seq'     => $s2Seq,
            'rejected'   => ($s1Provided && !$s1Applied) || ($s2Provided && !$s2Applied),
        ];
    }
}

if (!function_exists('pbnow_collab_extend_expiry')) {
    /**
     * Sliding expiry (Audit C L8): activity keeps a live session alive so a
     * late joiner is never refused while scores are still being entered.
     * Only ever extends; never shortens.
     */
    function pbnow_collab_extend_expiry($conn, int $sessionId): void {
        try {
            $stmt = $conn->prepare(
                "UPDATE collab_sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 2 HOUR)
                 WHERE id = ? AND status = 'active'
                   AND (expires_at IS NULL OR expires_at < DATE_ADD(NOW(), INTERVAL 1 HOUR))"
            );
            if ($stmt) { $stmt->bind_param('i', $sessionId); $stmt->execute(); $stmt->close(); }
        } catch (Throwable $e) { /* best effort */ }
    }
}
