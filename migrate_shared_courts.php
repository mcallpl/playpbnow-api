<?php
/**
 * One-time migration: Merge courts from dink.courts into playpbnow.courts
 *
 * This makes playpbnow.courts the single source of truth for all apps.
 * After running, the shared beacon API will point to playpbnow.courts.
 *
 * Run via: https://peoplestar.com/PlayPBNow/api/migrate_shared_courts.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

// Use raw mysqli to avoid the wrapper's close() behavior
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed: ' . $conn->connect_error]);
    exit;
}
$conn->set_charset('utf8mb4');

// Ensure playpbnow.courts has the columns that dink.courts has
$check = $conn->query("SHOW COLUMNS FROM courts LIKE 'court_count'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE courts ADD COLUMN court_count INT DEFAULT NULL");
}

// Drop FK constraint on created_by_user_id if it exists (dink user IDs won't match playpbnow users)
$conn->query("ALTER TABLE courts DROP FOREIGN KEY courts_ibfk_1");

// Get all courts from dink database
$dinkCourts = [];
$result = $conn->query("SELECT * FROM dink.courts");
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Cannot read dink.courts: ' . $conn->error]);
    exit;
}
while ($row = $result->fetch_assoc()) {
    $dinkCourts[] = $row;
}

$migrated = 0;
$skipped = 0;
$details = [];

foreach ($dinkCourts as $dc) {
    $name = $dc['name'] ?? '';
    $city = $dc['city'] ?? '';
    $address = $dc['address'] ?? '';
    $state = $dc['state'] ?? '';
    $lat = isset($dc['lat']) ? floatval($dc['lat']) : null;
    $lng = isset($dc['lng']) ? floatval($dc['lng']) : null;
    $court_count = isset($dc['court_count']) ? intval($dc['court_count']) : null;

    // Check if this court already exists in playpbnow (match by name + city)
    $stmt = $conn->prepare("SELECT id, name, city, lat, lng FROM courts WHERE name = ? AND city = ?");
    $stmt->bind_param("ss", $name, $city);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        // Court already exists — update lat/lng if missing in playpbnow but present in dink
        $existLat = isset($existing['lat']) ? floatval($existing['lat']) : 0;
        $existLng = isset($existing['lng']) ? floatval($existing['lng']) : 0;
        if ((!$existLat || !$existLng) && $lat && $lng) {
            $upd = $conn->prepare("UPDATE courts SET lat = ?, lng = ? WHERE id = ?");
            $upd->bind_param("ddi", $lat, $lng, $existing['id']);
            $upd->execute();
            $upd->close();
            $details[] = "Updated coords for: {$name} ({$city}) → playpbnow ID {$existing['id']}";
        }
        $skipped++;
        continue;
    }

    // Insert into playpbnow.courts (skip created_by_user_id to avoid FK issues)
    $stmt = $conn->prepare(
        "INSERT INTO courts (name, address, city, state, lat, lng, court_count)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "ssssddi",
        $name, $address, $city, $state,
        $lat, $lng, $court_count
    );

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        $details[] = "Migrated: {$name} ({$city}) → dink ID {$dc['id']} → playpbnow ID {$newId}";
        $migrated++;
    } else {
        $details[] = "FAILED: {$name} ({$city}) → " . $stmt->error;
    }
    $stmt->close();
}

// Show summary
$totalPlaypbnow = 0;
$countResult = $conn->query("SELECT COUNT(*) as cnt FROM courts");
if ($countResult) {
    $totalPlaypbnow = $countResult->fetch_assoc()['cnt'];
}

$totalDink = count($dinkCourts);

echo json_encode([
    'status' => 'success',
    'message' => "Migration complete. Migrated: $migrated, Skipped (already exist): $skipped",
    'dink_courts_total' => $totalDink,
    'playpbnow_courts_total' => $totalPlaypbnow,
    'details' => $details
], JSON_PRETTY_PRINT);

$conn->close();
