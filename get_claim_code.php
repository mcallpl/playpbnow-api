<?php
/**
 * get_claim_code.php — organizer fetches the shareable claim code for one of
 * their players (Phase 3).
 *
 * Premium only, and only for a player you created (or super_admin). Returns the
 * claim_code + the claim/card URLs the organizer shows to the real person so
 * they can claim their universal record. The player must already be linked to a
 * universal identity (i.e. added by phone).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/identity.php';

$actor = pbnow_require_premium(); // 402 if not Pro/trial/admin

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$player_id = (int)($input['player_id'] ?? $_GET['player_id'] ?? 0);
if (!$player_id) {
    echo json_encode(['status' => 'error', 'message' => 'player_id required.']);
    exit;
}

$player = dbGetRow(
    "SELECT id, first_name, created_by_user_id, identity_id FROM players WHERE id = ?",
    [$player_id]
);
if (!$player) {
    echo json_encode(['status' => 'error', 'message' => 'Player not found.']);
    exit;
}

// Creator-only (super_admin may view any).
if (!pbnow_is_admin($actor) && (int)$player['created_by_user_id'] !== $actor) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You can only share profiles for players you created.']);
    exit;
}

if (empty($player['identity_id'])) {
    echo json_encode([
        'status'  => 'not_linked',
        'message' => 'Add this player by phone to create a shareable universal profile.'
    ]);
    exit;
}

$identity = dbGetRow(
    "SELECT id, claim_code, display_name, claimed_by_user_id FROM player_identities WHERE id = ?",
    [(int)$player['identity_id']]
);
if (!$identity) {
    echo json_encode(['status' => 'error', 'message' => 'Universal profile not found.']);
    exit;
}

// Backfill a code if this identity somehow predates Phase 3.
$code = $identity['claim_code'];
if (empty($code)) {
    $code = pbnow_unique_claim_code();
    dbQuery("UPDATE player_identities SET claim_code = ? WHERE id = ?", [$code, (int)$identity['id']]);
}

$base = 'https://playpbnow.com';
echo json_encode([
    'status'       => 'success',
    'claim_code'   => $code,
    'display_name' => $identity['display_name'] ?? $player['first_name'],
    'claimed'      => !empty($identity['claimed_by_user_id']),
    'claim_url'    => $base . '/claim.html?code=' . urlencode($code),
    'card_url'     => $base . '/player-card.html?code=' . urlencode($code),
]);
