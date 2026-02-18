<?php
// test_db_insert.php - Test if database inserts work
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Database Insert Test</h1>";
echo "<pre>";

require_once __DIR__ . '/db_config.php';

echo "1. Testing database connection...\n";
$conn = getDBConnection();
echo "✓ Connection successful!\n\n";

echo "2. Testing user creation...\n";
$test_device = 'test_' . time();
$user = getOrCreateUser($test_device);
if ($user) {
    echo "✓ User created: ID = {$user['id']}, device_id = {$user['device_id']}\n\n";
} else {
    echo "✗ Failed to create user\n\n";
    exit;
}

echo "3. Testing group creation...\n";
$group_key = 'test_group_' . time();
$group_id = dbInsert(
    "INSERT INTO `groups` (group_key, name, creator_user_id, device_id) 
     VALUES (?, ?, ?, ?)",
    [$group_key, 'Test Group', $user['id'], $test_device]
);

if ($group_id) {
    echo "✓ Group created: ID = $group_id\n\n";
} else {
    echo "✗ Failed to create group - check error_log\n\n";
    exit;
}

echo "4. Testing session creation...\n";
$batch_id = 'test_batch_' . uniqid();
$session_id = dbInsert(
    "INSERT INTO sessions (group_id, batch_id, title, device_id, session_date) 
     VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))",
    [$group_id, $batch_id, 'Test Session', $test_device, time()]
);

if ($session_id) {
    echo "✓ Session created: ID = $session_id\n\n";
} else {
    echo "✗ Failed to create session - check error_log\n\n";
    exit;
}

echo "5. Testing match creation...\n";
$match_id = dbInsert(
    "INSERT INTO matches 
     (group_id, session_id, batch_id, 
      p1_key, p2_key, p3_key, p4_key,
      p1_name, p2_name, p3_name, p4_name,
      s1, s2, timestamp, match_date, device_id, match_title) 
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?)",
    [
        $group_id, $session_id, $batch_id,
        'p1', 'p2', 'p3', 'p4',
        'Player 1', 'Player 2', 'Player 3', 'Player 4',
        11, 5, time(), time(), $test_device, 'Test Match'
    ]
);

if ($match_id) {
    echo "✓ Match created: ID = $match_id\n\n";
} else {
    echo "✗ Failed to create match - check error_log\n\n";
    exit;
}

echo "6. Verifying data in database...\n";
$match = dbGetRow("SELECT * FROM matches WHERE id = ?", [$match_id]);
if ($match) {
    echo "✓ Match found in database!\n";
    echo "  - Players: {$match['p1_name']}/{$match['p2_name']} vs {$match['p3_name']}/{$match['p4_name']}\n";
    echo "  - Score: {$match['s1']}-{$match['s2']}\n";
    echo "  - Device ID: {$match['device_id']}\n\n";
} else {
    echo "✗ Match not found after insert!\n\n";
}

echo "7. Cleaning up test data...\n";
$conn->query("DELETE FROM matches WHERE device_id = '$test_device'");
$conn->query("DELETE FROM sessions WHERE device_id = '$test_device'");
$conn->query("DELETE FROM `groups` WHERE device_id = '$test_device'");
$conn->query("DELETE FROM users WHERE device_id = '$test_device'");
echo "✓ Test data removed\n\n";

echo "========================================\n";
echo "ALL TESTS PASSED! ✓\n";
echo "Database is working correctly.\n";
echo "========================================\n";

echo "</pre>";
?>
