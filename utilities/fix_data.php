<?php
// api/fix_data.php
// RUN THIS ONCE TO MERGE THE "GHOST" DATA INTO YOUR MAIN FILE

header("Content-Type: text/plain");

$main_file = '../pickleball_data.json';
$ghost_file = 'pickleball_data.json'; // The one inside /api/

if (!file_exists($ghost_file)) {
    die("No ghost file found in API folder. Nothing to merge.");
}

$main_data = file_exists($main_file) ? json_decode(file_get_contents($main_file), true) : [];
$ghost_data = json_decode(file_get_contents($ghost_file), true);

if (!$ghost_data) {
    die("Ghost file is empty or corrupt.");
}

// Merge Ghost Data into Main Data
foreach ($ghost_data as $group_name => $content) {
    if (!isset($main_data[$group_name])) {
        $main_data[$group_name] = ['history' => []];
    }
    
    // Append history
    if (isset($content['history'])) {
        foreach ($content['history'] as $record) {
            $main_data[$group_name]['history'][] = $record;
        }
    }
}

// Save to Main File
if (file_put_contents($main_file, json_encode($main_data, JSON_PRETTY_PRINT))) {
    echo "SUCCESS: Data merged! 'MY 8' scores should now be visible in the app.\n";
    // Optional: Delete the ghost file so this doesn't happen again
    unlink($ghost_file); 
    echo "Ghost file deleted to prevent confusion.";
} else {
    echo "ERROR: Could not write to main file. Check permissions.";
}
?>