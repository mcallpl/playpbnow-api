<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

// Return cities grouped by county
$rows = dbGetAll(
    "SELECT DISTINCT county, city FROM courts
     WHERE city IS NOT NULL AND city != '' AND county IS NOT NULL AND county != ''
     ORDER BY county, city"
);

$grouped = [];
foreach ($rows as $row) {
    $county = $row['county'];
    if (!isset($grouped[$county])) {
        $grouped[$county] = [];
    }
    if (!in_array($row['city'], $grouped[$county])) {
        $grouped[$county][] = $row['city'];
    }
}

echo json_encode(['status' => 'success', 'counties' => $grouped]);
