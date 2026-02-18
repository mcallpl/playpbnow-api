<?php
// ============================================
// update_player_stats.php — Recalculates all player stats
// from match history in the matches table.
//
// Call after saving scores:
//   GET: update_player_stats.php (recalcs ALL players)
//   GET: update_player_stats.php?player_key=xxx (recalcs one player)
//
// Logic: A player is on team1 if they are p1 or p2.
//        A player is on team2 if they are p3 or p4.
//        Win = their team's score > opponent's score
//        Diff = sum of (own_score - opponent_score) across all matches
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_config.php';

try {
    $targetKey = $_GET['player_key'] ?? '';

    // Get all players (or just one)
    if ($targetKey) {
        $players = dbGetAll("SELECT id, player_key FROM players WHERE player_key = ?", [$targetKey]);
    } else {
        $players = dbGetAll("SELECT id, player_key FROM players", []);
    }

    $conn = getDBConnection();
    $updated = 0;

    foreach ($players as $player) {
        $pk = $player['player_key'];
        $pid = (int)$player['id'];

        // Find all matches where this player was p1 or p2 (team 1)
        $t1Matches = dbGetAll(
            "SELECT s1, s2 FROM matches WHERE p1_key = ? OR p2_key = ?",
            [$pk, $pk]
        );

        // Find all matches where this player was p3 or p4 (team 2)
        $t2Matches = dbGetAll(
            "SELECT s1, s2 FROM matches WHERE p3_key = ? OR p4_key = ?",
            [$pk, $pk]
        );

        $wins = 0;
        $losses = 0;
        $diff = 0;

        // Team 1 matches: player's score = s1, opponent's = s2
        foreach ($t1Matches as $m) {
            $s1 = (int)$m['s1'];
            $s2 = (int)$m['s2'];
            if ($s1 > $s2) $wins++;
            elseif ($s2 > $s1) $losses++;
            $diff += ($s1 - $s2);
        }

        // Team 2 matches: player's score = s2, opponent's = s1
        foreach ($t2Matches as $m) {
            $s1 = (int)$m['s1'];
            $s2 = (int)$m['s2'];
            if ($s2 > $s1) $wins++;
            elseif ($s1 > $s2) $losses++;
            $diff += ($s2 - $s1);
        }

        $totalGames = $wins + $losses;
        $winPct = $totalGames > 0 ? round(($wins / $totalGames) * 100, 2) : 0.00;

        // Update player record
        $stmt = $conn->prepare("UPDATE players SET wins = ?, losses = ?, diff = ?, win_pct = ? WHERE id = ?");
        $stmt->bind_param('iiidi', $wins, $losses, $diff, $winPct, $pid);
        $stmt->execute();
        $stmt->close();
        $updated++;
    }

    $conn->close();

    echo json_encode([
        'status' => 'success',
        'players_updated' => $updated,
        'message' => "Recalculated stats for $updated players"
    ]);

} catch (Exception $e) {
    error_log("STATS ERROR: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
