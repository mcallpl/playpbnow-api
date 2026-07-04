<?php
/**
 * claim_lookup.php — public: what profile does this claim_code point to?
 *
 * Lets the claim page greet the person ("Claim Marco's record") and know whether
 * it's already claimed, without any login. Returns the display name and claimed
 * status only — never the phone or its hash.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$code  = strtoupper(trim($input['code'] ?? ($_GET['code'] ?? '')));

if ($code === '') {
    echo json_encode(['status' => 'error', 'message' => 'No claim code provided.']);
    exit;
}

$identity = dbGetRow(
    "SELECT display_name, claimed_by_user_id FROM player_identities WHERE claim_code = ?",
    [$code]
);
if (!$identity) {
    echo json_encode(['status' => 'not_found', 'message' => 'That claim code isn\'t valid.']);
    exit;
}

echo json_encode([
    'status'       => 'success',
    'display_name' => $identity['display_name'] ?: 'your',
    'claimed'      => !empty($identity['claimed_by_user_id']),
]);
