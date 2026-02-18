<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$name    = trim($input['name'] ?? '');
$city    = trim($input['city'] ?? '');
$state   = trim($input['state'] ?? '');
$address = trim($input['address'] ?? '');
$user_id = $input['user_id'] ?? '';

if (empty($name)) { echo json_encode(['status' => 'error', 'message' => 'Court name required']); exit; }
if (empty($city)) { echo json_encode(['status' => 'error', 'message' => 'City required']); exit; }

try {
    // Check for duplicate
    $existing = dbGetRow(
        "SELECT id, name, city FROM courts WHERE name = ? AND city = ?",
        [$name, $city]
    );
    
    if ($existing) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Court already exists',
            'court' => [
                'id' => (int)$existing['id'],
                'name' => $existing['name'],
                'city' => $existing['city']
            ]
        ]);
        exit;
    }
    
    $court_id = dbInsert(
        "INSERT INTO courts (name, city, state, address, created_by_user_id, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$name, $city, $state ?: null, $address ?: null, $user_id ?: null]
    );
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Court created',
        'court' => [
            'id' => $court_id,
            'name' => $name,
            'city' => $city,
            'state' => $state
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Add court error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
