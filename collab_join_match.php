<?php
// ============================================
// collab_join_match.php
// Unit B calls this with a 6-digit code to join the live session
// Returns the full schedule + current scores so Unit B is up to date
//
// 2026-09-04 (audit #14 / #19):
//   #14 The participant row is now written with ONE upsert instead of a
//       SELECT-then-INSERT with no transaction (which duplicated rows and
//       permanently inflated connected_users), and an optional device_id is
//       accepted so the same person on two phones counts as two devices.
//       The connected roster is returned alongside the count.
//   #19 Share-code lookups are ORDER BY id DESC LIMIT 1, so a reused code can
//       never resolve to an older row.
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
$user_id    = $input['user_id'] ?? '';

// Audit #14: an optional device id lets the same account score from two
// phones without the two heartbeats fighting over one participant row.
$identity  = pbnow_collab_identity($input);
if ($user_id === '' && $identity['user_id'] !== '') { $user_id = $identity['user_id']; }
$device_id = $identity['device_id'];

if (strlen($share_code) !== 6) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid share code format']);
    exit;
}

// ── 1. Find the active session by share code ──────────────────
// Audit #19: ORDER BY id DESC LIMIT 1 — a reused code must resolve to the
// NEWEST session, never a stale one.
$session = dbGetRow(
    "SELECT * FROM collab_sessions
     WHERE share_code = ?
     AND status = 'active'
     AND expires_at > NOW()
     ORDER BY id DESC LIMIT 1",
    [$share_code]
);

if (!$session) {
    // Do a looser lookup to return a meaningful error
    $anySession = dbGetRow(
        "SELECT id, status, expires_at FROM collab_sessions WHERE share_code = ? ORDER BY id DESC LIMIT 1",
        [$share_code]
    );
    if ($anySession) {
        $st = $anySession['status'];
        if ($st === 'finished') {
            echo json_encode(['status' => 'error', 'message' => 'This match has already been saved and closed.']);
        } elseif ($st === 'finishing') {
            // Audit #9: a device is mid-save right now.
            echo json_encode(['status' => 'error', 'message' => 'This match is being saved right now. Try again in a moment.']);
        } elseif ($st === 'active') {
            // Active but expired
            echo json_encode(['status' => 'error', 'message' => 'This session has expired. Ask the host to start a new shared session.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'This session is no longer active (status: ' . $st . ').']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Share code not found. Double-check the code and try again.']);
    }
    exit;
}

// ── 2. Track this collaborator (update heartbeat if already joined) ──
// Audit #14: this was a SELECT-then-INSERT with no transaction — two joins
// landing together inserted TWO rows and inflated connected_users forever.
// One upsert now, against the UNIQUE key from migration 006; the helper falls
// back to the old read-then-write when that migration has not been applied.
// The host keeps their 'host' role: the helper never demotes it.
pbnow_collab_touch_participant((int)$session['id'], (string)$user_id, $device_id, 'collaborator');

// ── 3. Get current scores (latest state) ──────────────────────
$latestScores = json_decode($session['scores_json'], true) ?: [];

// Also check for any score_updates that are newer than the snapshot
$updates = dbGetAll(
    "SELECT round_idx, game_idx, s1_str, s2_str FROM collab_score_updates
     WHERE session_id = ? ORDER BY updated_at DESC",
    [(int)$session['id']]
);

// Apply updates to the snapshot (latest wins for each game)
$appliedGames = [];
foreach ($updates as $u) {
    $gameKey = $u['round_idx'] . '_' . $u['game_idx'];
    if (!isset($appliedGames[$gameKey])) {
        $s1 = $u['s1_str'] ?? '';
        $s2 = $u['s2_str'] ?? '';
        if ($s1 !== '') $latestScores[$u['round_idx'] . '_' . $u['game_idx'] . '_t1'] = $s1;
        if ($s2 !== '') $latestScores[$u['round_idx'] . '_' . $u['game_idx'] . '_t2'] = $s2;
        $appliedGames[$gameKey] = true;
    }
}

// ── 4. Count connected users (active in last 30 seconds) ──────
$connected = dbGetRow(
    "SELECT COUNT(*) as cnt FROM collab_participants
     WHERE session_id = ? AND last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)"
     . pbnow_collab_participants_alive_sql(),
    [(int)$session['id']]
);

// Audit #14: WHO is connected, not just how many.
$participants = pbnow_collab_roster((int)$session['id'], 30);

// ── 5. Return everything Unit B needs ─────────────────────────
echo json_encode([
    'status' => 'success',
    'session' => [
        'id' => $session['id'],
        'batch_id' => $session['batch_id'],
        'group_name' => $session['group_name'],
        'share_code' => $session['share_code'],
        'creator_user_id' => $session['creator_user_id'] ?? ''
    ],
    'schedule' => json_decode($session['schedule_json'], true),
    'scores' => $latestScores,
    'players' => [], // Players are embedded in the schedule
    // max(1, …) preserves the old floor of 1: the joiner used to be inserted
    // even with an empty user_id, which always made the count at least one.
    // The upsert no longer writes anonymous rows, so keep the floor here.
    'connected_users' => max(1, (int)($connected['cnt'] ?? 1)),

    // ── Additive ────────────────────────────────────────────────────────────
    'participants'     => $participants,
    'seq'              => isset($session['seq_counter']) ? (int)$session['seq_counter'] : 0,
    'schedule_version' => isset($session['schedule_version']) ? (int)$session['schedule_version'] : 0,
    'scores_reset_at'  => $session['scores_reset_at'] ?? null,
]);
