<?php
/**
 * get_universal_stats.php — a player's cross-organizer record.
 *
 * "Credit follows the person": aggregates every match played by ANY local copy
 * that shares this person's master identity, no matter whose court/app it was
 * recorded on. Returns wins/losses/diff + how many different organizers they've
 * played under. Never returns the phone or its hash — identity in, stats out.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';
require_once __DIR__ . '/identity.php';

pbnow_require_session_user(); // any signed-in user may look up universal stats

$input       = json_decode(file_get_contents('php://input'), true) ?: [];
$player_id   = (int)($input['player_id']   ?? $_GET['player_id']   ?? 0);
$identity_id = (int)($input['identity_id'] ?? $_GET['identity_id'] ?? 0);
$phone       = $input['phone'] ?? ($_GET['phone'] ?? null);

// Resolve to an identity from any of the three handles.
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

$identity = dbGetRow("SELECT id, display_name FROM player_identities WHERE id = ?", [$identity_id]);

// Every local player row that IS this person.
$copies = dbGetAll(
    "SELECT player_key, created_by_user_id FROM players WHERE identity_id = ?",
    [$identity_id]
);
$keys       = array_values(array_filter(array_column($copies, 'player_key')));
$organizers = count(array_unique(array_filter(array_column($copies, 'created_by_user_id'))));

$wins = 0; $losses = 0; $diff = 0;
if (!empty($keys)) {
    $ph = implode(',', array_fill(0, count($keys), '?'));
    // Team 1 side (p1/p2)
    foreach (dbGetAll("SELECT s1, s2 FROM matches WHERE p1_key IN ($ph) OR p2_key IN ($ph)", array_merge($keys, $keys)) as $m) {
        $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
        if ($s1 > $s2) $wins++; elseif ($s2 > $s1) $losses++;
        $diff += ($s1 - $s2);
    }
    // Team 2 side (p3/p4)
    foreach (dbGetAll("SELECT s1, s2 FROM matches WHERE p3_key IN ($ph) OR p4_key IN ($ph)", array_merge($keys, $keys)) as $m) {
        $s1 = (int)$m['s1']; $s2 = (int)$m['s2'];
        if ($s2 > $s1) $wins++; elseif ($s1 > $s2) $losses++;
        $diff += ($s2 - $s1);
    }
}
$games   = $wins + $losses;
$win_pct = $games > 0 ? round($wins / $games * 100, 1) : 0.0;

echo json_encode([
    'status'        => 'success',
    'identity_id'   => $identity_id,
    'display_name'  => $identity['display_name'] ?? null,
    'organizers'    => $organizers,        // distinct organizers this person has played under
    'linked_copies' => count($copies),
    'universal'     => [
        'wins'    => $wins,
        'losses'  => $losses,
        'diff'    => $diff,
        'games'   => $games,
        'win_pct' => $win_pct,
    ],
]);
