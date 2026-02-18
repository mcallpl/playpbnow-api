<?php
// api/reset_database.php
// PURPOSE: Wipes all data, resets to valid JSON, and ensures writability.

header('Content-Type: text/plain');

$file = __DIR__ . '/pickleball_data.json';

// 1. Define the clean slate (Empty JSON Object)
$clean_data = new stdClass(); // This encodes to {}

// 2. Overwrite the file
if (file_put_contents($file, json_encode($clean_data, JSON_PRETTY_PRINT))) {
    
    // 3. CRITICAL: Set permissions so the App can write to it immediately
    if (@chmod($file, 0666)) {
        echo "SUCCESS: Database wiped clean.\n";
        echo "File status: Valid JSON, Writable (0666).\n";
        echo "You can now start fresh.";
    } else {
        echo "WARNING: Database wiped, but could not verify permissions.\n";
    }

} else {
    echo "ERROR: Could not wipe file. Server permissions denied.";
}
?>