<?php
// Fix: invite_responses.player_id should reference pool_players, not players
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$results = [];

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Drop the bad foreign key
    $conn->exec("ALTER TABLE invite_responses DROP FOREIGN KEY invite_responses_ibfk_2");
    $results['drop_fk'] = 'dropped invite_responses_ibfk_2';
} catch (Exception $e) {
    $results['drop_fk'] = $e->getMessage();
}

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add correct foreign key to pool_players
    $conn->exec("ALTER TABLE invite_responses ADD CONSTRAINT invite_responses_pool_player_fk FOREIGN KEY (player_id) REFERENCES pool_players(id) ON DELETE CASCADE");
    $results['add_fk'] = 'added FK to pool_players';
} catch (Exception $e) {
    $results['add_fk'] = $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
