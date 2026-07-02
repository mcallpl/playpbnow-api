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
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://peoplestar.com');
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
$round_idx  = (int)($input['round_idx'] ?? -1);
$game_idx   = (int)($input['game_idx'] ?? -1);
$s1_str     = $input['s1_str'] ?? '';
$s2_str     = $input['s2_str'] ?? '';

if (!$share_code || $round_idx < 0 || $game_idx < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// Integer values derived from strings (for backward compat)
$s1_int = ($s1_str !== '') ? (int)$s1_str : 0;
$s2_int = ($s2_str !== '') ? (int)$s2_str : 0;

$conn = getDBConnection();
$conn->begin_transaction();

try {
    // 1. Lock the session row for the duration of this sync. Concurrent syncs
    //    for the same session will queue here, serializing all read-modify-write
    //    below and eliminating the lost-update races.
    $stmt = $conn->prepare(
        "SELECT id, scores_json FROM collab_sessions WHERE share_code = ? AND status = 'active' FOR UPDATE"
    );
    $stmt->bind_param('s', $share_code);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Session not found or expired']);
        exit;
    }

    $sid = (int)$session['id'];

    // 2. Upsert the per-game score row (session already locked).
    $stmt = $conn->prepare(
        "SELECT id, s1_str, s2_str FROM collab_score_updates WHERE session_id = ? AND round_idx = ? AND game_idx = ?"
    );
    $stmt->bind_param('iii', $sid, $round_idx, $game_idx);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        // Only update fields that have real values — don't blank out existing data
        $final_s1 = ($s1_str !== '') ? $s1_str : $existing['s1_str'];
        $final_s2 = ($s2_str !== '') ? $s2_str : $existing['s2_str'];
        $final_s1_int = ($final_s1 !== '') ? (int)$final_s1 : 0;
        $final_s2_int = ($final_s2 !== '') ? (int)$final_s2 : 0;

        $stmt = $conn->prepare(
            "UPDATE collab_score_updates SET s1 = ?, s2 = ?, s1_str = ?, s2_str = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('iissi', $final_s1_int, $final_s2_int, $final_s1, $final_s2, $existing['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO collab_score_updates (session_id, round_idx, game_idx, s1, s2, s1_str, s2_str, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('iiiiiss', $sid, $round_idx, $game_idx, $s1_int, $s2_int, $s1_str, $s2_str);
        $stmt->execute();
        $stmt->close();
    }

    // 3. Merge into the session snapshot blob (read value was locked in step 1).
    $currentScores = json_decode($session['scores_json'] ?? '{}', true) ?: [];
    if ($s1_str !== '') $currentScores["{$round_idx}_{$game_idx}_t1"] = $s1_str;
    if ($s2_str !== '') $currentScores["{$round_idx}_{$game_idx}_t2"] = $s2_str;

    $jsonScores = json_encode($currentScores);
    $stmt = $conn->prepare("UPDATE collab_sessions SET scores_json = ? WHERE id = ?");
    $stmt->bind_param('si', $jsonScores, $sid);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['status' => 'success']);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('collab_sync_scores error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Sync failed, please retry']);
}
