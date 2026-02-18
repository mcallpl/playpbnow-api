<?php
// api/fix_ghost_data.php
// RUN THIS ONCE to recover your hidden matches

header('Content-Type: text/plain');

// 1. Target the correct file in the API folder
$file = __DIR__ . '/pickleball_data.json';

if (!file_exists($file)) {
    die("Error: Data file not found.");
}

$data = json_decode(file_get_contents($file), true);

// 2. Define the Ghost (Empty) and the Target (Real Name)
$ghost_key = ""; // The empty name where data is hiding
$target_group = "My 8 Players"; // The name you want (Change this if needed!)

if (isset($data[$ghost_key])) {
    $ghost_matches = $data[$ghost_key]['history'] ?? [];
    $count = count($ghost_matches);
    
    if ($count > 0) {
        // Initialize target if it doesn't exist
        if (!isset($data[$target_group])) {
            $data[$target_group] = ['history' => []];
        }
        
        // MERGE: Move ghost matches to target
        foreach ($ghost_matches as $match) {
            $data[$target_group]['history'][] = $match;
        }
        
        // DELETE the ghost group so it doesn't confuse you again
        unset($data[$ghost_key]);
        
        // SAVE
        if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT))) {
            echo "SUCCESS! Moved $count matches from 'Ghost Group' to '$target_group'.\n";
            echo "Check your app now!";
        } else {
            echo "Error: Could not save the file. Check permissions.";
        }
    } else {
        echo "The Ghost Group exists but is empty. Nothing to move.";
    }
} else {
    echo "No Ghost Group found. Your data might already be fixed!";
}
?>