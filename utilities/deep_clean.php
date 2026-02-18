<?php
// api/deep_clean.php
// PURPOSE: 
// 1. Deletes the "Unknown Group" folder.
// 2. Scans REAL groups (like "My 8") and deletes individual matches with "Unknown" players.

header('Content-Type: text/plain');

$file = __DIR__ . '/pickleball_data.json';

if (!file_exists($file)) {
    die("Error: pickleball_data.json not found.");
}

$data = json_decode(file_get_contents($file), true);
if (!$data) {
    die("Error: Could not read JSON data.");
}

$groups_removed = 0;
$matches_removed = 0;

// 1. DELETE JUNK GROUPS
$junk_groups = ["Unknown Group", "Pickleheads", "Unknown"];
foreach ($junk_groups as $junk) {
    if (isset($data[$junk])) {
        unset($data[$junk]);
        echo "✅ Deleted Group: '$junk'\n";
        $groups_removed++;
    }
}

// 2. SCRUB VALID GROUPS (Remove matches with "Unknown" players)
foreach ($data as $group_name => &$group_data) {
    if (!isset($group_data['history']) || empty($group_data['history'])) {
        continue;
    }

    $original_count = count($group_data['history']);
    $clean_history = [];

    foreach ($group_data['history'] as $match) {
        // Check if any player name is "Unknown"
        $p1 = $match['p1_name'] ?? 'Unknown';
        $p2 = $match['p2_name'] ?? 'Unknown';
        
        if ($p1 === 'Unknown' || $p2 === 'Unknown') {
            // Skip this match (it's bad)
            continue;
        }
        
        // Keep good match
        $clean_history[] = $match;
    }

    // Update the group
    $group_data['history'] = array_values($clean_history); // Re-index array
    $removed_here = $original_count - count($clean_history);
    
    if ($removed_here > 0) {
        echo "✅ Removed $removed_here bad matches from '$group_name'\n";
        $matches_removed += $removed_here;
    }
}

// 3. SAVE
if ($groups_removed > 0 || $matches_removed > 0) {
    if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT))) {
        echo "\nSUCCESS: Database scrubbed.\n";
        echo "Groups Deleted: $groups_removed\n";
        echo "Bad Matches Deleted: $matches_removed\n";
    } else {
        echo "\nERROR: Could not save changes. Permissions?";
    }
} else {
    echo "\nClean. No bad data found.";
}
?>