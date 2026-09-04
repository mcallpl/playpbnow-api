<?php
// ============================================
// collab_update_schedule.php
// Unit A calls this after shuffling/swapping players
// Updates the schedule_json so Unit B gets the latest player assignments
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

// Verify session exists and is active
$session = dbGetRow(
    "SELECT id FROM collab_sessions WHERE id = ? AND share_code = ? AND status = 'active'",
    [$session_id, $share_code]
);

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Session not found or expired']);
    exit;
}

// Update the schedule
$scheduleJson = json_encode($schedule);
$conn = getDBConnection();
if ($reset_scores) {
    $emptyScores = '{}';
    $stmt = $conn->prepare("UPDATE collab_sessions SET schedule_json = ?, scores_json = ? WHERE id = ?");
    $stmt->bind_param('ssi', $scheduleJson, $emptyScores, $session_id);
} else {
    $stmt = $conn->prepare("UPDATE collab_sessions SET schedule_json = ? WHERE id = ?");
    $stmt->bind_param('si', $scheduleJson, $session_id);
}
$success = $stmt->execute();
$stmt->close();

$scores_cleared = 0;
if ($success && $reset_scores) {
    $del = $conn->prepare("DELETE FROM collab_score_updates WHERE session_id = ?");
    $del->bind_param('i', $session_id);
    $del->execute();
    $scores_cleared = $del->affected_rows;
    $del->close();
}
$conn->close();

if ($success) {
    echo json_encode(['status' => 'success', 'scores_reset' => $reset_scores, 'scores_cleared' => $scores_cleared]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update schedule']);
}
