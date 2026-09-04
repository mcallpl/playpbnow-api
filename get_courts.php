<?php
// ============================================
// get_courts.php — Returns all courts for selection
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

try {
    // M5: lat/lng/county added so the Play Now court picker can sort by
    // distance and show "Name — City". Existing fields are unchanged.
    $courts = dbGetAll("SELECT id, name, city, state, address, lat, lng, county FROM courts ORDER BY name ASC", []);

    foreach ($courts as $i => $c) {
        $courts[$i]['lat'] = ($c['lat'] !== null && $c['lat'] !== '') ? (float)$c['lat'] : null;
        $courts[$i]['lng'] = ($c['lng'] !== null && $c['lng'] !== '') ? (float)$c['lng'] : null;
    }

    echo json_encode([
        'status' => 'success',
        'courts' => $courts,
        'count' => count($courts)
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
