<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';
$courts = dbGetAll("SELECT id, name, city, state, address, county, lat, lng FROM courts ORDER BY city, name");
echo json_encode(['count' => count($courts), 'courts' => $courts], JSON_PRETTY_PRINT);
