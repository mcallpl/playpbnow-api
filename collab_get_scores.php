<?php
// ============================================
// collab_get_scores.php V3
// Returns string values as the source of truth
//
// 2026-09-04 (audit #2 / #8 / #14 / #15 / #19):
//   #2  Each update row now carries its server-issued sequence (s1_seq/s2_seq)
//       and the response carries the session's current `seq`, so a client can
//       send an honest last_seen_seq on its next write and a delayed retry can
//       no longer clobber a newer value from another scorer.
//   #8  schedule_version + scores_reset_at are returned so a client can tell
//       its pairings are stale after a host shuffle.
//   #14 `participants` (user_id, role, first_name, last_seen) is returned
//       ALONGSIDE the existing `connected_users` integer — the UI promises
//       "see who's connected" and only ever got a number. The heartbeat is now
//       a single upsert instead of a SELECT-then-INSERT that duplicated rows.
//   #15 expires_at is finally enforced here. This endpoint used to select
//       without any expiry check and then EXTEND the expiry, so a session
//       could never expire on the scoring path while collab_join_match.php
//       enforced it strictly — a friend was told "expired" while the host's
//       screen looked perfectly healthy. One rule now: if an active session
//       has genuinely passed expires_at, mark it expired and say so;
//       otherwise keep the existing sliding extension.
//   #19 The share_code lookup is ORDER BY id DESC LIMIT 1. It selected
//       status IN ('active','finished') with no ordering, so a reused code
//       could return the OLD finished row and instantly "finish" a brand-new
//       match on every device.
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/collab_auth.php';

$share_code = strtoupper(trim($_GET['share_code'] ?? ''));
$since      = (int)($_GET['since'] ?? 0);
$user_id    = $_GET['user_id'] ?? '';
$device_id  = trim((string)($_GET['device_id'] ?? ''));

if (!$share_code) {
    echo json_encode(['status' => 'error', 'message' => 'Missing share_code']);
    exit;
}

// Find active or finished session. `SELECT *` so the optional columns from
// migration 006 come along when they exist and are simply absent when they
// do not — no schema-dependent column list to break on a lagging deploy.
// 'finishing' is included so a device polling during another device's save
// sees the truth instead of "Session not found or expired".
$session = dbGetRow(
    "SELECT * FROM collab_sessions
     WHERE share_code = ? AND status IN ('active', 'finishing', 'finished')
     ORDER BY id DESC LIMIT 1",
    [$share_code]
);

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Session not found or expired']);
    exit;
}

$sid = (int)$session['id'];
$sessionStatus = $session['status']; // 'active', 'finishing' or 'finished'
$expiredNow = false;

// ── Audit #15: one expiry rule ────────────────────────────────────────────
// A genuinely-passed expires_at ends the session here too, instead of this
// endpoint silently extending a dead session forever. Note the sliding
// extension below means this only fires when NOBODY has polled or scored for
// over an hour, so it cannot cut a live match short.
if ($sessionStatus === 'active') {
    $exp = $session['expires_at'] ?? null;
    if ($exp !== null && $exp !== '' && $exp !== '0000-00-00 00:00:00' && strtotime($exp) < time()) {
        $expiredNow = true;
        $sessionStatus = 'expired';
        try {
            // UNIQUE KEY (share_code, status) means this can collide with an
            // older expired row for a reused code. Failing to record it is
            // harmless — the response below already reports the truth.
            dbQuery("UPDATE collab_sessions SET status = 'expired' WHERE id = ? AND status = 'active'", [$sid]);
        } catch (Throwable $e) {
            error_log('collab_get_scores expire-mark skipped: ' . $e->getMessage());
        }
    }
}

// Audit C L8: keep an ACTIVE session alive while anyone is polling it, so a
// late joiner is never refused by collab_join_match.php's expires_at check
// while scores are still flowing. Only ever extends; never shortens.
if ($sessionStatus === 'active') {
    try {
        dbQuery(
            "UPDATE collab_sessions
             SET expires_at = DATE_ADD(NOW(), INTERVAL 2 HOUR)
             WHERE id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at < DATE_ADD(NOW(), INTERVAL 1 HOUR))",
            [$sid]
        );
    } catch (Throwable $e) { /* best effort */ }
}

// Update heartbeat.
// Audit #14: this was a SELECT-then-INSERT with no transaction, so two polls
// landing together inserted TWO rows and inflated connected_users forever.
// pbnow_collab_touch_participant() is a single INSERT ... ON DUPLICATE KEY
// UPDATE against the UNIQUE key added by migration 006, and falls back to the
// old read-then-write when that migration has not been applied.
if ($user_id) {
    pbnow_collab_touch_participant($sid, (string)$user_id, $device_id, 'collaborator');
}

// Audit #2: return each row's sequence numbers when they exist.
$hasSeq = pbnow_collab_has_column('collab_score_updates', 's1_seq');
$seqCols = $hasSeq ? ", s1_seq, s2_seq, s1_device, s2_device" : "";

// Get score updates
if ($since === 0) {
    // Full pull — get everything
    $updates = dbGetAll(
        "SELECT round_idx, game_idx, s1_str, s2_str, UNIX_TIMESTAMP(updated_at) as ts{$seqCols}
         FROM collab_score_updates
         WHERE session_id = ?
         ORDER BY updated_at ASC",
        [$sid]
    );
} else {
    // Incremental — subtract 1s to catch same-second updates (client deduplicates)
    $sinceDate = date('Y-m-d H:i:s', max(0, intval($since / 1000) - 1));
    $updates = dbGetAll(
        "SELECT round_idx, game_idx, s1_str, s2_str, UNIX_TIMESTAMP(updated_at) as ts{$seqCols}
         FROM collab_score_updates
         WHERE session_id = ? AND updated_at > ?
         ORDER BY updated_at ASC",
        [$sid, $sinceDate]
    );
}

// Count connected users (active in last 30s)
$connected = dbGetRow(
    "SELECT COUNT(*) as cnt FROM collab_participants
     WHERE session_id = ? AND last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)"
     . pbnow_collab_participants_alive_sql(),
    [$sid]
);

// Audit #14: WHO is connected, not just how many.
$participants = pbnow_collab_roster($sid, 30);

// Latest timestamp
$latestTs = 0;
foreach ($updates as $u) {
    if ((int)$u['ts'] > $latestTs) $latestTs = (int)$u['ts'];
}

// Every field this endpoint has ever returned is still returned, unchanged.
$response = [
    'status' => 'success',
    'session_status' => $sessionStatus,
    'group_name' => $session['group_name'],
    'saved_session_id' => $session['saved_session_id'] ?? null,
    'updates' => $updates,
    'schedule' => json_decode($session['schedule_json'], true),
    'connected_users' => (int)($connected['cnt'] ?? 0),
    'latest_timestamp' => $latestTs > 0 ? $latestTs * 1000 : $since,
    'session_active' => ($sessionStatus === 'active'),

    // ── Additive ────────────────────────────────────────────────────────────
    'participants'     => $participants,
    'seq'              => isset($session['seq_counter']) ? (int)$session['seq_counter'] : 0,
    'schedule_version' => isset($session['schedule_version']) ? (int)$session['schedule_version'] : 0,
    'scores_reset_at'  => $session['scores_reset_at'] ?? null,
    'expires_at'       => $session['expires_at'] ?? null,
];

if ($expiredNow) {
    $response['expired'] = true;
    $response['message'] = 'This live session expired. Your scores are safe on this device — start a new shared session to keep syncing.';
}

echo json_encode($response);
