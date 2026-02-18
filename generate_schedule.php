<?php
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

error_log("📊 Received " . count($sent_players) . " players, " . count($round_configs) . " rounds");

if (empty($group_key))     { echo json_encode(['status'=>'error','message'=>'Group key required']); exit; }
if (empty($round_configs)) { echo json_encode(['status'=>'success','schedule'=>[]]); exit; }

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

    $rows = dbGetAll("SELECT player_key as id, first_name, gender FROM players WHERE group_id = ? ORDER BY first_name", [$group['id']]);
    if (empty($rows)) { echo json_encode(['status'=>'success','schedule'=>[]]); exit; }

    foreach ($rows as $r) {
        $id = (string)$r['id'];
        $P[$id] = ['id'=>$id, 'first_name'=>$r['first_name'], 'gender'=>strtolower($r['gender'] ?? 'male')];
    }
}
$all_ids = array_keys($P);

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

function chooseSitters($ids, $sitCounts, $n, $P) {
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

// ── MAIN ──
$best = null;

foreach ([1, 2] as $maxAllowed) {
    for ($run = 0; $run < 50; $run++) {
        $pairCounts = [];
        $sitCount   = array_fill_keys($all_ids, 0);
        $mixedCounts = array_fill_keys($all_ids, 0); // Track mixed game participation
        $schedule   = [];
        $failed     = false;

        foreach ($round_configs as $cfg) {
            $mode    = $cfg['type'] ?? 'mixer';
            $sitN    = count($all_ids) % 4;
            $sitters = chooseSitters($all_ids, $sitCount, $sitN, $P);
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
                $games = $result['games'];
                $mixedCounts = $result['mixedCounts'];
            } else {
                $games = $result;
            }

            foreach ($games as $g) {
                $p1=$g['team1'][0]['id']; $p2=$g['team1'][1]['id'];
                $p3=$g['team2'][0]['id']; $p4=$g['team2'][1]['id'];
                $pairCounts[pk($p1,$p2)] = ($pairCounts[pk($p1,$p2)]??0) + 1;
                $pairCounts[pk($p3,$p4)] = ($pairCounts[pk($p3,$p4)]??0) + 1;
            }

            $byes = [];
            foreach ($sitters as $s) { $sitCount[$s]++; $byes[] = $P[$s]; }
            $schedule[] = ['games'=>$games, 'byes'=>$byes, 'type'=>$mode];
        }

        if (!$failed) { $best = $schedule; break 2; }
    }
}

if ($best === null) $best = [];

error_log("🎾 Schedule result: " . (empty($best) ? "FAILED/EMPTY" : count($best) . " rounds"));

echo json_encode(['status'=>'success', 'schedule'=>$best]);
?>
