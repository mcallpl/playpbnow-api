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

if (empty($name))    { echo json_encode(['status' => 'error', 'message' => 'Court name required']); exit; }
if (empty($city))    { echo json_encode(['status' => 'error', 'message' => 'City required']); exit; }
if (empty($address)) { echo json_encode(['status' => 'error', 'message' => 'Address required']); exit; }
if (empty($user_id)) { echo json_encode(['status' => 'error', 'message' => 'User ID required']); exit; }

// Geocode the address using Google Maps API
function geocodeAddress($name, $address, $city, $state) {
    $apiKey = GOOGLE_MAPS_API_KEY;
    $fullAddress = urlencode("$name, $address, $city, $state");
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=$fullAddress&key=$apiKey";

    $response = file_get_contents($url);
    if ($response === false) {
        error_log("Geocoding request failed for: $name, $address, $city, $state");
        return ['lat' => null, 'lng' => null];
    }

    $data = json_decode($response, true);
    if ($data['status'] === 'OK' && !empty($data['results'])) {
        return [
            'lat' => $data['results'][0]['geometry']['location']['lat'],
            'lng' => $data['results'][0]['geometry']['location']['lng']
        ];
    }

    error_log("Geocoding failed (status: {$data['status']}) for: $name, $address, $city, $state");
    return ['lat' => null, 'lng' => null];
}

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

    // Geocode before inserting
    $coords = geocodeAddress($name, $address, $city, $state);

    $court_id = dbInsert(
        "INSERT INTO courts (name, city, state, address, lat, lng, created_by_user_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$name, $city, $state ?: null, $address, $coords['lat'], $coords['lng'], $user_id]
    );

    echo json_encode([
        'status' => 'success',
        'message' => 'Court created',
        'court' => [
            'id' => $court_id,
            'name' => $name,
            'city' => $city,
            'state' => $state,
            'address' => $address,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'created_by_user_id' => $user_id
        ]
    ]);

} catch (Exception $e) {
    error_log("Add court error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
