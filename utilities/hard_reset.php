<?php
// api/hard_reset.php
// PURPOSE: PHYSICAL DELETION of the database file.

header('Content-Type: text/plain');
$file = __DIR__ . '/pickleball_data.json';

// 1. DELETE THE FILE
if (file_exists($file)) {
    unlink($file);
    echo "Old file deleted from disk.\n";
} else {
    echo "No file found to delete.\n";
}

// 2. CREATE A FRESH EMPTY FILE
if (file_put_contents($file, "{}")) {
    chmod($file, 0666); // Ensure writable
    echo "New empty database created successfully.\n";
    echo "Current File Size: " . filesize($file) . " bytes (Should be 2 bytes)\n";
} else {
    echo "CRITICAL ERROR: Could not create new file. Check folder permissions.\n";
}
?>