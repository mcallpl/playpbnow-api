<?php
/**
 * Migration: Add short_notice, tournament_interest, max_travel_minutes to pool_players.
 * DELETE AFTER USE.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$conn = getDBConnection();
$results = [];

// Add short_notice column
$cols = $conn->query("SHOW COLUMNS FROM pool_players LIKE 'short_notice'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE pool_players ADD COLUMN short_notice ENUM('Yes','No') DEFAULT 'Yes' AFTER cities_to_play");
    $results['short_notice'] = 'added';
} else {
    $results['short_notice'] = 'already exists';
}

// Add tournament_interest column
$cols = $conn->query("SHOW COLUMNS FROM pool_players LIKE 'tournament_interest'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE pool_players ADD COLUMN tournament_interest ENUM('Yes','No') DEFAULT 'Yes' AFTER short_notice");
    $results['tournament_interest'] = 'added';
} else {
    $results['tournament_interest'] = 'already exists';
}

// Add max_travel_minutes column
$cols = $conn->query("SHOW COLUMNS FROM pool_players LIKE 'max_travel_minutes'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE pool_players ADD COLUMN max_travel_minutes INT DEFAULT 30 AFTER tournament_interest");
    $results['max_travel_minutes'] = 'added';
} else {
    $results['max_travel_minutes'] = 'already exists';
}

// Add indexes
$conn->query("ALTER TABLE pool_players ADD INDEX idx_short_notice (short_notice)");
$conn->query("ALTER TABLE pool_players ADD INDEX idx_tournament (tournament_interest)");

// Also add new columns to player_verification_codes temp table
$cols = $conn->query("SHOW COLUMNS FROM player_verification_codes LIKE 'short_notice'");
if ($cols->num_rows === 0) {
    $conn->query("ALTER TABLE player_verification_codes ADD COLUMN short_notice ENUM('Yes','No') DEFAULT 'Yes'");
    $conn->query("ALTER TABLE player_verification_codes ADD COLUMN tournament_interest ENUM('Yes','No') DEFAULT 'Yes'");
    $conn->query("ALTER TABLE player_verification_codes ADD COLUMN max_travel_minutes INT DEFAULT 30");
    $results['verification_codes_columns'] = 'added';
} else {
    $results['verification_codes_columns'] = 'already exist';
}

$results['status'] = 'success';
echo json_encode($results, JSON_PRETTY_PRINT);
