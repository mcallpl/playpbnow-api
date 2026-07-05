<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';

if (!defined('GOOGLE_MAPS_API_KEY')) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error']);
    exit;
}

$response = json_encode([
    'status' => 'success',
    'google_maps_api_key' => GOOGLE_MAPS_API_KEY
]);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to encode response']);
    exit;
}

echo $response;
