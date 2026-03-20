<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, 'dink');
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed: ' . $conn->connect_error]);
    exit;
}

if ($conn->query("RENAME TABLE courts TO courts_deprecated")) {
    echo json_encode(['status' => 'success', 'message' => 'dink.courts renamed to dink.courts_deprecated']);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}

$conn->close();
