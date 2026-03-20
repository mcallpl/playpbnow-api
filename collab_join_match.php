<?php
// ============================================
// collab_join_match.php
// Unit B calls this with a 6-digit code to join the live session
// Returns the full schedule + current scores so Unit B is up to date
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'No data received']);
    exit;
}

$share_code = strtoupper(trim($input['share_code'] ?? ''));
$user_id    = $input['user_id'] ?? '';

if (strlen($share_code) !== 6) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid share code format']);
    exit;
}

// ── 1. Find the active session by share code ──────────────────
$session = dbGetRow(
    "SELECT * FROM collab_sessions
     WHERE share_code = ?
     AND status = 'active'
     AND expires_at > NOW()",
    [$share_code]
);

if (!$session) {
    // Do a looser lookup to return a meaningful error
    $anySession = dbGetRow(
        "SELECT id, status, expires_at FROM collab_sessions WHERE share_code = ?",
        [$share_code]
    );
    if ($anySession) {
        $st = $anySession['status'];
        if ($st === 'finished') {
            echo json_encode(['status' => 'error', 'message' => 'This match has already been saved and closed.']);
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
$existingCollab = dbGetRow(
    "SELECT id FROM collab_participants WHERE session_id = ? AND user_id = ?",
    [(int)$session['id'], $user_id]
);

if ($existingCollab) {
    // Update last_seen
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE collab_participants SET last_seen = NOW() WHERE id = ?");
    $stmt->bind_param('i', $existingCollab['id']);
    $stmt->execute();
    $stmt->close();
    $conn->close();
} else {
    // New participant
    dbInsert(
        "INSERT INTO collab_participants (session_id, user_id, role, joined_at, last_seen)
         VALUES (?, ?, 'collaborator', NOW(), NOW())",
        [(int)$session['id'], $user_id]
    );
}

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
     WHERE session_id = ? AND last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)",
    [(int)$session['id']]
);

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
    'connected_users' => (int)($connected['cnt'] ?? 1),
]);
