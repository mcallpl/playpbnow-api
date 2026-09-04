<?php
// api/view_groups.php
// PURPOSE: detailed inspection of Group Registry and Roster integrity.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── DISABLED (UAT 2026-09-04, Audit E H6) ─────────────────────────────
// This endpoint reads the LEGACY flat-file store (pickleball_data.json) with
// no authentication and is not called by the app (everything is in MySQL
// now). It returned every group name and player name in the file to anyone.
// Hard 403 here; the original code is kept below, unreachable, per the
// additive-only rule. Remove this block only with Chip's explicit OK.
http_response_code(403);
echo json_encode(['status' => 'error', 'message' => 'This legacy endpoint is disabled.']);
exit;

$file = __DIR__ . '/pickleball_data.json';

if (!file_exists($file)) {
    echo json_encode(["status" => "ERROR", "message" => "Database file not found."]);
    exit;
}

$data = json_decode(file_get_contents($file), true);
if (!$data) $data = [];

$report = [];

// Scan every group found in the file
foreach ($data as $group_name => $content) {
    $history = $content['history'] ?? [];
    $match_count = count($history);
    
    // Scan for Unique Players (to verify if Roster is valid or "Unknown")
    $unique_players = [];
    $last_activity = "No matches yet";

    foreach ($history as $m) {
        if (!empty($m['p1_name'])) $unique_players[$m['p1_name']] = true;
        if (!empty($m['p2_name'])) $unique_players[$m['p2_name']] = true;
        if (!empty($m['p3_name'])) $unique_players[$m['p3_name']] = true;
        if (!empty($m['p4_name'])) $unique_players[$m['p4_name']] = true;
        
        if (!empty($m['date'])) $last_activity = $m['date'];
    }

    $player_list = array_keys($unique_players);
    sort($player_list);

    $report[$group_name] = [
        "match_count" => $match_count,
        "last_active" => $last_activity,
        "roster_integrity" => (in_array("Unknown", $player_list)) ? "CORRUPTED (Contains Unknowns)" : "HEALTHY",
        "players_found" => $player_list
    ];
}

echo json_encode([
    "total_groups" => count($report),
    "groups" => $report
], JSON_PRETTY_PRINT);
?>