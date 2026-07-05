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

$user_lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$user_lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$radius_miles = isset($_GET['radius']) ? (float)$_GET['radius'] : 10;

$conn = getDBConnection();

// Expire old beacons
$conn->query("UPDATE beacons SET status='expired' WHERE expires_at < NOW() AND status='active'");

// Count active beacons (with optional radius filtering)
$has_location = ($user_lat !== null && $user_lng !== null);

if ($has_location) {
    $sql = "SELECT COUNT(*) AS cnt FROM (
                SELECT b.id,
                    CASE WHEN c.lat IS NOT NULL AND c.lng IS NOT NULL THEN
                        (3959 * ACOS(LEAST(1.0, GREATEST(-1.0,
                            COS(RADIANS($user_lat)) * COS(RADIANS(c.lat)) *
                            COS(RADIANS(c.lng) - RADIANS($user_lng)) +
                            SIN(RADIANS($user_lat)) * SIN(RADIANS(c.lat))
                        ))))
                    ELSE NULL END AS distance_miles
                FROM beacons b
                LEFT JOIN courts c ON b.court_id = c.id
                WHERE b.status = 'active'
                HAVING distance_miles <= " . (float)$radius_miles . " OR distance_miles IS NULL
            ) AS nearby";
} else {
    $sql = "SELECT COUNT(*) AS cnt FROM beacons WHERE status='active'";
}

$result = $conn->query($sql);
$count = 0;
if ($result) {
    $row = $result->fetch_assoc();
    $count = (int)$row['cnt'];
    $result->free();
}

$conn->close();

echo json_encode(['status' => 'success', 'count' => $count]);
