<?php
// api/get_rankings.php
// UPDATED: Reads directly from the local API folder

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// 1. TARGET THE FILE IN THE CURRENT API FOLDER
$file_path = __DIR__ . '/pickleball_data.json';

if (!file_exists($file_path)) {
    echo json_encode([]);
    exit;
}

// 2. LOAD DATA
$raw_data = file_get_contents($file_path);
$data = json_decode($raw_data, true);

// Robust check: If file is empty or corrupt, return empty list
if (!$data) {
    echo json_encode([]);
    exit;
}

$input_group = $_GET['group'] ?? 'GLOBAL';
$players = [];

function updatePlayer(&$list, $id, $name, $won, $pf, $pa) {
    if (!$id) return;
    $key = $id; 
    if (!isset($list[$key])) {
        $list[$key] = ['id' => $id, 'name' => $name, 'wins' => 0, 'losses' => 0, 'points' => 0, 'games' => 0];
    }
    $list[$key]['games']++;
    $list[$key]['points'] += $pf;
    if ($won) $list[$key]['wins']++;
    else $list[$key]['losses']++;
}

foreach ($data as $group_name => $group_data) {
    // Global vs Specific Group Logic
    $req_group = strtoupper($input_group);
    $curr_group = strtoupper($group_name);

    if ($req_group !== 'GLOBAL' && $req_group !== '' && $req_group !== $curr_group) {
        continue; 
    }

    if (isset($group_data['history'])) {
        foreach ($group_data['history'] as $match) {
            $s1 = (int)($match['s1'] ?? 0);
            $s2 = (int)($match['s2'] ?? 0);
            $team1_won = ($s1 > $s2);
            $team2_won = ($s2 > $s1);

            updatePlayer($players, $match['p1'], $match['p1_name'], $team1_won, $s1, $s2);
            updatePlayer($players, $match['p2'], $match['p2_name'], $team1_won, $s1, $s2);
            updatePlayer($players, $match['p3'], $match['p3_name'], $team2_won, $s2, $s1);
            updatePlayer($players, $match['p4'], $match['p4_name'], $team2_won, $s2, $s1);
        }
    }
}

// 3. SORT BY WINS
usort($players, function($a, $b) { 
    if ($b['wins'] == $a['wins']) {
        return $b['points'] <=> $a['points']; 
    }
    return $b['wins'] <=> $a['wins']; 
});

echo json_encode(array_values($players));
?>