<?php
// ============================================
// collab_create_session.php
// Unit A calls this to create a share code for live collaboration
// ============================================

// Catch ALL errors and return as JSON
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$raw = file_get_contents('php://input');
error_log("COLLAB_CREATE: Raw input length: " . strlen($raw));

$input = json_decode($raw, true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'No data received', 'raw' => substr($raw, 0, 200)]);
    exit;
}

$batch_id   = $input['batch_id'] ?? '';
$group_name = $input['group_name'] ?? '';
$schedule   = $input['schedule'] ?? [];
$scores     = $input['scores'] ?? [];

error_log("COLLAB_CREATE: batch_id=$batch_id, group_name=$group_name, schedule_count=" . count($schedule));

if (!$batch_id || !$group_name) {
    echo json_encode(['status' => 'error', 'message' => 'Missing batch_id or group_name']);
    exit;
}

// ── 1. Generate unique 6-character alphanumeric code ──────────
function generateShareCode() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

// ── 2. Check if collab_sessions table exists ──────────────────
$conn = getDBConnection();
$tableCheck = $conn->query("SHOW TABLES LIKE 'collab_sessions'");
if ($tableCheck->num_rows === 0) {
    error_log("COLLAB_CREATE: Table collab_sessions does NOT exist!");
    echo json_encode(['status' => 'error', 'message' => 'Collaboration tables not found. Run collab_migration.sql first.']);
    $conn->close();
    exit;
}
$conn->close();

// ── 3. Check if session already has a collab record ──────────
$existingSession = dbGetRow(
    "SELECT id, share_code FROM collab_sessions WHERE batch_id = ? AND status = 'active'",
    [$batch_id]
);

if ($existingSession) {
    error_log("COLLAB_CREATE: Found existing session, code=" . $existingSession['share_code']);
    echo json_encode([
        'status' => 'success',
        'share_code' => $existingSession['share_code'],
        'session_id' => $existingSession['id'],
        'message' => 'Existing session found'
    ]);
    exit;
}

// ── 4. Make sure code is unique ───────────────────────────────
$maxAttempts = 10;
$shareCode = '';
for ($i = 0; $i < $maxAttempts; $i++) {
    $candidate = generateShareCode();
    $existing = dbGetRow(
        "SELECT id FROM collab_sessions WHERE share_code = ? AND status = 'active'",
        [$candidate]
    );
    if (!$existing) {
        $shareCode = $candidate;
        break;
    }
}

if (!$shareCode) {
    echo json_encode(['status' => 'error', 'message' => 'Could not generate unique code']);
    exit;
}

error_log("COLLAB_CREATE: Generated share code: $shareCode");

// ── 5. Create the collab session ──────────────────────────────
$scheduleJson = json_encode($schedule);
$scoresJson = json_encode($scores);

$sessionId = dbInsert(
    "INSERT INTO collab_sessions (batch_id, group_name, share_code, schedule_json, scores_json, status, created_at, expires_at)
     VALUES (?, ?, ?, ?, ?, 'active', NOW(), DATE_ADD(NOW(), INTERVAL 12 HOUR))",
    [
        $batch_id,
        $group_name,
        $shareCode,
        $scheduleJson,
        $scoresJson
    ]
);

if (!$sessionId) {
    error_log("COLLAB_CREATE: dbInsert FAILED");
    echo json_encode(['status' => 'error', 'message' => 'Failed to create session - database insert failed']);
    exit;
}

error_log("COLLAB_CREATE: SUCCESS! session_id=$sessionId, share_code=$shareCode");

echo json_encode([
    'status' => 'success',
    'share_code' => $shareCode,
    'session_id' => $sessionId,
    'expires_in' => '12 hours'
]);

} catch (Exception $e) {
    error_log("COLLAB_CREATE EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
