<?php
// ============================================
// collab_get_scores.php V3
// Returns string values as the source of truth
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$share_code = strtoupper(trim($_GET['share_code'] ?? ''));
$since      = (int)($_GET['since'] ?? 0);
$user_id    = $_GET['user_id'] ?? '';

if (!$share_code) {
    echo json_encode(['status' => 'error', 'message' => 'Missing share_code']);
    exit;
}

// Find active or finished session
$session = dbGetRow(
    "SELECT id, status, group_name, saved_session_id FROM collab_sessions WHERE share_code = ? AND status IN ('active', 'finished')",
    [$share_code]
);

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Session not found or expired']);
    exit;
}

$sid = (int)$session['id'];

// Update heartbeat
if ($user_id) {
    $existingParticipant = dbGetRow(
        "SELECT id FROM collab_participants WHERE session_id = ? AND user_id = ?",
        [$sid, $user_id]
    );
    if ($existingParticipant) {
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE collab_participants SET last_seen = NOW() WHERE id = ?");
        $stmt->bind_param('i', $existingParticipant['id']);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } else {
        // Auto-register as participant
        dbInsert(
            "INSERT INTO collab_participants (session_id, user_id, role, joined_at, last_seen) VALUES (?, ?, 'collaborator', NOW(), NOW())",
            [$sid, $user_id]
        );
    }
}

// Get score updates
if ($since === 0) {
    // Full pull — get everything
    $updates = dbGetAll(
        "SELECT round_idx, game_idx, s1_str, s2_str, UNIX_TIMESTAMP(updated_at) as ts 
         FROM collab_score_updates 
         WHERE session_id = ?
         ORDER BY updated_at ASC",
        [$sid]
    );
} else {
    // Incremental — only newer than last poll
    $sinceDate = date('Y-m-d H:i:s', intval($since / 1000));
    $updates = dbGetAll(
        "SELECT round_idx, game_idx, s1_str, s2_str, UNIX_TIMESTAMP(updated_at) as ts 
         FROM collab_score_updates 
         WHERE session_id = ? AND updated_at > ?
         ORDER BY updated_at ASC",
        [$sid, $sinceDate]
    );
}

// Count connected users (active in last 30s)
$connected = dbGetRow(
    "SELECT COUNT(*) as cnt FROM collab_participants 
     WHERE session_id = ? AND last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)",
    [$sid]
);

// Latest timestamp
$latestTs = 0;
foreach ($updates as $u) {
    if ((int)$u['ts'] > $latestTs) $latestTs = (int)$u['ts'];
}

$sessionStatus = $session['status']; // 'active' or 'finished'

echo json_encode([
    'status' => 'success',
    'session_status' => $sessionStatus,
    'group_name' => $session['group_name'],
    'saved_session_id' => $session['saved_session_id'] ?? null,
    'updates' => $updates,
    'connected_users' => (int)($connected['cnt'] ?? 0),
    'latest_timestamp' => $latestTs > 0 ? $latestTs * 1000 : $since,
    'session_active' => ($sessionStatus === 'active')
]);
