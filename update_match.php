<?php

// ============================================
// update_match.php - DATABASE VERSION
// Updates match scores and player names
// Propagates name changes across the session
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$match_id  = (int)($input['match_id'] ?? 0);
$group_key = $input['group'] ?? '';

if (!$match_id || !$group_key) {
    echo json_encode(['status' => 'error', 'message' => 'Missing identifiers']);
    exit;
}

// Resolve group_id - try by group_key first, then fall back to name
$group = dbGetRow("SELECT id FROM `groups` WHERE group_key = ? OR name = ?", [$group_key, $group_key]);
if (!$group) {
    echo json_encode(['status' => 'error', 'message' => 'Group not found']);
    exit;
}
$group_id = (int)$group['id'];

// Find match by ID
$match = dbGetRow("SELECT * FROM matches WHERE id = ? AND group_id = ?", [$match_id, $group_id]);
if (!$match) {
    echo json_encode(['status' => 'error', 'message' => 'Match not found']);
    exit;
}

$session_id = $match['session_id'] ?? null;

// Gather inputs
$p1_id = $input['p1'] ?? '';
$p2_id = $input['p2'] ?? '';
$p3_id = $input['p3'] ?? '';
$p4_id = $input['p4'] ?? '';

$new_s1 = (int)($input['new_s1'] ?? 0);
$new_s2 = (int)($input['new_s2'] ?? 0);

// Build new_names safely
$new_names = [];
if (!empty($p1_id) && isset($input['p1_name'])) $new_names[$p1_id] = trim($input['p1_name']);
if (!empty($p2_id) && isset($input['p2_name'])) $new_names[$p2_id] = trim($input['p2_name']);
if (!empty($p3_id) && isset($input['p3_name'])) $new_names[$p3_id] = trim($input['p3_name']);
if (!empty($p4_id) && isset($input['p4_name'])) $new_names[$p4_id] = trim($input['p4_name']);

// Preserve existing names if no valid mapping provided
$p1_name_final = (!empty($p1_id) && isset($new_names[$p1_id])) ? $new_names[$p1_id] : $match['p1_name'];
$p2_name_final = (!empty($p2_id) && isset($new_names[$p2_id])) ? $new_names[$p2_id] : $match['p2_name'];
$p3_name_final = (!empty($p3_id) && isset($new_names[$p3_id])) ? $new_names[$p3_id] : $match['p3_name'];
$p4_name_final = (!empty($p4_id) && isset($new_names[$p4_id])) ? $new_names[$p4_id] : $match['p4_name'];

// Update the match (Scores + Names)
$conn = getDBConnection();
$stmt = $conn->prepare(
    "UPDATE matches 
     SET s1 = ?, s2 = ?, p1_name = ?, p2_name = ?, p3_name = ?, p4_name = ?
     WHERE id = ?"
);
$stmt->bind_param('iissssi', $new_s1, $new_s2, $p1_name_final, $p2_name_final, $p3_name_final, $p4_name_final, $match_id);
$stmt->execute();
$stmt->close();
$conn->close();

// Propagate name changes across the session
if ($session_id && !empty($new_names)) {
    foreach ($new_names as $player_key => $new_name) {
        if (empty($new_name) || empty($player_key)) continue;
        
        // Update p1
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p1_name = ? WHERE session_id = ? AND p1_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Update p2
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p2_name = ? WHERE session_id = ? AND p2_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Update p3
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p3_name = ? WHERE session_id = ? AND p3_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        
        // Update p4
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE matches SET p4_name = ? WHERE session_id = ? AND p4_key = ?");
        $stmt->bind_param('sss', $new_name, $session_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
}

// Update master roster
if (!empty($new_names)) {
    foreach ($new_names as $player_key => $new_name) {
        if (empty($new_name) || empty($player_key)) continue;
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE players SET first_name = ? WHERE group_id = ? AND player_key = ?");
        $stmt->bind_param('sis', $new_name, $group_id, $player_key);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
}

echo json_encode([
    'status'  => 'success',
    'message' => 'Match updated'
]);
?>