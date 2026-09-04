<?php
// ============================================
// collab_sync_scores.php V4
// Stores string values as the source of truth
// Empty string = no value entered (NOT zero)
//
// V4: all writes for a session now run inside a single transaction that locks
// the session row (SELECT ... FOR UPDATE) up front. This serializes concurrent
// score syncs for the same live match, fixing two lost-update races that
// existed before: (1) duplicate/overwriting rows in collab_score_updates and
// (2) the scores_json read-modify-write blob clobbering another scorer's edit.
//
// V5 (2026-09-04, audit #2/#11/#19):
//   - ORDERING: the client's `updated_at: Date.now()` was accepted and then
//     ignored, so a delayed or retried write silently overwrote a NEWER value
//     from another scorer. Writes now carry a SERVER-issued sequence
//     (collab_sessions.seq_counter) and a field is rejected only when the
//     stored value came from a different device more recently than the
//     caller's `last_seen_seq`. A caller that sends no last_seen_seq is always
//     accepted, so older app builds behave exactly as they always have.
//   - AUTH: the caller must be a participant (or the host). Tolerant by
//     design — see collab_auth.php's header.
//   - Share-code lookup is ORDER BY id DESC LIMIT 1 so a reused code can never
//     resolve to an older row.
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/collab_auth.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'No data received']);
    exit;
}

$share_code = strtoupper(trim($input['share_code'] ?? ''));
$round_idx  = (int)($input['round_idx'] ?? -1);
$game_idx   = (int)($input['game_idx'] ?? -1);
$s1_str     = $input['s1_str'] ?? '';
$s2_str     = $input['s2_str'] ?? '';

// Was the field actually sent? An empty string that WAS sent means "the user
// erased this score"; a field that was NOT sent means "don't touch it".
// Conflating the two made a score impossible to erase — see the block below.
$s1_provided = array_key_exists('s1_str', $input);
$s2_provided = array_key_exists('s2_str', $input);

if (!$share_code || $round_idx < 0 || $game_idx < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// Integer values derived from strings (for backward compat)
$s1_int = ($s1_str !== '') ? (int)$s1_str : 0;
$s2_int = ($s2_str !== '') ? (int)$s2_str : 0;

// Audit #2: ordering inputs. `last_seen_seq` is the highest sequence number
// this device has seen from collab_get_scores.php. ABSENT (older builds) means
// "no opinion" and every write is accepted — no regression.
$last_seen_seq = array_key_exists('last_seen_seq', $input) && $input['last_seen_seq'] !== null
    ? (int)$input['last_seen_seq']
    : null;

// Audit #11 / #14: who is calling, and from which device.
$identity  = pbnow_collab_identity($input);
$device_id = $identity['device_id'];

// Audit #11: authenticate BEFORE opening the transaction so a rejected caller
// never holds the session row lock.
$authSession = null;
try {
    $authSession = dbGetRow(
        "SELECT id, creator_user_id, status FROM collab_sessions
         WHERE share_code = ? ORDER BY id DESC LIMIT 1",
        [$share_code]
    );
} catch (Throwable $e) {
    // A schema surprise must never block scoring — fall through unauthenticated
    // exactly as this endpoint behaved before the auth check existed.
    error_log('collab_sync_scores auth lookup skipped: ' . $e->getMessage());
}
if ($authSession) {
    pbnow_collab_require_participant($authSession, $identity);
}

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // 1. Lock the session row for the duration of this sync. Concurrent syncs
    //    for the same session will queue here, serializing all read-modify-write
    //    below and eliminating the lost-update races.
    // Audit #19: ORDER BY id DESC LIMIT 1. Share codes can be reused once the
    // old session is no longer active, and an unordered lookup could bind to
    // the older row.
    $stmt = $conn->prepare(
        "SELECT * FROM collab_sessions WHERE share_code = ? AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('s', $share_code);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $conn->rollback();
        // Say WHICH it is. "Not found or expired" was shown even when the match
        // had simply been finished by another scorer, which reads like data
        // loss to someone who just typed a score. (UAT 2026-09-04)
        $probe = $conn->prepare("SELECT status FROM collab_sessions WHERE share_code = ? ORDER BY id DESC LIMIT 1");
        $probe->bind_param('s', $share_code);
        $probe->execute();
        $probeRow = $probe->get_result()->fetch_assoc();
        $probe->close();
        $msg = 'Live session not found. Your scores are safe on this device.';
        if ($probeRow && $probeRow['status'] === 'finished') {
            $msg = 'This match was already finished by another device.';
        } elseif ($probeRow && $probeRow['status'] === 'finishing') {
            // Audit #9: another device is mid-save right now. Not an error the
            // scorer caused, and not data loss.
            $msg = 'Another device is saving this match right now.';
        } elseif ($probeRow) {
            $msg = 'This live session has ended. Your scores are safe on this device.';
        }
        echo json_encode(['status' => 'error', 'message' => $msg, 'session_status' => $probeRow['status'] ?? null]);
        exit;
    }

    $sid = (int)$session['id'];

    // 1b. Take the next server-issued sequence number for this session (audit
    //     #2). One per request. Returns 0 — and every guard below becomes a
    //     no-op — when migration 006 has not been applied yet.
    $seq = pbnow_collab_next_seq($conn, $sid);

    // 2. Upsert the per-game score row (session already locked).
    //
    //    The per-field rules live in pbnow_collab_apply_score_write():
    //
    //    (a) Take any field the caller actually sent, INCLUDING an empty one.
    //        This used to be `($s1_str !== '') ? $s1_str : $existing['s1_str']`,
    //        which treated "" as "nothing supplied" and therefore made a score
    //        impossible to erase: a scorer who typed 11-7 onto the wrong court
    //        cleared the box, saw it go blank, and then watched the old value
    //        reappear on every device on the next poll — because the server had
    //        kept it and kept re-broadcasting it. (UAT 2026-09-04)
    //        A field that is genuinely absent from the payload is still left
    //        alone, which preserves the original protective intent.
    //
    //    (b) Audit #2: reject a field only when the stored value was written by
    //        a DIFFERENT device more recently than this caller's last_seen_seq.
    //        Without last_seen_seq (older builds) everything is accepted.
    $write = pbnow_collab_apply_score_write(
        $conn, $sid, $round_idx, $game_idx,
        $s1_provided, (string)$s1_str, $s2_provided, (string)$s2_str,
        $last_seen_seq, $device_id, $seq
    );

    $final_s1 = $write['s1_final'];
    $final_s2 = $write['s2_final'];

    // 3. Merge into the session snapshot blob (read value was locked in step 1).
    //    Same rule as above: a cleared score must be REMOVED from the snapshot,
    //    or a late joiner would be handed the stale value the scorer deleted.
    //    The FINAL values are merged, so a write rejected by the ordering guard
    //    writes the winning value back instead of the stale one.
    $currentScores = json_decode($session['scores_json'] ?? '{}', true) ?: [];
    $currentScores = pbnow_collab_merge_snapshot(
        $currentScores, $round_idx, $game_idx,
        $s1_provided, $final_s1, $s2_provided, $final_s2
    );

    $jsonScores = json_encode($currentScores);
    $stmt = $conn->prepare("UPDATE collab_sessions SET scores_json = ? WHERE id = ?");
    $stmt->bind_param('si', $jsonScores, $sid);
    $stmt->execute();
    $stmt->close();

    // Audit C L8: activity extends the session so joins are never refused
    // while scores are still being entered. Only ever extends.
    $stmt = $conn->prepare(
        "UPDATE collab_sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 2 HOUR)
         WHERE id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at < DATE_ADD(NOW(), INTERVAL 1 HOUR))"
    );
    if ($stmt) { $stmt->bind_param('i', $sid); $stmt->execute(); $stmt->close(); }

    $conn->commit();

    // Audit #14: the scorer is a participant. Recorded AFTER the commit so a
    // heartbeat can never hold the session lock or fail the score write.
    if (($identity['user_id'] ?? '') !== '') {
        pbnow_collab_touch_participant($sid, (string)$identity['user_id'], $device_id, 'collaborator');
    }

    // Every pre-existing field is still returned; the rest is additive so the
    // client can send a correct last_seen_seq on its next write.
    echo json_encode([
        'status'     => 'success',
        'seq'        => $seq,
        's1_str'     => $final_s1,
        's2_str'     => $final_s2,
        's1_seq'     => $write['s1_seq'],
        's2_seq'     => $write['s2_seq'],
        's1_applied' => $write['s1_applied'],
        's2_applied' => $write['s2_applied'],
        // True when a newer value from another device won — the client should
        // adopt s1_str/s2_str above rather than re-sending.
        'superseded' => $write['rejected'],
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('collab_sync_scores error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Sync failed, please retry']);
}
