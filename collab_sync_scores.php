<?php
// ============================================
// collab_sync_scores.php V3
// Stores string values as the source of truth
// Empty string = no value entered (NOT zero)
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
$round_idx  = (int)($input['round_idx'] ?? -1);
$game_idx   = (int)($input['game_idx'] ?? -1);
$s1_str     = $input['s1_str'] ?? '';
$s2_str     = $input['s2_str'] ?? '';

if (!$share_code || $round_idx < 0 || $game_idx < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// Verify session
$session = dbGetRow(
    "SELECT id FROM collab_sessions WHERE share_code = ? AND status = 'active'",
    [$share_code]
);

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Session not found or expired']);
    exit;
}

$sid = (int)$session['id'];

// Integer values derived from strings (for backward compat)
$s1_int = ($s1_str !== '') ? (int)$s1_str : 0;
$s2_int = ($s2_str !== '') ? (int)$s2_str : 0;

// Upsert
$existing = dbGetRow(
    "SELECT id, s1_str, s2_str FROM collab_score_updates WHERE session_id = ? AND round_idx = ? AND game_idx = ?",
    [$sid, $round_idx, $game_idx]
);

if ($existing) {
    // Only update fields that have real values — don't blank out existing data
    $final_s1 = ($s1_str !== '') ? $s1_str : $existing['s1_str'];
    $final_s2 = ($s2_str !== '') ? $s2_str : $existing['s2_str'];
    $final_s1_int = ($final_s1 !== '') ? (int)$final_s1 : 0;
    $final_s2_int = ($final_s2 !== '') ? (int)$final_s2 : 0;

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "UPDATE collab_score_updates SET s1 = ?, s2 = ?, s1_str = ?, s2_str = ?, updated_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('iissi', $final_s1_int, $final_s2_int, $final_s1, $final_s2, $existing['id']);
    $stmt->execute();
    $stmt->close();
    $conn->close();
} else {
    dbInsert(
        "INSERT INTO collab_score_updates (session_id, round_idx, game_idx, s1, s2, s1_str, s2_str, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$sid, $round_idx, $game_idx, $s1_int, $s2_int, $s1_str, $s2_str]
    );
}

// Update session snapshot too
$sessionData = dbGetRow("SELECT scores_json FROM collab_sessions WHERE id = ?", [$sid]);
$currentScores = json_decode($sessionData['scores_json'] ?? '{}', true) ?: [];

if ($s1_str !== '') $currentScores["{$round_idx}_{$game_idx}_t1"] = $s1_str;
if ($s2_str !== '') $currentScores["{$round_idx}_{$game_idx}_t2"] = $s2_str;

$conn = getDBConnection();
$stmt = $conn->prepare("UPDATE collab_sessions SET scores_json = ? WHERE id = ?");
$jsonScores = json_encode($currentScores);
$stmt->bind_param('si', $jsonScores, $sid);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['status' => 'success']);
