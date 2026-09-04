<?php
// ============================================================================
// collab_sync_batch.php  (NEW — audit finding #16, "thundering herd")
//
// The client's pushAllScoresToServer() fires ONE collab_sync_scores.php
// request per game — up to 24 concurrent POSTs on a full schedule. Since V4
// every one of them takes `SELECT ... FOR UPDATE` on the SAME session row, so
// 24 requests serialize on one lock, each holding a PHP-FPM worker while it
// waits. On a busy court that is a self-inflicted stampede.
//
// This endpoint applies the whole set in ONE transaction under ONE row lock:
//   POST {
//     share_code:    "ABCDEF",
//     user_id:       "123",          // optional; Bearer token wins if present
//     device_id:     "…",            // optional but recommended (ordering)
//     last_seen_seq: 42,             // optional; see collab_auth.php
//     games: [ { round_idx, game_idx, s1_str, s2_str }, … ]
//   }
//
// A game object omitting s1_str (or s2_str) leaves that field untouched;
// sending it as "" CLEARS it — identical to collab_sync_scores.php.
//
// collab_sync_scores.php is untouched in behaviour and keeps working for
// older clients. This is purely an additional, faster path.
// ============================================================================

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
$games      = $input['games'] ?? [];

if (!$share_code || !is_array($games)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// Sanity cap: a full 8-round / 3-court schedule is 24 games. 200 is generous
// and stops a single request from holding the session lock indefinitely.
if (count($games) > 200) {
    echo json_encode(['status' => 'error', 'message' => 'Too many games in one batch (max 200)']);
    exit;
}

$last_seen_seq = array_key_exists('last_seen_seq', $input) && $input['last_seen_seq'] !== null
    ? (int)$input['last_seen_seq']
    : null;

$identity  = pbnow_collab_identity($input);
$device_id = $identity['device_id'];

// ── Audit #11: authenticate before taking the lock ──────────────────────────
$authSession = null;
try {
    $authSession = dbGetRow(
        "SELECT id, creator_user_id, status FROM collab_sessions
         WHERE share_code = ? ORDER BY id DESC LIMIT 1",
        [$share_code]
    );
} catch (Throwable $e) {
    error_log('collab_sync_batch auth lookup skipped: ' . $e->getMessage());
}
if ($authSession) {
    pbnow_collab_require_participant($authSession, $identity);
}

if (empty($games)) {
    echo json_encode(['status' => 'success', 'applied' => 0, 'results' => [], 'seq' => 0]);
    exit;
}

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // 1. Lock the session row ONCE for the whole batch (audit #19 ordering).
    $stmt = $conn->prepare(
        "SELECT * FROM collab_sessions WHERE share_code = ? AND status = 'active' ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('s', $share_code);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $conn->rollback();
        // Same wording contract as collab_sync_scores.php: say WHICH it is, so
        // a scorer never reads a finished match as data loss.
        $probe = $conn->prepare("SELECT status FROM collab_sessions WHERE share_code = ? ORDER BY id DESC LIMIT 1");
        $probe->bind_param('s', $share_code);
        $probe->execute();
        $probeRow = $probe->get_result()->fetch_assoc();
        $probe->close();
        $msg = 'Live session not found. Your scores are safe on this device.';
        if ($probeRow && $probeRow['status'] === 'finished') {
            $msg = 'This match was already finished by another device.';
        } elseif ($probeRow && $probeRow['status'] === 'finishing') {
            $msg = 'Another device is saving this match right now.';
        } elseif ($probeRow) {
            $msg = 'This live session has ended. Your scores are safe on this device.';
        }
        echo json_encode(['status' => 'error', 'message' => $msg, 'session_status' => $probeRow['status'] ?? null]);
        exit;
    }

    $sid = (int)$session['id'];

    // 2. ONE sequence number for the whole batch — every field written here was
    //    decided at the same instant by the same device, so they share it.
    $seq = pbnow_collab_next_seq($conn, $sid);

    $currentScores = json_decode($session['scores_json'] ?? '{}', true) ?: [];

    $results  = [];
    $applied  = 0;
    $rejected = 0;

    foreach ($games as $g) {
        if (!is_array($g)) continue;
        $r = (int)($g['round_idx'] ?? -1);
        $i = (int)($g['game_idx'] ?? -1);
        if ($r < 0 || $i < 0) continue;

        // Absent vs empty, exactly as in collab_sync_scores.php.
        $p1 = array_key_exists('s1_str', $g);
        $p2 = array_key_exists('s2_str', $g);
        if (!$p1 && !$p2) continue;

        $v1 = (string)($g['s1_str'] ?? '');
        $v2 = (string)($g['s2_str'] ?? '');

        $write = pbnow_collab_apply_score_write(
            $conn, $sid, $r, $i, $p1, $v1, $p2, $v2,
            $last_seen_seq, $device_id, $seq
        );

        $currentScores = pbnow_collab_merge_snapshot(
            $currentScores, $r, $i, $p1, $write['s1_final'], $p2, $write['s2_final']
        );

        if ($write['rejected']) { $rejected++; } else { $applied++; }

        $results[] = [
            'round_idx'  => $r,
            'game_idx'   => $i,
            's1_str'     => $write['s1_final'],
            's2_str'     => $write['s2_final'],
            's1_seq'     => $write['s1_seq'],
            's2_seq'     => $write['s2_seq'],
            's1_applied' => $write['s1_applied'],
            's2_applied' => $write['s2_applied'],
            'superseded' => $write['rejected'],
        ];
    }

    // 3. ONE snapshot write for the whole batch.
    $jsonScores = json_encode($currentScores);
    $stmt = $conn->prepare("UPDATE collab_sessions SET scores_json = ? WHERE id = ?");
    $stmt->bind_param('si', $jsonScores, $sid);
    $stmt->execute();
    $stmt->close();

    // Sliding expiry, same rule as the single-game endpoint (Audit C L8).
    pbnow_collab_extend_expiry($conn, $sid);

    $conn->commit();

    if (($identity['user_id'] ?? '') !== '') {
        pbnow_collab_touch_participant($sid, (string)$identity['user_id'], $device_id, 'collaborator');
    }

    echo json_encode([
        'status'   => 'success',
        'seq'      => $seq,
        'applied'  => $applied,
        'rejected' => $rejected,
        'results'  => $results,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('collab_sync_batch error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Sync failed, please retry']);
}
