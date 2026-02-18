<?php
// api/test_save_logic.php
// PURPOSE: diagnose WHY saving fails without corrupting data.

header('Content-Type: text/plain');

echo "--- DIAGNOSTIC START ---\n";

// 1. CHECK FILE LOCATION
$dir = __DIR__;
$file = $dir . '/pickleball_data.json';
echo "1. Checking File Path: $file\n";

if (file_exists($file)) {
    echo "   ✅ File exists.\n";
} else {
    echo "   ❌ FILE NOT FOUND. Script is looking in: $dir\n";
    exit; // Stop here if file is missing
}

// 2. CHECK PERMISSIONS
echo "\n2. Checking Permissions:\n";
$perms = substr(sprintf('%o', fileperms($file)), -4);
echo "   Current Permissions: $perms\n";

if (is_writable($file)) {
    echo "   ✅ PHP says file is writable.\n";
} else {
    echo "   ❌ PHP says file is NOT writable. (Needs 0666)\n";
}

// 3. CHECK JSON INTEGRITY
echo "\n3. Checking Data Integrity:\n";
$content = file_get_contents($file);
$data = json_decode($content, true);

if (json_last_error() === JSON_ERROR_NONE) {
    echo "   ✅ JSON is valid.\n";
    $count = count($data);
    echo "   Found $count groups in database.\n";
} else {
    echo "   ❌ JSON IS CORRUPT: " . json_last_error_msg() . "\n";
    exit;
}

// 4. TEST DETECTIVE LOGIC (The "My 8" vs "My 8 Players" test)
echo "\n4. Testing Group Matching Logic:\n";
$test_input = "My 8"; // This is what your App sends
echo "   Simulating App Input: '$test_input'\n";

$found_key = null;
$match_type = "None";

foreach ($data as $db_key => $val) {
    // Exact Match Check
    if (trim(strtolower($db_key)) === trim(strtolower($test_input))) {
        $found_key = $db_key;
        $match_type = "Exact Match";
        break;
    }
    // Contains Match Check
    if (stripos($db_key, trim($test_input)) !== false) {
        $found_key = $db_key;
        $match_type = "Partial/Contains Match";
        break;
    }
}

if ($found_key) {
    echo "   ✅ SUCCESS: Logic mapped '$test_input' to existing group '$found_key'\n";
    echo "   Match Type: $match_type\n";
} else {
    echo "   ❌ FAILURE: Logic could not find a match for '$test_input'. It would have created a Ghost Group.\n";
    echo "   Available Keys: " . implode(", ", array_keys($data)) . "\n";
}

// 5. TEST WRITE CAPABILITY (Safe Test)
echo "\n5. Testing Disk Write:\n";
$test_file = $dir . '/write_test_file.txt';
if (file_put_contents($test_file, "Write Test Successful at " . date('H:i:s'))) {
    echo "   ✅ SUCCESS: Successfully wrote a test file to this folder.\n";
    unlink($test_file); // Clean up
} else {
    echo "   ❌ FAILURE: Could not write to disk. Server permissions are blocking PHP.\n";
}

echo "\n--- DIAGNOSTIC END ---";
?>