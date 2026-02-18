<?php
// ============================================
// get_courts.php — Returns all courts for selection
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

try {
    $courts = dbGetAll("SELECT id, name, city, state, address FROM courts ORDER BY name ASC", []);

    echo json_encode([
        'status' => 'success',
        'courts' => $courts
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
