<?php
// ============================================
// collab_update_schedule.php
// Unit A calls this after shuffling/swapping players
// Updates the schedule_json so Unit B gets the latest player assignments
//
// 2026-09-04 (audit #8 / #11 / #19):
//   #8  The UPDATE and the DELETE used to be two separate statements with no
//       transaction and no row lock, so a score syncing at exactly that
//       instant survived the wipe and re-appeared on the NEW pairings. Both
//       now happen inside one transaction that holds the SAME session-row lock
//       collab_sync_scores.php takes, so a concurrent sync either lands wholly
//       before the reset (and is deleted) or wholly after it.
//       schedule_version is bumped on every schedule change, and
//       scores_reset_at is stamped only when the scores were actually wiped.
//   #11 HOST ONLY. This endpoint can erase every score in a live match, and it
//       had no authentication at all — anyone who guessed a 6-character code
//       could curl it with reset_scores:true. See collab_auth.php for why the
//       check is tolerant rather than fail-closed.
//   #19 Share-code lookups are ORDER BY id DESC LIMIT 1.
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
$session_id = (int)($input['session_id'] ?? 0);
$schedule   = $input['schedule'] ?? [];
// Audit C H5: when the host SHUFFLES mid-match the pairings change but the
// server kept the old scores, so every collaborator's next poll re-applied
// the old scores onto the new pairings. The client passes reset_scores:true
// on a shuffle; the server wipes the snapshot AND the per-game rows.
$reset_scores = !empty($input['reset_scores']);

if (!$share_code || !$session_id || empty($schedule)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// ── Audit #11: HOST ONLY. Resolved before the transaction so a rejected
//    caller never holds the session lock.
//
//    CONFIRMED EXPLOITABLE on production 2026-09-04: with nothing but the
//    share code printed on screen, an anonymous POST of
//    {"schedule":[{"games":[]}],"reset_scores":true} returned
//    {"scores_cleared":1} and every device adopted the wipe.
//
//    Three controls now stand between that curl and a live match:
//      1. session_id MUST belong to share_code. It never was validated
//         against it — it only had to be present and non-empty, so it was no
//         control at all. The lookup below (and the FOR UPDATE that follows)
//         match on BOTH, and the id we actually write is the one WE looked
//         up, never the client's number.
//      2. An IDENTIFIED caller must be the session's creator_user_id.
//      3. An UNIDENTIFIED caller (the shipped app build sends no identity, so
//         this must stay tolerated for now) may NOT reset scores at all, and
//         its schedule must prove knowledge of the match — see
//         pbnow_collab_schedule_proves_knowledge(). The exploit payload above
//         fails both.
// ─────────────────────────────────────────────────────────────────────────
$identity = pbnow_collab_identity($input);
$authSession = null;
$authLookupFailed = false;
try {
    $authSession = dbGetRow(
        "SELECT id, creator_user_id, status, schedule_json FROM collab_sessions
         WHERE id = ? AND share_code = ? ORDER BY id DESC LIMIT 1",
        [$session_id, $share_code]
    );
} catch (Throwable $e) {
    // A schema surprise is not the same as "no such session" — do not turn it
    // into a spurious "not found" for a host in the middle of a match.
    $authLookupFailed = true;
    error_log('collab_update_schedule auth lookup skipped: ' . $e->getMessage());
}

if (!$authSession && !$authLookupFailed) {
    // session_id did not belong to share_code (or the session is gone). Same
    // wording as before so nothing downstream changes.
    echo json_encode(['status' => 'error', 'message' => 'Session not found or expired']);
    exit;
}

$hostState = $authSession
    ? pbnow_collab_require_host($authSession, $identity)  // exits on a wrong user
    : 'unverified';
$reset_refused = false;

if ($hostState !== 'host') {
    // Control 3: prove knowledge of the match before touching it.
    if (!pbnow_collab_schedule_proves_knowledge($schedule, $authSession['schedule_json'] ?? null)) {
        error_log("collab_update_schedule: REJECTED unverified schedule push for session {$session_id} (no player overlap)");
        pbnow_collab_deny(
            'Only the host who started this live match can change the schedule.',
            403, 'host_only'
        );
    }
    // And never let an unverified caller destroy scores.
    if ($reset_scores) {
        error_log("collab_update_schedule: REFUSED reset_scores from unverified caller for session {$session_id}");
        $reset_scores  = false;
        $reset_refused = true;
    }
}

$scheduleJson = json_encode($schedule);

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // ── 1. Lock the session row — the SAME lock collab_sync_scores.php and
    //       collab_sync_batch.php take. A score sync racing this shuffle now
    //       queues here instead of slipping between the DELETE and the UPDATE.
    $stmt = $conn->prepare(
        "SELECT id FROM collab_sessions
         WHERE id = ? AND share_code = ? AND status = 'active'
         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('is', $session_id, $share_code);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Session not found or expired']);
        exit;
    }

    $sid = (int)$session['id'];

    // ── 2. Delete the per-game rows FIRST, inside the lock.
    $scores_cleared = 0;
    if ($reset_scores) {
        $del = $conn->prepare("DELETE FROM collab_score_updates WHERE session_id = ?");
        $del->bind_param('i', $sid);
        $del->execute();
        $scores_cleared = $del->affected_rows;
        $del->close();
    }

    // ── 3. …then the session row itself, in the same transaction.
    //       schedule_version / scores_reset_at are optional columns
    //       (migrations/006_collab_hardening.sql). If they are missing this
    //       falls back to exactly the previous statements.
    $hasVersion = pbnow_collab_has_column('collab_sessions', 'schedule_version');
    $hasResetAt = pbnow_collab_has_column('collab_sessions', 'scores_reset_at');

    $sets = ['schedule_json = ?'];
    $types = 's';
    $params = [$scheduleJson];

    if ($reset_scores) {
        $sets[] = 'scores_json = ?';
        $types .= 's';
        $params[] = '{}';
        if ($hasResetAt) { $sets[] = 'scores_reset_at = NOW(6)'; }
    }
    if ($hasVersion) { $sets[] = 'schedule_version = schedule_version + 1'; }

    $types .= 'i';
    $params[] = $sid;

    $sql = 'UPDATE collab_sessions SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) { throw new Exception('Failed to prepare schedule update: ' . $conn->error); }
    $stmt->bind_param($types, ...$params);
    $success = $stmt->execute();
    $stmt->close();

    if (!$success) { throw new Exception('Failed to update schedule'); }

    // ── 4. Read back the new version so the client can detect staleness.
    $schedule_version = null;
    $scores_reset_at  = null;
    if ($hasVersion || $hasResetAt) {
        $cols = [];
        if ($hasVersion) $cols[] = 'schedule_version';
        if ($hasResetAt) $cols[] = 'scores_reset_at';
        $stmt = $conn->prepare('SELECT ' . implode(', ', $cols) . ' FROM collab_sessions WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $sid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                if ($hasVersion) $schedule_version = (int)$row['schedule_version'];
                if ($hasResetAt) $scores_reset_at  = $row['scores_reset_at'];
            }
        }
    }

    $conn->commit();

    // Every field the old response returned is still returned.
    $out = [
        'status'           => 'success',
        'scores_reset'     => $reset_scores,
        'scores_cleared'   => $scores_cleared,
        'schedule_version' => $schedule_version,
        'scores_reset_at'  => $scores_reset_at,
    ];
    if ($reset_refused) {
        // The schedule still went through; only the destructive half was
        // refused. Told plainly so the host knows to sign in / update.
        $out['reset_refused'] = true;
        $out['code']    = 'host_verification_required';
        $out['message'] = 'Schedule updated. Scores were kept: clearing them requires the host to be signed in.';
    }
    echo json_encode($out);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('collab_update_schedule error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to update schedule']);
}
