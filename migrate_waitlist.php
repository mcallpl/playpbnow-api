<?php
/**
 * One-time migration: Add 'waitlisted' to invite_responses.status enum
 * DELETE AFTER USE
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$conn = getDBConnection();

$sql = "ALTER TABLE invite_responses MODIFY COLUMN status ENUM('pending','confirmed','interested','declined','waitlisted') DEFAULT 'pending'";

if ($conn->query($sql)) {
    echo json_encode(['status' => 'success', 'message' => 'Added waitlisted to invite_responses.status enum']);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}
