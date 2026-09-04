<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';
require_once 'beacon_common.php'; // H1 session adoption + H2 ISO dates

$court_id = $_GET['court_id'] ?? null;
$user_id = $_GET['user_id'] ?? null;
$include_history = $_GET['include_history'] ?? null;
$user_lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$user_lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$radius_miles = isset($_GET['radius']) ? (float)$_GET['radius'] : 999;

// H1: a valid session token overrides the client-supplied user id.
$user_id = beacon_adopt_session_user($user_id);
$user_id = $user_id !== null ? (string)$user_id : null;

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
    exit;
}

$conn = getDBConnection();

// 1. Expire old beacons
$conn->query("UPDATE beacons SET status='expired' WHERE expires_at < NOW() AND status='active'");

// 1b. Auto-expire replacement requests for lobbies no longer in gathering/locked
$conn->query("
    UPDATE replacement_requests rr
    JOIN beacon_lobbies bl ON rr.lobby_id = bl.id
    SET rr.status = 'expired'
    WHERE rr.status = 'open' AND bl.status NOT IN ('gathering', 'locked')
");

// Check if beacon_type column exists (migration may not have been run yet)
$colCheck = $conn->query("SHOW COLUMNS FROM beacons LIKE 'beacon_type'");
$hasBeaconType = $colCheck && $colCheck->num_rows > 0;
if ($colCheck) $colCheck->free();
$beaconTypeCol = $hasBeaconType ? "b.beacon_type," : "'structured' AS beacon_type,";

// 2. Select active beacons (with optional radius filtering)
$has_location = ($user_lat !== null && $user_lng !== null);

if ($has_location) {
    $sql = "SELECT b.id, b.user_id, $beaconTypeCol b.court_id, b.player_count, b.skill_level, b.message,
                   b.status, b.expires_at, b.created_at,
                   c.name AS court_name,
                   c.lat AS court_lat,
                   c.lng AS court_lng,
                   CASE WHEN c.lat IS NOT NULL AND c.lng IS NOT NULL THEN
                       (3959 * ACOS(LEAST(1.0, GREATEST(-1.0,
                           COS(RADIANS($user_lat)) * COS(RADIANS(c.lat)) *
                           COS(RADIANS(c.lng) - RADIANS($user_lng)) +
                           SIN(RADIANS($user_lat)) * SIN(RADIANS(c.lat))
                       ))))
                   ELSE NULL END AS distance_miles
            FROM beacons b
            LEFT JOIN courts c ON b.court_id = c.id
            WHERE b.status = 'active'";
} else {
    $sql = "SELECT b.id, b.user_id, $beaconTypeCol b.court_id, b.player_count, b.skill_level, b.message,
                   b.status, b.expires_at, b.created_at,
                   c.name AS court_name,
                   c.lat AS court_lat,
                   c.lng AS court_lng
            FROM beacons b
            LEFT JOIN courts c ON b.court_id = c.id
            WHERE b.status = 'active'";
}

if ($court_id) {
    $sql .= " AND b.court_id = " . (int)$court_id;
}

if ($has_location) {
    $sql .= " HAVING distance_miles <= " . (float)$radius_miles . " OR distance_miles IS NULL";
    $sql .= " ORDER BY distance_miles IS NULL, distance_miles ASC";
} else {
    $sql .= " ORDER BY b.created_at DESC";
}

$result = $conn->query($sql);
if (!$result) {
    echo json_encode(['status' => 'error', 'message' => 'Beacon query failed: ' . $conn->error]);
    $conn->close();
    exit;
}

$beacons = [];
while ($row = $result->fetch_assoc()) {
    $beacons[] = $row;
}
$result->free();

// ── ISO-8601 date fields (UAT 2026-09-04) ─────────────────────────────────
// MySQL hands back 'YYYY-MM-DD HH:MM:SS' with no offset, in America/Los_Angeles.
// WebKit (Safari and every iOS browser) returns Invalid Date for that format,
// so the client's `expires_at > now` filter dropped EVERY beacon and the feed
// looked permanently empty; users in other time zones saw beacons expire at
// the wrong moment. The original string fields are left untouched for older
// clients; these unambiguous ones are added alongside.
// 3. Enrich each beacon
foreach ($beacons as $i => $beacon) {
    $uid = $conn->real_escape_string($beacon['user_id']);

    // Reliability (C3: 'started' counts as reached-a-match — nothing ever
    // writes 'completed', so counting only that pinned everyone at 0%)
    $beacons[$i]['reliability_pct'] = 100;
    $relResult = $conn->query(
        "SELECT COUNT(CASE WHEN bl.status IN ('started','completed') THEN 1 END) / NULLIF(COUNT(*), 0) * 100 AS reliability_pct
         FROM beacon_lobby_members blm
         JOIN beacon_lobbies bl ON blm.lobby_id = bl.id
         WHERE blm.user_id = '$uid' AND blm.status != 'replaced'"
    );
    if ($relResult) {
        $rel = $relResult->fetch_assoc();
        if ($rel && $rel['reliability_pct'] !== null) {
            $beacons[$i]['reliability_pct'] = round((float)$rel['reliability_pct'], 1);
        }
        $relResult->free();
    }

    // Distance (round to 1 decimal place if available)
    if (isset($beacon['distance_miles'])) {
        $beacons[$i]['distance_miles'] = round((float)$beacon['distance_miles'], 1);
    }

    // is_mine (string-compare: user_id is VARCHAR, sessions hand us an int)
    $beacons[$i]['is_mine'] = ((string)$beacon['user_id'] === (string)$user_id);

    // H2: ISO-8601 copies + seconds-until-expiry (original fields untouched)
    $beacons[$i] = beacon_add_time_fields($beacons[$i]);

    // Creator name — check users table first (authoritative), then user_profiles, then beacon_lobby_members
    $beacons[$i]['creator_name'] = 'Player';

    // Try users table first (login/auth — most reliable source of truth)
    $userResult = @$conn->query(
        "SELECT first_name, last_name FROM users WHERE id = '$uid' LIMIT 1"
    );
    if ($userResult) {
        $u = $userResult->fetch_assoc();
        if ($u && !empty($u['first_name'])) {
            $beacons[$i]['creator_name'] = trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''));
        }
        $userResult->free();
    }

    // Fall back to user_profiles
    if ($beacons[$i]['creator_name'] === 'Player') {
        $profileResult = $conn->query(
            "SELECT first_name, last_name FROM user_profiles WHERE user_id = '$uid' LIMIT 1"
        );
        if ($profileResult) {
            $profile = $profileResult->fetch_assoc();
            if ($profile && $profile['first_name']) {
                $beacons[$i]['creator_name'] = trim($profile['first_name'] . ' ' . $profile['last_name']);
            }
            $profileResult->free();
        }
    }

    // Fall back to beacon_lobby_members
    if ($beacons[$i]['creator_name'] === 'Player') {
        $nameResult = $conn->query(
            "SELECT first_name, last_name FROM beacon_lobby_members WHERE user_id = '$uid' ORDER BY id DESC LIMIT 1"
        );
        if ($nameResult) {
            $player = $nameResult->fetch_assoc();
            if ($player && $player['first_name']) {
                $beacons[$i]['creator_name'] = trim($player['first_name'] . ' ' . $player['last_name']);
            }
            $nameResult->free();
        }
    }

    // Chat message count
    $chatResult = $conn->query("SELECT COUNT(*) AS cnt FROM beacon_messages WHERE beacon_id = " . (int)$beacon['id']);
    if ($chatResult) {
        $beacons[$i]['chat_count'] = (int)$chatResult->fetch_assoc()['cnt'];
        $chatResult->free();
    } else {
        $beacons[$i]['chat_count'] = 0;
    }

    // Replacement info — check if any open replacement requests exist for this beacon's lobby
    $beacons[$i]['needs_replacement'] = false;
    $beacons[$i]['replacement_info'] = null;
    $repResult = $conn->query(
        "SELECT rr.id AS request_id, rr.lobby_id, rr.departing_user_id,
                blm.first_name AS departing_first_name, blm.last_name AS departing_last_name,
                bl.target_players
         FROM replacement_requests rr
         JOIN beacon_lobbies bl ON rr.lobby_id = bl.id
         JOIN beacon_lobby_members blm ON rr.departing_member_id = blm.id
         WHERE bl.beacon_id = " . (int)$beacon['id'] . "
           AND rr.status = 'open'
         LIMIT 1"
    );
    if ($repResult) {
        $rep = $repResult->fetch_assoc();
        if ($rep) {
            $beacons[$i]['needs_replacement'] = true;
            $beacons[$i]['replacement_info'] = [
                'request_id' => (int)$rep['request_id'],
                'lobby_id' => (int)$rep['lobby_id'],
                'departing_name' => trim($rep['departing_first_name'] . ' ' . $rep['departing_last_name']),
                'departing_user_id' => $rep['departing_user_id'],
                'target_players' => (int)$rep['target_players']
            ];
        }
        $repResult->free();
    }

    // Mode-specific enrichment
    $beacons[$i]['response_count'] = 0;
    $beacons[$i]['responses'] = [];
    $beacons[$i]['user_responded'] = false;
    $beacons[$i]['active_lobby_id'] = null;
    $beacons[$i]['lobby_member_count'] = 0;
    $beacons[$i]['active_lobby_status'] = null;
    $beacons[$i]['active_lobby_target_players'] = null;
    $beacons[$i]['i_am_in_lobby'] = false;

    if ($beacon['beacon_type'] === 'casual') {
        // Casual: fetch response count + respondents
        $respResult = $conn->query(
            "SELECT user_id, first_name, response_type, created_at
             FROM beacon_responses WHERE beacon_id = " . (int)$beacon['id'] . "
             ORDER BY created_at DESC"
        );
        if ($respResult) {
            $responses = [];
            while ($r = $respResult->fetch_assoc()) {
                $responses[] = $r;
                if ((string)$r['user_id'] === (string)$user_id) {
                    $beacons[$i]['user_responded'] = true;
                }
            }
            $beacons[$i]['response_count'] = count($responses);
            $beacons[$i]['responses'] = $responses;
            $respResult->free();
        }
    } else {
        // Structured: fetch active lobby info (C1: newest open lobby — the
        // old LIMIT 1 without ORDER BY picked an arbitrary one when duplicates
        // had been created)
        $lobbyResult = $conn->query(
            "SELECT bl.id, bl.status, bl.target_players,
                    (SELECT COUNT(*) FROM beacon_lobby_members WHERE lobby_id = bl.id AND status NOT IN ('left','replaced')) AS member_count,
                    (SELECT COUNT(*) FROM beacon_lobby_members WHERE lobby_id = bl.id AND user_id = '" . $conn->real_escape_string((string)$user_id) . "' AND status NOT IN ('left','replaced')) AS me_in
             FROM beacon_lobbies bl
             WHERE bl.beacon_id = " . (int)$beacon['id'] . " AND bl.status IN ('gathering','locked')
             ORDER BY bl.id DESC
             LIMIT 1"
        );
        if ($lobbyResult) {
            $lobbyRow = $lobbyResult->fetch_assoc();
            if ($lobbyRow) {
                $beacons[$i]['active_lobby_id'] = (int)$lobbyRow['id'];
                $beacons[$i]['lobby_member_count'] = (int)$lobbyRow['member_count'];
                $beacons[$i]['active_lobby_status'] = $lobbyRow['status'];
                $beacons[$i]['active_lobby_target_players'] = (int)$lobbyRow['target_players'];
                $beacons[$i]['i_am_in_lobby'] = ((int)$lobbyRow['me_in'] > 0);
            }
            $lobbyResult->free();
        }
    }
}

// 3b. C4 owner-resume / self-heal: the caller's own open lobby membership, so
//     a restarted or re-focused client can pick its lobby back up.
$my_lobby_id = null;
$my_lobby_status = null;
$my_lobby_beacon_id = null;
$my_lobby_role = null;
$myLobbyStmt = $conn->prepare(
    "SELECT bl.id, bl.status, bl.beacon_id, bl.host_user_id
     FROM beacon_lobby_members blm
     JOIN beacon_lobbies bl ON bl.id = blm.lobby_id
     WHERE blm.user_id = ? AND blm.status NOT IN ('left', 'replaced')
       AND bl.status IN ('gathering', 'locked')
     ORDER BY bl.id DESC LIMIT 1"
);
if ($myLobbyStmt) {
    $myLobbyStmt->bind_param('s', $user_id);
    $myLobbyStmt->execute();
    $myLobby = $myLobbyStmt->get_result()->fetch_assoc();
    $myLobbyStmt->close();
    if ($myLobby) {
        $my_lobby_id = (int)$myLobby['id'];
        $my_lobby_status = $myLobby['status'];
        $my_lobby_beacon_id = (int)$myLobby['beacon_id'];
        $my_lobby_role = ((string)$myLobby['host_user_id'] === (string)$user_id) ? 'host' : 'member';
    }
}

// 4. Courts with active beacons (M5: include city/lat/lng — old fields kept)
$courtsResult = $conn->query(
    "SELECT DISTINCT c.id, c.name, c.city, c.lat, c.lng
     FROM courts c
     INNER JOIN beacons b ON c.id = b.court_id AND b.status = 'active'
     ORDER BY c.name"
);
$courts = [];
if ($courtsResult) {
    while ($row = $courtsResult->fetch_assoc()) {
        $courts[] = $row;
    }
    $courtsResult->free();
}

// 5. History (expired/cancelled beacons from last 24 hours)
$history = [];
if ($include_history) {
    $histSql = "SELECT b.id, b.user_id, $beaconTypeCol b.court_id, b.player_count, b.skill_level, b.message,
                       b.status, b.expires_at, b.created_at,
                       c.name AS court_name,
                       c.lat AS court_lat,
                       c.lng AS court_lng,
                       (SELECT bl.status FROM beacon_lobbies bl WHERE bl.beacon_id = b.id ORDER BY bl.id DESC LIMIT 1) AS lobby_status
                FROM beacons b
                LEFT JOIN courts c ON b.court_id = c.id
                WHERE b.status IN ('expired', 'cancelled')
                  AND b.expires_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
                ORDER BY b.created_at DESC
                LIMIT 20";
    $histResult = $conn->query($histSql);
    if ($histResult) {
        while ($row = $histResult->fetch_assoc()) {
            $uid = $conn->real_escape_string($row['user_id']);

            // Creator name — check users table first
            $row['creator_name'] = 'Player';
            $uResult = @$conn->query("SELECT first_name, last_name FROM users WHERE id = '$uid' LIMIT 1");
            if ($uResult) {
                $u = $uResult->fetch_assoc();
                if ($u && !empty($u['first_name'])) {
                    $row['creator_name'] = trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''));
                }
                $uResult->free();
            }
            if ($row['creator_name'] === 'Player') {
                $pResult = $conn->query("SELECT first_name, last_name FROM user_profiles WHERE user_id = '$uid' LIMIT 1");
                if ($pResult) {
                    $p = $pResult->fetch_assoc();
                    if ($p && $p['first_name']) {
                        $row['creator_name'] = trim($p['first_name'] . ' ' . $p['last_name']);
                    }
                    $pResult->free();
                }
            }

            $row['is_mine'] = ((string)$row['user_id'] === (string)$user_id);
            // L3: a beacon whose lobby reached the match is "Started", not "Expired"
            $row['was_started'] = in_array($row['lobby_status'] ?? '', ['started', 'completed'], true);
            $row = beacon_add_time_fields($row);
            $history[] = $row;
        }
        $histResult->free();
    }
}

$conn->close();

echo json_encode([
    'status' => 'success',
    'beacons' => $beacons,
    'courts' => $courts,
    'history' => $history,
    // additions
    'my_lobby_id' => $my_lobby_id,
    'my_lobby_status' => $my_lobby_status,
    'my_lobby_beacon_id' => $my_lobby_beacon_id,
    'my_lobby_role' => $my_lobby_role,
    'has_location' => $has_location,
    'server_now_iso' => date('c'),
]);
