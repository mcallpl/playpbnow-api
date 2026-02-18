<?php
// api/nuke_my8.php
// PURPOSE: Force-clears the history of "My 8 Players" completely.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$file = __DIR__ . '/pickleball_data.json';

// 1. Load Data
if (!file_exists($file)) {
    die(json_encode(["status" => "ERROR", "message" => "Database file not found."]));
}
$data = json_decode(file_get_contents($file), true);
if (!$data) $data = [];

// 2. The Target
$target_group = "My 8 Players";

// 3. The Nuke
if (isset($data[$target_group])) {
    // Set history to empty array immediately. No filtering. Just delete.
    $data[$target_group]['history'] = [];
    
    // 4. Save
    if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT))) {
        echo json_encode([
            "status" => "SUCCESS 💥",
            "message" => "Successfully nuked all matches in '$target_group'.",
            "current_match_count" => 0
        ]);
    } else {
        echo json_encode(["status" => "ERROR", "message" => "Could not write to file. Check permissions."]);
    }
} else {
    echo json_encode([
        "status" => "SKIPPED", 
        "message" => "Group '$target_group' was not found in the file, so there was nothing to delete."
    ]);
}
?>