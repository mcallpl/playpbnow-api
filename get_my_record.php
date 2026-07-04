<?php
/**
 * get_my_record.php — the signed-in user's own claimed universal record.
 *
 * For the in-app "My Record" view. Resolves the identity the user has claimed
 * (player_identities.claimed_by_user_id) and returns their cross-organizer
 * stats. Stats only — never the phone or its hash.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/identity.php';

$uid = pbnow_require_session_user();

$identity = dbGetRow(
    "SELECT id, display_name, claim_code FROM player_identities WHERE claimed_by_user_id = ? ORDER BY id ASC LIMIT 1",
    [$uid]
);
if (!$identity) {
    echo json_encode([
        'status'  => 'unclaimed',
        'message' => 'You haven\'t claimed a universal profile yet.'
    ]);
    exit;
}

$stats = pbnow_universal_stats((int)$identity['id']);

echo json_encode([
    'status'        => 'success',
    'identity_id'   => (int)$identity['id'],
    'display_name'  => $identity['display_name'] ?? null,
    'claim_code'    => $identity['claim_code'] ?? null,
    'organizers'    => $stats['organizers'],
    'linked_copies' => $stats['linked_copies'],
    'universal'     => [
        'wins'    => $stats['wins'],
        'losses'  => $stats['losses'],
        'diff'    => $stats['diff'],
        'games'   => $stats['games'],
        'win_pct' => $stats['win_pct'],
    ],
]);
