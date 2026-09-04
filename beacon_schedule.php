<?php
// ============================================================
// beacon_schedule.php — ONE schedule generator + match-quality calculator
// shared by beacon_lock_lobby.php and beacon_accept_replacement.php (H4).
//
// Before this include existed each file carried its own copy, and the
// accept-replacement copy built players WITHOUT ids — so a roster regenerated
// after a replacement produced schedule entries the scoring screen could not
// key on (player.id undefined). Both callers now build players through
// beacon_schedule_players() which always sets id = player_id ?: user_id,
// exactly like the original lock-lobby path.
//
// The per-file copies are kept behind function_exists() guards as fallbacks;
// this include is loaded first so these definitions win.
// ============================================================

if (!function_exists('beacon_schedule_players')) {
    /**
     * Map beacon_lobby_members rows -> player objects for the schedule.
     * id is always present: player_id when known, else the user_id string.
     */
    function beacon_schedule_players(array $members): array {
        $players = [];
        foreach ($members as $member) {
            $players[] = [
                'id' => !empty($member['player_id']) ? (string)$member['player_id'] : (string)($member['user_id'] ?? ''),
                'user_id' => (string)($member['user_id'] ?? ''),
                'first_name' => $member['first_name'] ?? 'Player',
                'last_name' => $member['last_name'] ?? '',
                'gender' => $member['gender'] ?? 'unknown',
            ];
        }
        return $players;
    }
}

if (!function_exists('generateSchedule')) {
    /**
     * Generate round-robin schedule from player list (same algorithm that
     * beacon_lock_lobby.php has always used).
     */
    function generateSchedule(array $players): array {
        $n = count($players);
        $schedule = [];

        if ($n < 2) return [];

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
}

if (!function_exists('calculateMatchQuality')) {
    /**
     * Match quality heuristic (same as beacon_lock_lobby.php). Accepts member
     * rows (with reliability_pct) or plain player arrays.
     */
    function calculateMatchQuality(array $members): int {
        $quality = 75;
        $player_count = count($members);

        if ($player_count > 0 && $player_count % 4 === 0) {
            $quality += 10;
        }

        $male_count = 0;
        $female_count = 0;
        foreach ($members as $member) {
            $gender = strtolower((string)($member['gender'] ?? ''));
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
}
