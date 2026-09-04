<?php
// ============================================================
// generate_schedule.php
//
// Builds a round-by-round doubles schedule for a group.
//
// UAT 2026-09-04 (Audit C H1/H2/M3/M11):
//  - The HTTP handler is now guarded so the scheduling functions can be
//    included by playpbnow-api/tests/schedule_balance_test.php without
//    sending headers or touching the database. Define PBNOW_SCHEDULE_TEST
//    before including this file from the CLI to get "library mode".
//  - The pairing-repeat cap rises progressively (1..max(2, rounds)) instead
//    of giving up after [1, 2]. 4 players 2M/2F have only two legal mixed
//    pairings, so 6 rounds NEEDS each pairing three times — the old caps
//    made every run fail and the endpoint answered status:success with an
//    empty schedule, which the app rendered as "No Matches Generated".
//  - The endpoint never returns status:success with an empty schedule any
//    more; it returns status:error with a plain-English reason.
//  - New optional `courts` input (default floor(n/4), min 1):
//    games per round = min(floor(n/4), courts); sitters = n - 4*games.
//  - chooseSitters picks by LOWEST overall sit count; gender is only a
//    tiebreak. The old gender-ratio split made women never sit with 3M/2F
//    and made each woman sit half the rounds with 4M/2F.
// ============================================================

$__pbnowScheduleTestMode = (php_sapi_name() === 'cli' && defined('PBNOW_SCHEDULE_TEST'));

if (!$__pbnowScheduleTestMode) {
    date_default_timezone_set('America/Los_Angeles');
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

    require_once __DIR__ . '/db_config.php';

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { echo json_encode(['status'=>'error','message'=>'Invalid input']); exit; }

    $group_key     = $input['group_key'] ?? '';
    $round_configs = $input['round_configs'] ?? [];
    $sent_players  = $input['players'] ?? [];
    // Courts available. 0 / missing = "as many as the roster can fill".
    $courts_input  = (int)($input['courts'] ?? 0);

    error_log("📊 Received " . count($sent_players) . " players, " . count($round_configs) . " rounds, courts=" . $courts_input);

    if (empty($group_key))     { echo json_encode(['status'=>'error','message'=>'Group key required']); exit; }
    // Previously: status success + empty schedule. The app treats an empty
    // schedule as a failure, so say so plainly.
    if (empty($round_configs)) { echo json_encode(['status'=>'error','message'=>'No rounds configured. Add at least one round and try again.','schedule'=>[]]); exit; }

    // ── LOAD PLAYERS ──
    $P = [];

    if (!empty($sent_players)) {
        foreach ($sent_players as $r) {
            $id = (string)($r['id'] ?? '');
            if (empty($id)) continue;
            $P[$id] = [
                'id' => $id,
                'first_name' => $r['first_name'] ?? 'Unknown',
                'gender' => strtolower($r['gender'] ?? 'male')
            ];
        }
    } else {
        $group = dbGetRow("SELECT id FROM `groups` WHERE group_key = ?", [$group_key]);
        if (!$group) { echo json_encode(['status'=>'error','message'=>'Group not found']); exit; }

        // Roster comes from player_group_memberships, not the legacy players.group_id
        // column. A player added to a second group only ever gets a membership row,
        // so the old query silently dropped them from generated schedules.
        // Ordering stays on first_name to keep schedule output identical.
        $rows = dbGetAll(
            "SELECT p.player_key as id, p.first_name, p.gender
             FROM players p
             INNER JOIN player_group_memberships pgm ON pgm.player_id = p.id
             WHERE pgm.group_id = ? AND p._deleted_at IS NULL
             ORDER BY p.first_name",
            [$group['id']]
        );
        if (empty($rows)) { echo json_encode(['status'=>'error','message'=>'This group has no players yet. Add players before generating a schedule.','schedule'=>[]]); exit; }

        foreach ($rows as $r) {
            $id = (string)$r['id'];
            $P[$id] = ['id'=>$id, 'first_name'=>$r['first_name'], 'gender'=>strtolower($r['gender'] ?? 'male')];
        }
    }
}

// ── HELPERS ──

function pk($a, $b) { return $a < $b ? "$a|$b" : "$b|$a"; }

function isFemale($id, $P) { return strpos($P[$id]['gender'], 'f') === 0; }

function genderViolation($a, $b, $c, $d, $P) {
    $t1m = !isFemale($a,$P) && !isFemale($b,$P);
    $t1f =  isFemale($a,$P) &&  isFemale($b,$P);
    $t2m = !isFemale($c,$P) && !isFemale($d,$P);
    $t2f =  isFemale($c,$P) &&  isFemale($d,$P);
    return ($t1m && $t2f) || ($t1f && $t2m);
}

function G($a,$b,$c,$d,$P) {
    return ['team1'=>[$P[$a],$P[$b]],'team2'=>[$P[$c],$P[$d]]];
}

// ------------------------------------------------------------------
// LEGACY sitter picker — kept for reference, no longer called.
// It split the sit seats by gender ratio EVERY round, so the per-gender
// buckets were balanced but the overall sit counts were not (Audit C H2:
// 5 players 3M/2F → women never sat; 6 players 4M/2F → each woman sat 3 of 6).
// ------------------------------------------------------------------
function chooseSitters_byGenderRatio($ids, $sitCounts, $n, $P) {
    if ($n <= 0) return [];

    $males = array_filter($ids, function($id) use ($P) { return !isFemale($id, $P); });
    $females = array_filter($ids, function($id) use ($P) { return isFemale($id, $P); });

    $maleCount = count($males);
    $femaleCount = count($females);
    $totalPlayers = $maleCount + $femaleCount;

    $malesNeeded = 0;
    $femalesNeeded = 0;

    if ($totalPlayers == 0) return [];

    $maleRatio = $maleCount / $totalPlayers;

    $malesNeeded = round($n * $maleRatio);
    $femalesNeeded = $n - $malesNeeded;

    if ($malesNeeded > $maleCount) {
        $malesNeeded = $maleCount;
        $femalesNeeded = $n - $malesNeeded;
    }
    if ($femalesNeeded > $femaleCount) {
        $femalesNeeded = $femaleCount;
        $malesNeeded = $n - $femalesNeeded;
    }

    $selectedMales = [];
    if ($malesNeeded > 0 && count($males) > 0) {
        $maleGroups = [];
        foreach ($males as $id) {
            $count = $sitCounts[$id] ?? 0;
            if (!isset($maleGroups[$count])) {
                $maleGroups[$count] = [];
            }
            $maleGroups[$count][] = $id;
        }
        ksort($maleGroups);

        foreach ($maleGroups as $playerList) {
            shuffle($playerList);
            foreach ($playerList as $player) {
                $selectedMales[] = $player;
                if (count($selectedMales) >= $malesNeeded) break 2;
            }
        }
    }

    $selectedFemales = [];
    if ($femalesNeeded > 0 && count($females) > 0) {
        $femaleGroups = [];
        foreach ($females as $id) {
            $count = $sitCounts[$id] ?? 0;
            if (!isset($femaleGroups[$count])) {
                $femaleGroups[$count] = [];
            }
            $femaleGroups[$count][] = $id;
        }
        ksort($femaleGroups);

        foreach ($femaleGroups as $playerList) {
            shuffle($playerList);
            foreach ($playerList as $player) {
                $selectedFemales[] = $player;
                if (count($selectedFemales) >= $femalesNeeded) break 2;
            }
        }
    }

    return array_merge($selectedMales, $selectedFemales);
}

// ------------------------------------------------------------------
// chooseSitters — pick $n sitters by LOWEST overall sit count.
//
// Everyone with the fewest sits goes first, so after R rounds no player has
// sat more than one round more than anyone else (max(sit) - min(sit) <= 1).
// Within the same sit count the order is random, with gender as the only
// tiebreak: the majority gender in the roster sits first, which keeps the
// playing pool as gender-balanced as possible for mixed rounds.
// ------------------------------------------------------------------
function chooseSitters($ids, $sitCounts, $n, $P) {
    if ($n <= 0) return [];
    $ids = array_values($ids);
    if (empty($ids)) return [];

    $maleCount = 0; $femaleCount = 0;
    foreach ($ids as $id) { if (isFemale($id, $P)) $femaleCount++; else $maleCount++; }
    // +1 = this gender should sit first on a tie; 0 = neutral.
    $majorityIsFemale = $femaleCount > $maleCount;
    $genderTie = ($femaleCount === $maleCount);

    shuffle($ids);                       // random order among true ties
    $order = array_flip($ids);           // remember the shuffled position (stable sort)

    usort($ids, function($a, $b) use ($sitCounts, $P, $majorityIsFemale, $genderTie, $order) {
        $ca = $sitCounts[$a] ?? 0;
        $cb = $sitCounts[$b] ?? 0;
        if ($ca !== $cb) return $ca <=> $cb;          // fewest sits first
        if (!$genderTie) {                            // gender only as tiebreak
            $fa = isFemale($a, $P); $fb = isFemale($b, $P);
            if ($fa !== $fb) {
                $aMajority = ($fa === $majorityIsFemale);
                return $aMajority ? -1 : 1;           // majority gender sits first
            }
        }
        return $order[$a] <=> $order[$b];             // keep the shuffle
    });

    return array_slice($ids, 0, min($n, count($ids)));
}

/**
 * Gender-aware sitter choice for MIXED rounds (UAT 2026-09-04).
 *
 * chooseSitters() balances how often each person sits, which is correct, but it
 * is blind to what the round actually needs. A "mixed" round with G games needs
 * exactly 2*G men and 2*G women on court. Once the courts stepper let an
 * organiser play fewer games than floor(players/4), the sit-count-only choice
 * regularly left a lopsided playing set — e.g. 5 men and 3 women for 2 courts —
 * and buildRound() then fell back to same-gender teams. A 16-player group on 2
 * courts produced EIGHT same-gender teams inside rounds labelled
 * "MIXED DOUBLES".
 *
 * This picks the sitters so the players left standing are gender-balanced,
 * still taking whoever has sat least within each gender. It returns null when
 * balance is impossible (e.g. not enough of one gender), and the caller then
 * falls back to the original chooser so nothing regresses.
 */
function chooseSittersForMixed($ids, $sitCounts, $n, $P, $gamesThisRound) {
    $ids = array_values($ids);
    if ($n <= 0 || empty($ids)) return null;
    if ($gamesThisRound <= 0) return null;

    $needPerGender = 2 * $gamesThisRound;   // men on court, and women on court

    $males = []; $females = [];
    foreach ($ids as $id) {
        if (isFemale($id, $P)) { $females[] = $id; } else { $males[] = $id; }
    }
    // Not enough of one gender to build an all-mixed round — let the caller
    // use the normal chooser and let buildRound do what it can.
    if (count($males) < $needPerGender || count($females) < $needPerGender) return null;

    // Only balance when BOTH genders still have someone spare to rotate. If one
    // gender has exactly the number needed on court, forcing a balanced court
    // pins that gender in every round and makes the OTHER gender absorb every
    // sit-out — which is the unfairness H2 fixed (5 players 3M/2F: the two
    // women would never sit). In that case fall back to the fair chooser and
    // accept that buildRound may need a non-mixed pairing.
    if (count($males) <= $needPerGender || count($females) <= $needPerGender) return null;

    $maleSitters   = count($males)   - $needPerGender;
    $femaleSitters = count($females) - $needPerGender;
    if ($maleSitters < 0 || $femaleSitters < 0) return null;
    if (($maleSitters + $femaleSitters) !== $n) return null;   // sanity

    // Fewest sits first within each gender, shuffled among true ties.
    $pick = function($pool, $take) use ($sitCounts) {
        if ($take <= 0) return [];
        $pool = array_values($pool);
        shuffle($pool);
        $order = array_flip($pool);
        usort($pool, function($a, $b) use ($sitCounts, $order) {
            $ca = $sitCounts[$a] ?? 0;
            $cb = $sitCounts[$b] ?? 0;
            if ($ca !== $cb) return $ca <=> $cb;
            return $order[$a] <=> $order[$b];
        });
        return array_slice($pool, 0, $take);
    };

    return array_merge($pick($males, $maleSitters), $pick($females, $femaleSitters));
}

function buildRound($pool, $pairCounts, $maxAllowed, $mode, $P, $mixedCounts) {
    if (count($pool) < 4) return [];

    $attempt = function($orderedPool) use ($pairCounts, $maxAllowed, $mode, $P, $mixedCounts) {
        $games = [];
        $localMixedCounts = $mixedCounts; // Track mixed participation this round

        if ($mode === 'mixed') {
            $ms = array_values(array_filter($orderedPool, function($id) use ($P) { return !isFemale($id,$P); }));
            $fs = array_values(array_filter($orderedPool, function($id) use ($P) { return isFemale($id,$P); }));
            while (count($ms) >= 2 && count($fs) >= 2) {
                $a=array_shift($ms); $b=array_shift($fs);
                $c=array_shift($ms); $d=array_shift($fs);
                if (($pairCounts[pk($a,$b)]??0) >= $maxAllowed) return false;
                if (($pairCounts[pk($c,$d)]??0) >= $maxAllowed) return false;
                $games[] = G($a,$b,$c,$d,$P);
            }
            $left = array_merge($ms, $fs);
            while (count($left) >= 4) {
                $a=array_shift($left); $b=array_shift($left);
                $c=array_shift($left); $d=array_shift($left);
                if (($pairCounts[pk($a,$b)]??0) >= $maxAllowed) return false;
                if (($pairCounts[pk($c,$d)]??0) >= $maxAllowed) return false;
                if (genderViolation($a,$b,$c,$d,$P)) return false;
                $games[] = G($a,$b,$c,$d,$P);
            }
            return $games;
        }

        if ($mode === 'gender') {
            $ms = array_values(array_filter($orderedPool, function($id) use ($P) { return !isFemale($id,$P); }));
            $fs = array_values(array_filter($orderedPool, function($id) use ($P) { return isFemale($id,$P); }));

            // Men vs men games
            while (count($ms) >= 4) {
                $a=array_shift($ms); $b=array_shift($ms);
                $c=array_shift($ms); $d=array_shift($ms);
                if (($pairCounts[pk($a,$b)]??0) >= $maxAllowed) return false;
                if (($pairCounts[pk($c,$d)]??0) >= $maxAllowed) return false;
                $games[] = G($a,$b,$c,$d,$P);
            }

            // Women vs women games
            while (count($fs) >= 4) {
                $a=array_shift($fs); $b=array_shift($fs);
                $c=array_shift($fs); $d=array_shift($fs);
                if (($pairCounts[pk($a,$b)]??0) >= $maxAllowed) return false;
                if (($pairCounts[pk($c,$d)]??0) >= $maxAllowed) return false;
                $games[] = G($a,$b,$c,$d,$P);
            }

            // MIXED DOUBLES for leftovers - PRIORITIZE players who've had fewest mixed games
            $left = array_merge($ms, $fs);

            while (count($left) >= 4) {
                $leftM = array_values(array_filter($left, function($id) use ($P) { return !isFemale($id,$P); }));
                $leftF = array_values(array_filter($left, function($id) use ($P) { return isFemale($id,$P); }));

                if (count($leftM) >= 2 && count($leftF) >= 2) {
                    // Sort by mixed game count (fairness: pick those with least mixed games)
                    usort($leftM, function($a, $b) use ($localMixedCounts) {
                        return ($localMixedCounts[$a] ?? 0) <=> ($localMixedCounts[$b] ?? 0);
                    });
                    usort($leftF, function($a, $b) use ($localMixedCounts) {
                        return ($localMixedCounts[$a] ?? 0) <=> ($localMixedCounts[$b] ?? 0);
                    });

                    // Pick players with fewest mixed games
                    $a = array_shift($leftM); $b = array_shift($leftF);
                    $c = array_shift($leftM); $d = array_shift($leftF);

                    if (($pairCounts[pk($a,$b)]??0) >= $maxAllowed) return false;
                    if (($pairCounts[pk($c,$d)]??0) >= $maxAllowed) return false;

                    $games[] = G($a,$b,$c,$d,$P);

                    // Track that these players played mixed
                    $localMixedCounts[$a] = ($localMixedCounts[$a] ?? 0) + 1;
                    $localMixedCounts[$b] = ($localMixedCounts[$b] ?? 0) + 1;
                    $localMixedCounts[$c] = ($localMixedCounts[$c] ?? 0) + 1;
                    $localMixedCounts[$d] = ($localMixedCounts[$d] ?? 0) + 1;

                    // Update $left
                    $left = array_merge($leftM, $leftF);
                } else {
                    // Not enough for perfect mixed, just take next 4
                    $a=array_shift($left); $b=array_shift($left);
                    $c=array_shift($left); $d=array_shift($left);
                    if (($pairCounts[pk($a,$b)]??0) >= $maxAllowed) return false;
                    if (($pairCounts[pk($c,$d)]??0) >= $maxAllowed) return false;
                    if (genderViolation($a,$b,$c,$d,$P)) return false;
                    $games[] = G($a,$b,$c,$d,$P);
                }
            }
            return ['games' => $games, 'mixedCounts' => $localMixedCounts];
        }

        // mixer mode
        $rem = $orderedPool;
        while (count($rem) >= 4) {
            $a=array_shift($rem); $b=array_shift($rem);
            $c=array_shift($rem); $d=array_shift($rem);
            if (($pairCounts[pk($a,$b)]??0) >= $maxAllowed) return false;
            if (($pairCounts[pk($c,$d)]??0) >= $maxAllowed) return false;
            if (genderViolation($a,$b,$c,$d,$P)) return false;
            $games[] = G($a,$b,$c,$d,$P);
        }
        return $games;
    };

    for ($i = 0; $i < 2000; $i++) {
        shuffle($pool);
        $result = $attempt($pool);
        if ($result !== false) return $result;
    }
    return false;
}

// ------------------------------------------------------------------
// pbnow_schedule_games_per_round(n, courts)
// games = min(floor(n/4), courts); courts <= 0 means "no limit" (floor(n/4)).
// Always at least 1 game when n >= 4.
// ------------------------------------------------------------------
function pbnow_schedule_games_per_round(int $n, int $courts = 0): int {
    $maxGames = intdiv($n, 4);
    if ($maxGames < 1) return 0;
    if ($courts < 1) $courts = $maxGames;   // default: fill every possible court
    return max(1, min($maxGames, $courts));
}

// ------------------------------------------------------------------
// pbnow_build_schedule(P, round_configs, courts)
//
// The MAIN loop, extracted so it can be unit-tested. Returns
//   ['schedule' => [...rounds...], 'error' => null, 'cap_used' => int]
// or ['schedule' => [], 'error' => 'plain English reason'].
//
// Pairing-repeat cap rises progressively: 1, 2, ... max(2, rounds). A cap of
// R can never fail for lack of fresh partners (a pairing may repeat every
// round), so the loop is guaranteed to terminate with a schedule whenever the
// roster is big enough for one game.
// ------------------------------------------------------------------
function pbnow_build_schedule(array $P, array $round_configs, int $courts = 0): array {
    $all_ids = array_keys($P);
    $n       = count($all_ids);
    $rounds  = count($round_configs);

    if ($rounds < 1) {
        return ['schedule' => [], 'error' => 'No rounds configured. Add at least one round and try again.'];
    }
    if ($n < 4) {
        return ['schedule' => [], 'error' => "Need at least 4 players to build a schedule (you have {$n})."];
    }

    $games   = pbnow_schedule_games_per_round($n, $courts);
    $sitN    = $n - 4 * $games;

    $best    = null;
    $capUsed = null;

    $maxCap = max(2, $rounds);
    for ($maxAllowed = 1; $maxAllowed <= $maxCap; $maxAllowed++) {
        for ($run = 0; $run < 50; $run++) {
            $pairCounts  = [];
            $sitCount    = array_fill_keys($all_ids, 0);
            $mixedCounts = array_fill_keys($all_ids, 0); // Track mixed game participation
            $schedule    = [];
            $failed      = false;

            foreach ($round_configs as $cfg) {
                // Accept BOTH shapes: {"type":"mixed"} (what the app sends) and a
                // bare "mixed" string. A string used to fall through to 'mixer'
                // silently, so a caller using the simpler form got same-gender
                // teams in rounds it had asked to be mixed, with no error
                // anywhere to explain it (UAT 2026-09-04).
                if (is_array($cfg)) {
                    $mode = $cfg['type'] ?? 'mixer';
                } elseif (is_string($cfg) && $cfg !== '') {
                    $mode = strtolower(trim($cfg));
                } else {
                    $mode = 'mixer';
                }
                if (!in_array($mode, ['mixed', 'gender', 'mixer'], true)) $mode = 'mixer';
                // A mixed round needs an even split on court; see
                // chooseSittersForMixed(). Falls back to the plain chooser when
                // an even split is not possible.
                $sitters = null;
                if ($mode === 'mixed') {
                    $sitters = chooseSittersForMixed($all_ids, $sitCount, $sitN, $P, $games);
                }
                if ($sitters === null) {
                    $sitters = chooseSitters($all_ids, $sitCount, $sitN, $P);
                }
                $pool    = array_values(array_diff($all_ids, $sitters));

                $result = buildRound($pool, $pairCounts, $maxAllowed, $mode, $P, $mixedCounts);

                if ($result === false) {
                    if ($mode === 'mixed') {
                        $result = buildRound($pool, $pairCounts, $maxAllowed, 'mixer', $P, $mixedCounts);
                    }
                }

                if ($result === false) { $failed = true; break; }

                // Extract games and update mixed counts if gender mode
                if (is_array($result) && isset($result['games'])) {
                    $gamesOut    = $result['games'];
                    $mixedCounts = $result['mixedCounts'];
                } else {
                    $gamesOut = $result;
                }

                foreach ($gamesOut as $g) {
                    $p1=$g['team1'][0]['id']; $p2=$g['team1'][1]['id'];
                    $p3=$g['team2'][0]['id']; $p4=$g['team2'][1]['id'];
                    $pairCounts[pk($p1,$p2)] = ($pairCounts[pk($p1,$p2)]??0) + 1;
                    $pairCounts[pk($p3,$p4)] = ($pairCounts[pk($p3,$p4)]??0) + 1;
                }

                $byes = [];
                foreach ($sitters as $s) { $sitCount[$s]++; $byes[] = $P[$s]; }
                $schedule[] = ['games'=>$gamesOut, 'byes'=>$byes, 'type'=>$mode];
            }

            if (!$failed) { $best = $schedule; $capUsed = $maxAllowed; break 2; }
        }
    }

    if ($best === null || empty($best)) {
        return [
            'schedule' => [],
            'error'    => "Could not build {$rounds} rounds for {$n} players. Reduce rounds or change round types.",
        ];
    }

    return ['schedule' => $best, 'error' => null, 'cap_used' => $capUsed, 'games_per_round' => $games, 'sit_per_round' => $sitN];
}

// ── MAIN (HTTP only) ──
if (!$__pbnowScheduleTestMode) {
    $built = pbnow_build_schedule($P, $round_configs, $courts_input);
    $best  = $built['schedule'];

    error_log("Schedule result: " . (empty($best) ? "FAILED/EMPTY (" . ($built['error'] ?? '') . ")" : count($best) . " rounds, cap=" . ($built['cap_used'] ?? '?')));

    if (empty($best)) {
        // NEVER status:success with an empty schedule — the client shows
        // data.message to the organizer instead of "No Matches Generated".
        echo json_encode([
            'status'   => 'error',
            'message'  => $built['error'] ?? 'Could not build a schedule for this roster.',
            'schedule' => [],
        ]);
        exit;
    }

    echo json_encode([
        'status'          => 'success',
        'schedule'        => $best,
        'games_per_round' => $built['games_per_round'] ?? null,
        'sit_per_round'   => $built['sit_per_round'] ?? null,
        'pair_repeat_cap' => $built['cap_used'] ?? null,
    ]);
}
?>
