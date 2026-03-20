<?php
/**
 * One-time migration: Add county column to courts and populate it.
 * Uses Google Maps Geocoding API to determine county from lat/lng or city+state.
 * DELETE AFTER USE.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$conn = getDBConnection();
$results = [];

// 1. Add county column if it doesn't exist
$cols = $conn->query("SHOW COLUMNS FROM courts LIKE 'county'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE courts ADD COLUMN county VARCHAR(100) DEFAULT NULL AFTER state");
    $conn->query("ALTER TABLE courts ADD INDEX idx_county (county)");
    $results['alter'] = 'added county column';
} else {
    $results['alter'] = 'county column already exists';
}

// 2. Get all courts missing county
$courts = dbGetAll("SELECT id, city, state, lat, lng FROM courts WHERE county IS NULL OR county = ''");
$results['courts_to_update'] = count($courts);

$updated = 0;
$failed = 0;

foreach ($courts as $court) {
    $county = null;

    // Try geocoding by lat/lng first
    if (!empty($court['lat']) && !empty($court['lng'])) {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng='
             . urlencode($court['lat']) . ',' . urlencode($court['lng'])
             . '&key=' . GOOGLE_MAPS_API_KEY;
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (!empty($data['results'])) {
            foreach ($data['results'][0]['address_components'] ?? [] as $comp) {
                if (in_array('administrative_area_level_2', $comp['types'])) {
                    $county = $comp['long_name'];
                    break;
                }
            }
        }
    }

    // Fallback: geocode by city + state
    if (!$county && !empty($court['city'])) {
        $query = $court['city'] . ', ' . ($court['state'] ?? 'CA');
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
             . urlencode($query)
             . '&key=' . GOOGLE_MAPS_API_KEY;
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (!empty($data['results'])) {
            foreach ($data['results'][0]['address_components'] ?? [] as $comp) {
                if (in_array('administrative_area_level_2', $comp['types'])) {
                    $county = $comp['long_name'];
                    break;
                }
            }
        }
    }

    if ($county) {
        // Normalize: "Orange County" -> "Orange County" (keep full name)
        dbQuery("UPDATE courts SET county = ? WHERE id = ?", [$county, $court['id']]);
        $updated++;
    } else {
        $failed++;
    }

    // Rate limit: Google allows 50 req/sec, but be safe
    usleep(100000); // 100ms
}

$results['updated'] = $updated;
$results['failed'] = $failed;

// 3. Show results grouped by county
$counties = dbGetAll("SELECT county, COUNT(*) as court_count, GROUP_CONCAT(DISTINCT city ORDER BY city SEPARATOR ', ') as cities FROM courts WHERE county IS NOT NULL GROUP BY county ORDER BY county");
$results['counties'] = $counties;

echo json_encode($results, JSON_PRETTY_PRINT);
