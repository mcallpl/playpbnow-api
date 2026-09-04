<?php
// api/get_group_details.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── DISABLED (UAT 2026-09-04, Audit E H6) ─────────────────────────────
// Reads the LEGACY flat-file store (pickleball_data.json) with no
// authentication; not used by the app (rosters live in MySQL). Hard 403;
// the original code is kept below, unreachable, per the additive-only rule.
http_response_code(403);
echo json_encode(['status' => 'error', 'message' => 'This legacy endpoint is disabled.']);
exit;

$storage_file = '../pickleball_data.json';
$group_name = $_GET['group'] ?? '';

if (file_exists($storage_file) && $group_name) {
    $data = json_decode(file_get_contents($storage_file), true);
    
    // Return the players for this specific group
    if (isset($data[$group_name]['players'])) {
        echo json_encode($data[$group_name]['players']);
        exit;
    }
}

// If nothing found, return empty
echo json_encode([]);
?>