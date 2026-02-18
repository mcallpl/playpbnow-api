<?php
// api/purge_scores.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Optional: Pass ?group=GroupName to purge only one group.
// If no group is passed, it purges scores for EVERY group.
$group = $_GET['group'] ?? ''; 

$file = '../pickleball_data.json';

if (!file_exists($file)) {
    echo json_encode(['status' => 'error', 'message' => 'Database file not found.']);
    exit;
}

$data = json_decode(file_get_contents($file), true);

if ($group && isset($data[$group])) {
    // Target specific group
    $data[$group]['history'] = []; // Clear History ONLY
    $message = "Successfully purged scores for group: " . $group;
} else {
    // Target ALL groups (Clean Slate)
    foreach ($data as $key => $val) {
        $data[$key]['history'] = []; // Clear History ONLY
    }
    $message = "Successfully purged scores for ALL groups.";
}

// Save back to file
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

echo json_encode(['status' => 'success', 'message' => $message]);
?>