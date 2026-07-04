<?php
/**
 * get_universal_stats.php — a player's cross-organizer record.
 *
 * "Credit follows the person": aggregates every match played by ANY local copy
 * that shares this person's master identity, no matter whose court/app it was
 * recorded on. Returns wins/losses/diff + how many different organizers they've
 * played under, plus claim status. Never returns the phone or its hash —
 * identity in, stats out.
 *
 * Accepts any of: player_id, identity_id, claim_code, phone.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/identity.php';

$input       = json_decode(file_get_contents('php://input'), true) ?: [];
$player_id   = (int)($input['player_id']   ?? $_GET['player_id']   ?? 0);
$identity_id = (int)($input['identity_id'] ?? $_GET['identity_id'] ?? 0);
$claim_code  = strtoupper(trim($input['claim_code'] ?? ($_GET['claim_code'] ?? '')));
$phone       = $input['phone'] ?? ($_GET['phone'] ?? null);

// A signed-in user is required EXCEPT when looking up by claim_code — the claim
// page is used by people who don't have the app yet, and the code itself is the
// bearer capability (it only ever exposes stats, never the phone).
$viewer = pbnow_optional_user_id();
if ($claim_code === '' && $viewer === null) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sign in required.']);
    exit;
}

// Resolve to an identity from any handle.
if (!$identity_id && $claim_code !== '') {
    $row = dbGetRow("SELECT id FROM player_identities WHERE claim_code = ?", [$claim_code]);
    $identity_id = (int)($row['id'] ?? 0);
}
if (!$identity_id && $player_id) {
    $row = dbGetRow("SELECT identity_id FROM players WHERE id = ?", [$player_id]);
    $identity_id = (int)($row['identity_id'] ?? 0);
}
if (!$identity_id && $phone) {
    $hash = pbnow_phone_hash($phone);
    if ($hash) {
        $row = dbGetRow("SELECT id FROM player_identities WHERE phone_hash = ?", [$hash]);
        $identity_id = (int)($row['id'] ?? 0);
    }
}
if (!$identity_id) {
    echo json_encode([
        'status'  => 'not_linked',
        'message' => 'This player has no universal identity yet. Add them by phone (Pro) to link one.'
    ]);
    exit;
}

$identity = dbGetRow("SELECT id, display_name, claim_code, claimed_by_user_id FROM player_identities WHERE id = ?", [$identity_id]);
$stats    = pbnow_universal_stats($identity_id);
$claimed_by = $identity['claimed_by_user_id'] ? (int)$identity['claimed_by_user_id'] : null;

echo json_encode([
    'status'         => 'success',
    'identity_id'    => (int)$identity_id,
    'display_name'   => $identity['display_name'] ?? null,
    'claim_code'     => $identity['claim_code'] ?? null,
    'claimed'        => $claimed_by !== null,
    'claimed_by_me'  => ($viewer !== null && $claimed_by === $viewer),
    'organizers'     => $stats['organizers'],
    'linked_copies'  => $stats['linked_copies'],
    'universal'      => [
        'wins'    => $stats['wins'],
        'losses'  => $stats['losses'],
        'diff'    => $stats['diff'],
        'games'   => $stats['games'],
        'win_pct' => $stats['win_pct'],
    ],
]);
