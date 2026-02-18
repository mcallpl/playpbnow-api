<?php
// api/get_group_details.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$storage_file = '../pickleball_data.json';
$group_name = $_GET['group'] ?? '';

if (file_exists($storage_file) && $group_name) {
    $data = json_decode(file_get_contents($storage_file), true);
    
    // Return the players for this specific group
    if (isset($data[$group_name]['players'])) {
        echo json_encode($data[$group_name]['players']);
        exit;
    }
}

// If nothing found, return empty
echo json_encode([]);
?>