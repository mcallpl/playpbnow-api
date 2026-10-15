<?php
// ============================================================
// tests/schedule_balance_test.php
//
// CLI-only balance test for generate_schedule.php (UAT 2026-09-04, Audit C
// H1/H2/M3). Run:
//
//     php playpbnow-api/tests/schedule_balance_test.php
//
// Exit code 0 = all assertions passed, 1 = at least one failed.
//
// For each roster size (4=2M/2F, 5=3M/2F, 6=4M/2F, 7, 8, 9, 12, 16) and each
// round type (mixer / mixed / gender) with 6 rounds, asserts:
//   1. count(schedule) == 6                       (no more empty schedules)
//   2. every player appears at most once per round (games + byes)
//   3. max(sit) - min(sit) <= 1                   (fair sitting)
// plus the `courts` cap: games per round == min(floor(n/4), courts).
// ============================================================

define('PBNOW_SCHEDULE_TEST', true);
require_once dirname(__DIR__) . '/generate_schedule.php';

$failures = 0;
$checks   = 0;

function t_assert(bool $cond, string $msg): void {
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  PASS  {$msg}\n";
    } else {
        $failures++;
        echo "  FAIL  {$msg}\n";
    }
}

function makeRoster(int $n, ?int $males = null): array {
    // Explicit splits for the audit cases; otherwise alternate M/F.
    if ($males === null) $males = intdiv($n + 1, 2);
    $P = [];
    for ($i = 1; $i <= $n; $i++) {
        $id = "pk_{$i}";
        $P[$id] = [
            'id'         => $id,
            'first_name' => "P{$i}",
            'gender'     => ($i <= $males) ? 'male' : 'female',
        ];
    }
    return $P;
}

function checkSchedule(array $P, array $schedule, int $rounds, string $label, ?int $expectedGames = null): void {
    $ids = array_keys($P);
    $n   = count($ids);

    t_assert(count($schedule) === $rounds, "{$label}: count(schedule) == {$rounds} (got " . count($schedule) . ")");

    $sit = array_fill_keys($ids, 0);
    $onceOk = true;
    $gamesOk = true;
    foreach ($schedule as $rIdx => $round) {
        $seen = [];
        foreach ($round['games'] as $g) {
            foreach (['team1', 'team2'] as $team) {
                foreach ($g[$team] as $p) {
                    $seen[$p['id']] = ($seen[$p['id']] ?? 0) + 1;
                }
            }
        }
        foreach ($round['byes'] as $b) {
            $seen[$b['id']] = ($seen[$b['id']] ?? 0) + 1;
            $sit[$b['id']]++;
        }
        foreach ($seen as $pid => $cnt) {
            if ($cnt > 1) { $onceOk = false; }
        }
        // Everyone accounted for exactly once (playing or sitting).
        if (count($seen) !== $n) { $onceOk = false; }
        if ($expectedGames !== null && count($round['games']) !== $expectedGames) { $gamesOk = false; }
    }
    t_assert($onceOk, "{$label}: every player appears at most once per round");
    if ($expectedGames !== null) {
        t_assert($gamesOk, "{$label}: {$expectedGames} game(s) per round");
    }
    if (!empty($sit)) {
        $spread = max($sit) - min($sit);
        t_assert($spread <= 1, "{$label}: max(sit)-min(sit) <= 1 (spread {$spread}, sits " . json_encode(array_values($sit)) . ")");
    }
}

$rounds = 6;
$cases = [
    // [n, males]
    [4, 2], [5, 3], [6, 4], [7, null], [8, null], [9, null], [12, null], [16, null],
];
$modes = ['mixer', 'mixed', 'gender'];

echo "schedule_balance_test — {$rounds} rounds\n";
$t0 = microtime(true);

foreach ($cases as [$n, $males]) {
    $P = makeRoster($n, $males);
    $m = $males ?? intdiv($n + 1, 2);
    $f = $n - $m;
    foreach ($modes as $mode) {
        $label = "{$n} players ({$m}M/{$f}F) {$mode}";
        echo "\n{$label}\n";
        $cfgs  = array_fill(0, $rounds, ['type' => $mode]);
        $built = pbnow_build_schedule($P, $cfgs, 0);
        t_assert($built['error'] === null, "{$label}: no error (" . ($built['error'] ?? 'ok') . ")");
        checkSchedule($P, $built['schedule'], $rounds, $label, pbnow_schedule_games_per_round($n, 0));
    }
}

// ── courts cap ──
echo "\ncourts cap\n";
$P8 = makeRoster(8);
$cfgs = array_fill(0, $rounds, ['type' => 'mixer']);
$built = pbnow_build_schedule($P8, $cfgs, 1);
t_assert($built['error'] === null, "8 players, courts=1: no error");
checkSchedule($P8, $built['schedule'], $rounds, "8 players courts=1", 1);

$P12 = makeRoster(12);
$built = pbnow_build_schedule($P12, $cfgs, 2);
t_assert($built['error'] === null, "12 players, courts=2: no error");
checkSchedule($P12, $built['schedule'], $rounds, "12 players courts=2", 2);

t_assert(pbnow_schedule_games_per_round(16, 0) === 4, "games_per_round(16, 0) == 4");
t_assert(pbnow_schedule_games_per_round(16, 3) === 3, "games_per_round(16, 3) == 3");
t_assert(pbnow_schedule_games_per_round(5, 9) === 1, "games_per_round(5, 9) == 1 (cannot exceed floor(n/4))");
t_assert(pbnow_schedule_games_per_round(3, 0) === 0, "games_per_round(3, 0) == 0");

// ── mixed-round integrity when courts limit who plays ──
// Regression for UAT 2026-09-04: the courts stepper let an organiser play fewer
// games than floor(players/4). The sitter chooser balanced sit COUNTS but
// ignored gender, so the playing set could be lopsided (e.g. 5 men, 3 women on
// 2 courts) and buildRound() silently fell back to same-gender teams inside a
// round labelled "MIXED DOUBLES". 16 players on 2 courts produced 8 such teams.
echo "\nmixed-round integrity with a courts cap\n";
foreach ([[16, 8, 2], [16, 8, 3], [12, 6, 2], [8, 4, 1]] as [$n, $males, $courts]) {
    $Pm = makeRoster($n, $males);
    $cfgsMixed = array_fill(0, $rounds, ['type' => 'mixed']);
    $b = pbnow_build_schedule($Pm, $cfgsMixed, $courts);
    $label = "{$n} players ({$males}M) on {$courts} courts, all-mixed";
    t_assert($b['error'] === null, "{$label}: no error (" . ($b['error'] ?? 'ok') . ")");

    $violations = 0;
    foreach (($b['schedule'] ?? []) as $rd) {
        foreach (($rd['games'] ?? []) as $g) {
            foreach (['team1', 'team2'] as $tk) {
                if (empty($g[$tk])) continue;
                $f = 0;
                foreach ($g[$tk] as $pl) {
                    if (strpos($Pm[$pl['id']]['gender'], 'f') === 0) $f++;
                }
                if ($f !== 1) $violations++;   // a mixed team is exactly 1 woman + 1 man
            }
        }
    }
    t_assert($violations === 0, "{$label}: every team is 1 man + 1 woman (violations {$violations})");
    checkSchedule($Pm, $b['schedule'], $rounds, $label, min(intdiv($n, 4), $courts));
}

// ── uneven groups: byes come first (Chip, 2026-10-15) ──
// "The byes outweigh the other rules because we don't want people sitting out
// unequally." With 18 men and 11 women on 4 courts, Mixed rounds used to sit
// 10 men and 3 women every round, so men sat about twice as often. Now
// everyone stays within one bye of everyone else, after EVERY round, and the
// leftover same-gender team plays a man+woman team: never two men vs two women.
echo "\nuneven groups: byes first, then as mixed as possible\n";
foreach ([[29, 18, 4, 8], [29, 11, 4, 8], [17, 12, 3, 6], [13, 9, 2, 6], [10, 7, 2, 6]] as [$n, $males, $courts, $rds]) {
    $Pu = makeRoster($n, $males);
    $bu = pbnow_build_schedule($Pu, array_fill(0, $rds, ['type' => 'mixed']), $courts);
    $label = "{$n} players ({$males}M) on {$courts} courts, {$rds} mixed rounds";
    t_assert($bu['error'] === null, "{$label}: no error (" . ($bu['error'] ?? 'ok') . ")");
    $sits = array_fill_keys(array_keys($Pu), 0);
    $worst = 0; $mmVsFf = 0; $sameVsSameAvoidable = 0;
    foreach (($bu['schedule'] ?? []) as $rd) {
        foreach ($rd['byes'] as $bp) $sits[$bp['id']]++;
        $worst = max($worst, max($sits) - min($sits));
        $mixedTeams = 0; $sameTeams = 0; $sameVsSame = 0;
        foreach ($rd['games'] as $g) {
            $kinds = [];
            foreach (['team1', 'team2'] as $tk) {
                $f = 0;
                foreach ($g[$tk] as $pl) if (strpos($Pu[$pl['id']]['gender'], 'f') === 0) $f++;
                $kinds[] = $f;
                if ($f === 1) $mixedTeams++; else $sameTeams++;
            }
            sort($kinds);
            if ($kinds === [0, 2]) $mmVsFf++;
            if ($kinds[0] !== 1 && $kinds[1] !== 1) $sameVsSame++;
        }
        // Two same-gender teams may only meet when there are more of them than mixed teams.
        if ($sameVsSame > 0 && $sameTeams <= $mixedTeams) $sameVsSameAvoidable++;
    }
    t_assert($worst <= 1, "{$label}: byes within 1 of each other after every round (worst {$worst})");
    t_assert($mmVsFf === 0, "{$label}: never two men vs two women ({$mmVsFf})");
    t_assert($sameVsSameAvoidable === 0, "{$label}: a same-gender team plays a man+woman team when one is free ({$sameVsSameAvoidable})");
}

// ── failure reporting ──
echo "\nfailure reporting\n";
$P3 = makeRoster(3);
$built = pbnow_build_schedule($P3, $cfgs, 0);
t_assert($built['error'] !== null && $built['schedule'] === [], "3 players: error + empty schedule (" . ($built['error'] ?? '') . ")");
$built = pbnow_build_schedule($P8, [], 0);
t_assert($built['error'] !== null && $built['schedule'] === [], "0 rounds: error + empty schedule (" . ($built['error'] ?? '') . ")");

$elapsed = round(microtime(true) - $t0, 2);
echo "\n{$checks} checks, {$failures} failed, {$elapsed}s\n";
exit($failures === 0 ? 0 : 1);
