<?php
// ============================================
// search_players.php V3 — OWN-ROSTER PLAYER SEARCH
//
// V2 searched EVERY player in the database across all organizers and returned
// their cell_phone. That let any coordinator type a few letters into the
// "add player" box and harvest other coordinators' rosters + phone numbers.
//
// V3 scopes the search to the caller's own roster: players they created, plus
// players in groups they own. The only place the app is allowed to browse
// "everyone" is the invite pool (pool_players), where names are protected and
// contact goes through the SMS relay — never here.
//
// Identity is taken from the session token (Authorization: Bearer <token>),
// never from a query param, so it can't be spoofed by changing a URL.
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$query = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 20), 50);

if (strlen($query) < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Search query too short']);
    exit;
}

// No valid session → no roster to search. Return an empty result set rather
// than a 401: this endpoint powers type-ahead, and the interceptor's 401
// self-heal would bounce the user to /login mid-keystroke.
$actor = pbnow_optional_user_id();
if ($actor === null) {
    echo json_encode(['status' => 'success', 'results' => [], 'count' => 0]);
    exit;
}

try {
    $searchTerm = "%{$query}%";

    // Scope: players this user created, OR players in a group this user owns.
    // Both paths are needed — a player added before created_by_user_id was
    // backfilled may only be reachable through group ownership, and a player
    // created but not yet placed in a group only through creation.
    $players = dbGetAll(
        "SELECT p.id, p.player_key, p.first_name, p.last_name, p.gender,
                p.is_verified, p.home_court_id,
                p.wins, p.losses, p.diff, p.win_pct,
                c.name as home_court_name, c.city as home_court_city
         FROM players p
         LEFT JOIN courts c ON p.home_court_id = c.id
         WHERE (p.first_name LIKE ? OR p.last_name LIKE ? OR p.cell_phone LIKE ?)
           AND p._deleted_at IS NULL
           AND (
                 p.created_by_user_id = ?
                 OR EXISTS (
                      SELECT 1 FROM player_group_memberships pgm
                      INNER JOIN `groups` g ON pgm.group_id = g.id
                      WHERE pgm.player_id = p.id
                        AND g.owner_user_id = ?
                        AND g._deleted_at IS NULL
                    )
               )
         ORDER BY p.first_name ASC
         LIMIT ?",
        [$searchTerm, $searchTerm, $searchTerm, $actor, $actor, $limit]
    );

    // For each player, list the groups they belong to — but only groups the
    // caller owns. Otherwise the group list leaks which OTHER organizers a
    // person plays with, which is the same disclosure by a different route.
    $results = [];
    foreach ($players as $p) {
        $groups = dbGetAll(
            "SELECT g.name as group_name, g.group_key, c.name as court_name
             FROM player_group_memberships pgm
             INNER JOIN `groups` g ON pgm.group_id = g.id
             LEFT JOIN courts c ON g.court_id = c.id
             WHERE pgm.player_id = ?
               AND g.owner_user_id = ?
               AND g._deleted_at IS NULL",
            [$p['id'], $actor]
        );

        $groupNames = array_map(function($g) { return $g['group_name']; }, $groups);

        $results[] = [
            'id' => (int)$p['id'],
            'player_key' => $p['player_key'],
            'first_name' => $p['first_name'],
            'last_name' => $p['last_name'] ?? '',
            'gender' => $p['gender'],
            // cell_phone intentionally omitted — the roster picker never needs
            // it, and it was the most sensitive field V2 exposed.
            'is_verified' => (bool)$p['is_verified'],
            'home_court_id' => $p['home_court_id'],
            'home_court_name' => $p['home_court_name'],
            'home_court_city' => $p['home_court_city'],
            'wins' => (int)$p['wins'],
            'losses' => (int)$p['losses'],
            'diff' => (int)$p['diff'],
            'win_pct' => (float)$p['win_pct'],
            'groups' => $groupNames,
            'source' => $p['home_court_name'] ?? 'No court'
        ];
    }

    echo json_encode([
        'status' => 'success',
        'results' => $results,
        'count' => count($results)
    ]);

} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
