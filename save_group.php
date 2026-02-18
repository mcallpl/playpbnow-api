<?php
// api/save_group.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

$input = json_decode(file_get_contents('php://input'), true);
$group_name = $input['group_name'] ?? '';
$players = $input['players'] ?? []; 
$overwrite = $input['overwrite'] ?? false;

if (!$group_name || empty($players)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

$storage_file = '../pickleball_data.json';
$all_data = file_exists($storage_file) ? json_decode(file_get_contents($storage_file), true) : [];

// Check if exists
if (isset($all_data[$group_name]) && !$overwrite) {
    echo json_encode(['status' => 'exists', 'message' => 'Group already exists']);
    exit;
}

// Map players for storage
$player_map = [];
foreach ($players as $p) {
    $player_map[$p['id']] = $p;
}

// Save (Preserve history if overwriting)
$all_data[$group_name] = [
    'players' => $player_map,
    'history' => $all_data[$group_name]['history'] ?? [] 
];

file_put_contents($storage_file, json_encode($all_data, JSON_PRETTY_PRINT));
echo json_encode(['status' => 'success']);
?>