<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_config.php';

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$lobby_id = $input['lobby_id'] ?? null;
$host_user_id = $input['host_user_id'] ?? null;

if (!$lobby_id || !$host_user_id) {
    echo json_encode(['status' => 'error', 'message' => 'lobby_id and host_user_id are required']);
    exit;
}

$conn = getDBConnection();

// 1. Verify lobby exists and status='gathering'
$stmt = $conn->prepare("SELECT * FROM beacon_lobbies WHERE id = ?");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$lobby = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lobby) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby not found']);
    exit;
}

if ($lobby['status'] !== 'gathering') {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Lobby is not in gathering state (status: ' . $lobby['status'] . ')']);
    exit;
}

// 2. Verify caller is the host
if ($lobby['host_user_id'] !== $host_user_id) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Only the host can lock the lobby']);
    exit;
}

// 3. Get all active (non-left) members
$stmt = $conn->prepare("
    SELECT id, user_id, player_id, first_name, last_name, gender, status, reliability_pct
    FROM beacon_lobby_members
    WHERE lobby_id = ? AND status NOT IN ('left', 'replaced')
    ORDER BY joined_at ASC
");
$stmt->bind_param('i', $lobby_id);
$stmt->execute();
$result = $stmt->get_result();
$members = [];
while ($row = $result->fetch_assoc()) {
    $members[] = $row;
}
$stmt->close();

// 4. Verify all members have status='confirmed' (block if anyone is seeking_replacement)
foreach ($members as $member) {
    if ($member['status'] === 'seeking_replacement') {
        $conn->close();
        echo json_encode(['status' => 'error', 'message' => $member['first_name'] . ' is seeking a replacement. Wait for the spot to be filled or ask them to cancel.']);
        exit;
    }
    if ($member['status'] !== 'confirmed') {
        $conn->close();
        echo json_encode(['status' => 'error', 'message' => 'All members must confirm before locking. ' . $member['first_name'] . ' has not confirmed.']);
        exit;
    }
}

if (count($members) < 2) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Need at least 2 players to lock a lobby']);
    exit;
}

// 5. Build players array for schedule generation
$players = [];
foreach ($members as $member) {
    $players[] = [
        'id' => $member['player_id'] ? (string)$member['player_id'] : $member['user_id'],
        'first_name' => $member['first_name'] ?? 'Player',
        'gender' => $member['gender'] ?? 'unknown'
    ];
}

// 6. Generate schedule_json (round-robin matchups)
$schedule = generateSchedule($players);

// 7. Calculate match_quality_percent
$match_quality = calculateMatchQuality($members);

// 8. Update lobby to locked with schedule
$schedule_json = json_encode($schedule);
$stmt = $conn->prepare("
    UPDATE beacon_lobbies
    SET status = 'locked', schedule_json = ?, match_quality_percent = ?
    WHERE id = ?
");
$stmt->bind_param('sii', $schedule_json, $match_quality, $lobby_id);
$stmt->execute();
$stmt->close();

$conn->close();

echo json_encode([
    'status' => 'success',
    'schedule_json' => $schedule,
    'match_quality_percent' => $match_quality
]);

/**
 * Generate round-robin schedule from player list.
 */
function generateSchedule(array $players): array {
    $n = count($players);
    $schedule = [];

    if ($n < 4) {
        $half = (int)ceil($n / 2);
        $team1 = array_slice($players, 0, $half);
        $team2 = array_slice($players, $half);
        $schedule[] = [
            'games' => [['team1' => $team1, 'team2' => $team2]],
            'byes' => []
        ];
        return $schedule;
    }

    if ($n === 4) {
        $p = $players;
        $pairings = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]]
        ];
        foreach ($pairings as $pairing) {
            $schedule[] = [
                'games' => [[
                    'team1' => [$p[$pairing[0][0]], $p[$pairing[0][1]]],
                    'team2' => [$p[$pairing[1][0]], $p[$pairing[1][1]]]
                ]],
                'byes' => []
            ];
        }
        return $schedule;
    }

    // General case: N players
    $courts_per_round = (int)floor($n / 4);
    $bye_count = $n - ($courts_per_round * 4);

    $roster = [];
    for ($i = 0; $i < $n; $i++) {
        $roster[] = $i;
    }

    $num_rounds = min($n - 1, 6);

    for ($round = 0; $round < $num_rounds; $round++) {
        $games = [];
        $byes = [];
        $available = $roster;

        for ($b = 0; $b < $bye_count; $b++) {
            $bye_idx = array_pop($available);
            $byes[] = $players[$bye_idx];
        }

        for ($g = 0; $g < $courts_per_round; $g++) {
            $idx = $g * 4;
            if ($idx + 3 < count($available)) {
                $games[] = [
                    'team1' => [$players[$available[$idx]], $players[$available[$idx + 1]]],
                    'team2' => [$players[$available[$idx + 2]], $players[$available[$idx + 3]]]
                ];
            }
        }

        $schedule[] = ['games' => $games, 'byes' => $byes];

        if ($n > 1) {
            $first = $roster[0];
            $rest = array_slice($roster, 1);
            $last = array_pop($rest);
            array_unshift($rest, $last);
            $roster = array_merge([$first], $rest);
        }
    }

    return $schedule;
}

/**
 * Calculate match quality percentage based on player heuristics.
 */
function calculateMatchQuality(array $members): int {
    $quality = 75;
    $player_count = count($members);

    if ($player_count % 4 === 0) {
        $quality += 10;
    }

    $male_count = 0;
    $female_count = 0;
    foreach ($members as $member) {
        $gender = strtolower($member['gender'] ?? '');
        if ($gender === 'male' || $gender === 'm') {
            $male_count++;
        } elseif ($gender === 'female' || $gender === 'f') {
            $female_count++;
        }
    }
    if (abs($male_count - $female_count) <= 1) {
        $quality += 5;
    }

    $all_reliable = true;
    foreach ($members as $member) {
        $reliability = (float)($member['reliability_pct'] ?? 0);
        if ($reliability < 80.0) {
            $all_reliable = false;
            break;
        }
    }
    if ($all_reliable) {
        $quality += 10;
    }

    return min($quality, 100);
}
