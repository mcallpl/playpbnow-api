<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Version: leaderboard-v6-scoped');
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

// GLOBAL mode is gone. It aggregated every match from every organizer in the
// database — a ranking across pools that never played each other, and a
// disclosure of other organizers' player names. Rankings are now always scoped
// to one group belonging to the caller.
$group_key = $_GET['group_key'] ?? $_GET['group'] ?? '';
$batch_id  = $_GET['batch_id'] ?? 'all';

// Prefer the session token over the spoofable user_id query param; fall back to
// the param only when no valid token was supplied (older clients).
$user_id = pbnow_optional_user_id();
if ($user_id === null) {
    $user_id = $_GET['user_id'] ?? '';
}

if (empty($user_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing user_id']);
    exit;
}
if (empty($group_key)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing group']);
    exit;
}

// Groups are resolved by NAME here (that is what the client sends), so the
// owner filter is load-bearing: without it, two organizers who both have a
// group called "Tuesday" would resolve to whichever row happened to be first.
$group = dbGetRow(
    "SELECT id FROM `groups` WHERE name = ? AND owner_user_id = ? AND _deleted_at IS NULL",
    [$group_key, $user_id]
);
// The client sometimes has the group_key rather than the display name.
if (!$group) {
    $group = dbGetRow(
        "SELECT id FROM `groups` WHERE group_key = ? AND owner_user_id = ? AND _deleted_at IS NULL",
        [$group_key, $user_id]
    );
}

// ── Collaborator access (Audit C H10) ─────────────────────────────────
// A collaborator who scored a live match is sent to the leaderboard for the
// saved session, but does not own the group, so the owner-scoped lookup above
// finds nothing and they land on an empty board. For a SPECIFIC session
// (batch_id !== 'all') allow the caller when either
//   (a) sessions.user_id = caller, or
//   (b) the caller has a collab_participants row for the collab session whose
//       saved_session_id = that session.
// In that case the group is resolved from sessions.group_id and the
// "sessions owned by caller" filter is relaxed to the session's own owner.
$sessionOwnerForFilter = $user_id;   // whose sessions may be listed
$viewerIsCollaborator  = false;
if ($batch_id !== 'all') {
    $sessRow = dbGetRow("SELECT id, group_id, user_id FROM sessions WHERE id = ?", [(int)$batch_id]);
    if ($sessRow) {
        $allowed = (strval($sessRow['user_id']) === strval($user_id));
        if (!$allowed) {
            try {
                $collabHit = dbGetRow(
                    "SELECT cp.id
                     FROM collab_sessions cs
                     INNER JOIN collab_participants cp ON cp.session_id = cs.id
                     WHERE cs.saved_session_id = ? AND cp.user_id = ?
                     LIMIT 1",
                    [(int)$sessRow['id'], (string)$user_id]
                );
                $allowed = (bool)$collabHit;
            } catch (Throwable $e) { $allowed = false; }
        }
        if ($allowed) {
            // Owner lookup may have failed (collaborator) or may have found a
            // different group with the same name — trust the session's group.
            if (!$group || (int)$group['id'] !== (int)$sessRow['group_id']) {
                $sessGroup = dbGetRow("SELECT id FROM `groups` WHERE id = ?", [(int)$sessRow['group_id']]);
                if ($sessGroup) {
                    $group = $sessGroup;
                    $viewerIsCollaborator = (strval($sessRow['user_id']) !== strval($user_id));
                    $sessionOwnerForFilter = $sessRow['user_id'];
                }
            }
        }
    }
}

if (!$group) {
    echo json_encode(['status' => 'success', 'leaderboard' => [], 'history' => [], 'roster' => []]);
    exit;
}
$group_id = $group['id'];

// BUILD QUERY — join matches to sessions via sessions.id (not batch_id)
$whereClause = "WHERE 1=1";
$params = [];

$whereClause .= " AND m.group_id = ?";
$params[] = $group_id;

$whereClause .= " AND s.user_id = ?";
$params[] = $sessionOwnerForFilter;

if ($batch_id !== 'all') {
    $whereClause .= " AND m.session_id = ?";
    $params[] = $batch_id;
}

$sql = "SELECT m.*, s.user_id as session_user_id, s.title as session_title,
               UNIX_TIMESTAMP(s.session_date) as session_timestamp
        FROM matches m
        LEFT JOIN sessions s ON m.session_id = s.id
        $whereClause
        ORDER BY m.round_num ASC, m.court_num ASC";

$rawHistory = dbGetAll($sql, $params);

// Deduplicate by match id (safety net against JOIN producing duplicate rows)
$seen = [];
$history = [];
foreach ($rawHistory as $row) {
    $mid = $row['id'];
    if (isset($seen[$mid])) continue;
    $seen[$mid] = true;
    $history[] = $row;
}

// Add ownership + map keys
foreach ($history as &$match) {
    $match['isYours'] = (strval($match['session_user_id'] ?? '') === strval($user_id));
    $match['p1'] = $match['p1_key'] ?? '';
    $match['p2'] = $match['p2_key'] ?? '';
    $match['p3'] = $match['p3_key'] ?? '';
    $match['p4'] = $match['p4_key'] ?? '';
    $match['match_id'] = $match['id'];
}
unset($match); // CRITICAL: break the reference before next foreach

// Build leaderboard from match data
// Audit C M4: stats are keyed by PLAYER KEY (p*_key) with the name as a
// fallback for legacy rows that have no key, so two different "Chris"es no
// longer merge into one row and a renamed player keeps their record. Ties
// (s1 === s2) are neither a win nor a loss.
$stats = [];
foreach ($history as $match) {
    $players_in_match = [
        ['name' => $match['p1_name'], 'key' => $match['p1_key'] ?? '', 'team' => 1],
        ['name' => $match['p2_name'], 'key' => $match['p2_key'] ?? '', 'team' => 1],
        ['name' => $match['p3_name'], 'key' => $match['p3_key'] ?? '', 'team' => 2],
        ['name' => $match['p4_name'], 'key' => $match['p4_key'] ?? '', 'team' => 2],
    ];

    $s1 = (int)$match['s1'];
    $s2 = (int)$match['s2'];
    if ($s1 === 0 && $s2 === 0) continue;

    foreach ($players_in_match as $p) {
        $name = $p['name'];
        if (!$name || $name === 'Unknown') continue;
        $statKey = ($p['key'] !== '' && $p['key'] !== null) ? (string)$p['key'] : (string)$name;
        if (!isset($stats[$statKey])) {
            $stats[$statKey] = ['name' => $name, 'key' => (string)$p['key'], 'w' => 0, 'l' => 0, 'pf' => 0, 'pa' => 0, 'games' => 0, 'got_zero' => false];
        }
        // Latest name wins (update_match propagates renames per session).
        $stats[$statKey]['name'] = $name;

        $stats[$statKey]['games']++;

        if ($p['team'] === 1) {
            $stats[$statKey]['pf'] += $s1;
            $stats[$statKey]['pa'] += $s2;
            if ($s1 > $s2) $stats[$statKey]['w']++;
            elseif ($s2 > $s1) $stats[$statKey]['l']++;
            if ($s1 === 0) $stats[$statKey]['got_zero'] = true;
        } else {
            $stats[$statKey]['pf'] += $s2;
            $stats[$statKey]['pa'] += $s1;
            if ($s2 > $s1) $stats[$statKey]['w']++;
            elseif ($s1 > $s2) $stats[$statKey]['l']++;
            if ($s2 === 0) $stats[$statKey]['got_zero'] = true;
        }
    }
}

// Build leaderboard with badges
$leaderboard = [];
foreach ($stats as $statKey => $s) {
    $name = $s['name'];
    $total = $s['w'] + $s['l'];
    $pct = $total > 0 ? round(($s['w'] / $total) * 100) : 0;
    $diff = $s['pf'] - $s['pa'];
    
    // BADGE LOGIC (mutually exclusive, except turkey combines with any):
    // 💠 Platinum = won ALL games (100%) — blue diamond emoji for platinum
    // 💎 Diamond = lost exactly 1 game
    // 🔥 Fire = won 3+ games
    // 🦃 Turkey = got scored 0 in any game (COMBINABLE with any above)
    //
    // Priority: Platinum > Diamond > Fire (only ONE of these, plus optional turkey)
    $badges = [];
    
    if ($total > 0) {
        if ($s['w'] === $total) {
            $badges[] = '💠'; // Platinum - perfect record (blue diamond)
        } elseif ($s['l'] === 1) {
            $badges[] = '💎'; // Diamond - lost exactly one
        } elseif ($s['w'] >= 3) {
            $badges[] = '🔥'; // Fire - 3+ wins
        }
        
        // Turkey combinable with any badge above
        if ($s['got_zero']) {
            $badges[] = '🦃'; // Turkey - got pickled
        }
    }
    
    $leaderboard[] = [
        // 'id' stays the display name for older clients that key on it; the
        // player key is exposed separately as 'player_key'.
        'id' => $name,
        'player_key' => $s['key'] !== '' ? $s['key'] : null,
        'name' => $name,
        'w' => $s['w'],
        'l' => $s['l'],
        'pct' => $pct,
        'diff' => $diff,
        'pf' => $s['pf'],
        'pa' => $s['pa'],
        'badges' => $badges
    ];
}

// Sort by win pct desc, then diff desc
usort($leaderboard, function($a, $b) {
    if ($b['pct'] !== $a['pct']) return $b['pct'] - $a['pct'];
    return $b['diff'] - $a['diff'];
});

// Roster is always the group's own members. (The old global branch selected
// every player row in the database to build the DUPR lookup.)
$roster = dbGetAll(
    "SELECT p.player_key as id, p.first_name as name, p.dupr_rating
     FROM players p
     INNER JOIN player_group_memberships pgm ON p.id = pgm.player_id
     WHERE pgm.group_id = ? AND p._deleted_at IS NULL",
    [$group_id]
);

// Build name-to-DUPR lookup for leaderboard enrichment (plus a key-based one)
$duprLookup = [];
$duprByKey  = [];
foreach ($roster as $r) {
    if (!empty($r['dupr_rating'])) {
        $duprLookup[$r['name']] = floatval($r['dupr_rating']);
        if (!empty($r['id'])) $duprByKey[(string)$r['id']] = floatval($r['dupr_rating']);
    }
}

// Enrich leaderboard with DUPR ratings (key first, then name)
foreach ($leaderboard as &$entry) {
    $entry['dupr'] = null;
    if (!empty($entry['player_key']) && isset($duprByKey[$entry['player_key']])) {
        $entry['dupr'] = $duprByKey[$entry['player_key']];
    } else {
        $entry['dupr'] = $duprLookup[$entry['name']] ?? null;
    }
}
unset($entry);

// ── session_meta (Audit C M5 / Audit E H4) ─────────────────────────────
// For a specific session, return the fixed-teams flag and tournament
// placements the app saved with it. Guarded: if the columns are missing the
// board still renders, just without the badge.
$sessionMeta = null;
if ($batch_id !== 'all') {
    try {
        $metaRow = dbGetRow(
            "SELECT id, title, user_id, is_fixed_teams, tournament_placements, UNIX_TIMESTAMP(session_date) as session_timestamp
             FROM sessions WHERE id = ?",
            [(int)$batch_id]
        );
        if ($metaRow) {
            $placements = null;
            if (!empty($metaRow['tournament_placements'])) {
                $decoded = json_decode($metaRow['tournament_placements'], true);
                $placements = is_array($decoded) ? $decoded : null;
            }
            $sessionMeta = [
                'session_id'            => (int)$metaRow['id'],
                'title'                 => $metaRow['title'],
                'is_fixed_teams'        => ((int)($metaRow['is_fixed_teams'] ?? 0)) === 1,
                'tournament_placements' => $placements,
                'session_timestamp'     => (int)($metaRow['session_timestamp'] ?? 0),
                'is_yours'              => (strval($metaRow['user_id']) === strval($user_id)),
                'viewer_is_collaborator'=> $viewerIsCollaborator,
            ];
        }
    } catch (Throwable $e) {
        error_log("get_leaderboard session_meta skipped: " . $e->getMessage());
        $sessionMeta = null;
    }
}

echo json_encode([
    'status' => 'success',
    'leaderboard' => $leaderboard,
    'history' => $history,
    'roster' => $roster,
    'session_meta' => $sessionMeta
]);
