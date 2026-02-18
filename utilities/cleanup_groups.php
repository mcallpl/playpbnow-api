<?php
// api/cleanup_groups.php
// PURPOSE: Deletes specific unwanted groups ("Pickleheads", "Friday Night Crew")

header('Content-Type: text/plain');

// 1. Target the file in the current API folder
$file = __DIR__ . '/pickleball_data.json';

if (!file_exists($file)) {
    die("Error: pickleball_data.json not found in this folder.");
}

// 2. Load Data
$data = json_decode(file_get_contents($file), true);
if (!$data) {
    die("Error: Could not read JSON data.");
}

// 3. Define Groups to Delete
$groups_to_delete = ["Pickleheads", "Friday Night Crew"];
$deleted_count = 0;

echo "--- CLEANUP REPORT ---\n";

foreach ($groups_to_delete as $target) {
    // Check Exact Match
    if (isset($data[$target])) {
        unset($data[$target]);
        echo "✅ DELETED: '$target'\n";
        $deleted_count++;
    } else {
        echo "ℹ️  SKIPPED: '$target' (Not found)\n";
    }
}

// 4. Save Changes
if ($deleted_count > 0) {
    if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT))) {
        echo "\nSUCCESS: File updated. Junk groups removed.\n";
        echo "You can now delete this script.";
    } else {
        echo "\nERROR: Could not save changes. Check permissions.";
    }
} else {
    echo "\nNo changes needed. The groups were already gone.";
}
?>