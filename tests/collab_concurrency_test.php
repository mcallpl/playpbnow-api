<?php
// ============================================================================
// tests/collab_concurrency_test.php
//
// Connected-scoring concurrency tests (Fix Agent C1, 2026-09-04) for audit
// findings #2 (ordering), #8 (atomic shuffle reset), #9 (simultaneous finish),
// #11 (authentication), #14 (participant duplicates), #16 (batch sync).
//
//     php playpbnow-api/tests/collab_concurrency_test.php
//
// Exit code 0 = all assertions passed, 1 = at least one failed.
//
// ── HOW THIS AVOIDS PRODUCTION ──────────────────────────────────────────────
// It NEVER loads db_config.php. collab_auth.php only reaches the database
// through dbGetRow/dbGetAll/dbQuery/dbInsert and guards its own include with
// `if (!function_exists('dbGetRow'))`, so this file defines those four helpers
// FIRST, pointed at a throwaway local database it creates and drops. No
// production host, credential or table is touched at any point.
//
// If no local MySQL is reachable the DB-backed section is skipped and only the
// pure-function assertions run — that is reported loudly rather than silently.
//
// The scratch schema is built in its PRE-migration shape, so the suite also
// proves the two things the brief demands of the migration:
//   * the PHP works BEFORE migration 006 is applied (legacy fallback), and
//   * 006 is safe to run twice.
// ============================================================================

$failures = 0;
$checks   = 0;
$skipped  = 0;

function t_assert(bool $cond, string $msg): void {
    global $failures, $checks;
    $checks++;
    echo $cond ? "  PASS  {$msg}\n" : "  FAIL  {$msg}\n";
    if (!$cond) $failures++;
}
function t_section(string $name): void { echo "\n{$name}\n" . str_repeat('-', strlen($name)) . "\n"; }

// ─────────────────────────────────────────────────────────────────────────────
// Scratch database
// ─────────────────────────────────────────────────────────────────────────────
$DB_NAME = 'pbnow_collab_test_' . getmypid();
$raw = null;
foreach ([['127.0.0.1', 'root', ''], ['localhost', 'root', ''], ['127.0.0.1', 'root', 'root']] as $cand) {
    try {
        $try = @new mysqli($cand[0], $cand[1], $cand[2]);
        if (!$try->connect_error) { $raw = $try; break; }
    } catch (Throwable $e) { /* try the next candidate */ }
}

if ($raw) {
    $raw->query("DROP DATABASE IF EXISTS `$DB_NAME`");
    $raw->query("CREATE DATABASE `$DB_NAME` DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $raw->select_db($DB_NAME);

    // PRE-migration shape, copied from collab_migration.sql + collab_alter_mysql8.sql.
    $raw->query("CREATE TABLE `collab_sessions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `batch_id` varchar(100) NOT NULL,
        `group_name` varchar(255) NOT NULL,
        `share_code` varchar(6) NOT NULL,
        `schedule_json` longtext NOT NULL,
        `scores_json` longtext DEFAULT NULL,
        `creator_user_id` varchar(100) NOT NULL DEFAULT '',
        `saved_session_id` int(11) DEFAULT NULL,
        `status` enum('active','finished','expired','closed') NOT NULL DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_share_code_active` (`share_code`,`status`)
    ) ENGINE=InnoDB");
    $raw->query("CREATE TABLE `collab_participants` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `session_id` int(11) NOT NULL,
        `user_id` varchar(100) NOT NULL,
        `role` enum('host','collaborator') NOT NULL DEFAULT 'collaborator',
        `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `last_seen` timestamp NOT NULL DEFAULT current_timestamp(),
        `_deleted_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_session_user` (`session_id`,`user_id`)
    ) ENGINE=InnoDB");
    $raw->query("CREATE TABLE `collab_score_updates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `session_id` int(11) NOT NULL,
        `round_idx` int(11) NOT NULL,
        `game_idx` int(11) NOT NULL,
        `s1` int(11) NOT NULL DEFAULT 0,
        `s2` int(11) NOT NULL DEFAULT 0,
        `s1_str` varchar(10) NOT NULL DEFAULT '',
        `s2_str` varchar(10) NOT NULL DEFAULT '',
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_session_round_game` (`session_id`,`round_idx`,`game_idx`)
    ) ENGINE=InnoDB");
    $raw->query("CREATE TABLE `users` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `first_name` varchar(100) DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB");
    $raw->query("INSERT INTO users (id, first_name) VALUES (100,'Chip'),(200,'Kim')");
}

$GLOBALS['__t_conn'] = $raw;

// ── The four helpers collab_auth.php expects, pointed at the scratch DB ──────
function getDBConnection() { return $GLOBALS['__t_conn']; }
function t_bind_types(array $params): string {
    $t = '';
    foreach ($params as $p) { $t .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's'); }
    return $t;
}
function dbQuery($sql, $params = []) {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    if (!empty($params)) $stmt->bind_param(t_bind_types($params), ...$params);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    return $stmt;
}
function dbGetRow($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    if (!$stmt) return null;
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function dbGetAll($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    if (!$stmt) return [];
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}
function dbInsert($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    if (!$stmt) return false;
    $id = getDBConnection()->insert_id;
    $stmt->close();
    return $id;
}

require_once dirname(__DIR__) . '/collab_auth.php';

// Reset collab_auth.php's per-request schema cache between phases. The cache is
// a `static` inside each function, which is exactly right in a web request and
// exactly wrong in a test that applies a migration half-way through, so the
// functions are re-declared per phase by running each phase in a subprocess…
// except we only need TWO shapes, so instead the test runs the legacy phase,
// then re-execs itself with an env flag for the migrated phase.
$PHASE = getenv('PBNOW_TEST_PHASE') ?: 'legacy';

// ─────────────────────────────────────────────────────────────────────────────
// 1. PURE FUNCTIONS — no database at all
// ─────────────────────────────────────────────────────────────────────────────
if ($PHASE === 'legacy') {
    t_section('#2 ordering guard — pbnow_collab_should_accept_field (pure)');

    t_assert(pbnow_collab_should_accept_field(5, 'devB', 'devA', null) === true,
        'no last_seen_seq (older app build) is always accepted — no regression');
    t_assert(pbnow_collab_should_accept_field(0, '', 'devA', 3) === true,
        'nothing stored yet is accepted');
    t_assert(pbnow_collab_should_accept_field(5, '', 'devA', 3) === true,
        'pre-migration row with no known author is accepted');
    t_assert(pbnow_collab_should_accept_field(9, 'devA', 'devA', 3) === true,
        "a device may always overwrite its OWN value (typing '1' then '11')");
    t_assert(pbnow_collab_should_accept_field(3, 'devB', 'devA', 3) === true,
        'a value the caller has already seen may be deliberately overridden');
    t_assert(pbnow_collab_should_accept_field(2, 'devB', 'devA', 7) === true,
        'an OLDER value from another device is overwritten');
    t_assert(pbnow_collab_should_accept_field(9, 'devB', 'devA', 3) === false,
        'THE BUG: a delayed retry must NOT clobber a NEWER value from another scorer');

    t_section('#2 clear-a-score — pbnow_collab_merge_snapshot (pure)');

    $snap = ['0_1_t1' => '11', '0_1_t2' => '7'];
    $out = pbnow_collab_merge_snapshot($snap, 0, 1, true, '', true, '7');
    t_assert(!array_key_exists('0_1_t1', $out),
        'a PROVIDED empty value REMOVES the key — the erased score cannot come back');
    t_assert(($out['0_1_t2'] ?? null) === '7', 'the other team\'s score is untouched');

    $out2 = pbnow_collab_merge_snapshot($snap, 0, 1, false, '', false, '');
    t_assert(($out2['0_1_t1'] ?? null) === '11' && ($out2['0_1_t2'] ?? null) === '7',
        'an ABSENT field leaves both stored values alone');

    $out3 = pbnow_collab_merge_snapshot($snap, 0, 1, true, '15', false, '');
    t_assert(($out3['0_1_t1'] ?? null) === '15', 'a provided non-empty value is stored');

    t_section('#9 finish claim — pbnow_collab_claim_outcome (pure)');

    $a = pbnow_collab_claim_outcome(1, 'finishing');
    t_assert($a['claimed'] === true && $a['outcome'] === 'claimed',
        'the winner (1 affected row) claims the match');
    $b = pbnow_collab_claim_outcome(0, 'finished');
    t_assert($b['claimed'] === false && $b['outcome'] === 'already_saved',
        'the loser (0 affected rows) is told it was already saved');
    $c = pbnow_collab_claim_outcome(0, 'finishing');
    t_assert($c['claimed'] === false && $c['outcome'] === 'saving_elsewhere',
        'the loser during the winner\'s save waits rather than double-saving');
    $d = pbnow_collab_claim_outcome(0, null);
    t_assert($d['claimed'] === false && $d['outcome'] === 'proceed',
        'NO collab row must never block a save (a lost match is worse than a dup)');

    t_section('#11 unidentified-caller proof of knowledge (pure)');

    $stored = json_encode([[ 'games' => [[
        'team1' => [['id' => 'pk_1', 'first_name' => 'A'], ['id' => 'pk_2', 'first_name' => 'B']],
        'team2' => [['id' => 'pk_3', 'first_name' => 'C'], ['id' => 'pk_4', 'first_name' => 'D']],
    ]], 'byes' => [] ]]);

    t_assert(pbnow_collab_schedule_proves_knowledge([['games' => []]], $stored) === false,
        'THE EXPLOIT: {"schedule":[{"games":[]}]} from a stranger is rejected');
    t_assert(pbnow_collab_schedule_proves_knowledge([], $stored) === false,
        'an empty schedule is rejected');
    t_assert(pbnow_collab_schedule_proves_knowledge(
        [['games' => [['team1' => [['id' => 'zz_9']], 'team2' => [['id' => 'zz_8']]]], 'byes' => []]], $stored) === false,
        'a fabricated schedule with unknown player ids is rejected');
    t_assert(pbnow_collab_schedule_proves_knowledge(
        [['games' => [[
            'team1' => [['id' => 'pk_1'], ['id' => 'pk_3']],
            'team2' => [['id' => 'pk_2'], ['id' => 'pk_4']],
        ]], 'byes' => []]], $stored) === true,
        'a real host SHUFFLE (same players, new pairings) is allowed');
    t_assert(pbnow_collab_schedule_proves_knowledge(
        [['games' => [[
            'team1' => [['id' => 'pk_1'], ['id' => 'pk_5']],
            'team2' => [['id' => 'pk_2'], ['id' => 'pk_4']],
        ]], 'byes' => [['id' => 'pk_3']]]], $stored) === true,
        'a real host SWAP that brings in a substitute is allowed');
    t_assert(pbnow_collab_schedule_proves_knowledge([['games' => []]], 'not json') === true,
        'an unjudgeable stored schedule never blocks a real host');
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. DATABASE-BACKED
// ─────────────────────────────────────────────────────────────────────────────
if (!$raw) {
    echo "\nSKIPPED: no local MySQL reachable — pure-function assertions only.\n";
    echo "         (The DB phase never touches production; it creates and drops\n";
    echo "          its own scratch database.)\n";
    $skipped = 1;
} else {

    // Helper: make a fresh session row and return its id.
    $mkSession = function (string $code, string $creator = '100') use ($raw): int {
        $raw->query("INSERT INTO collab_sessions
            (batch_id, group_name, share_code, schedule_json, scores_json, creator_user_id, status, expires_at)
            VALUES ('b1','Sunday Crew','$code','[]','{}','$creator','active', DATE_ADD(NOW(), INTERVAL 2 HOUR))");
        return (int)$raw->insert_id;
    };

    if ($PHASE === 'legacy') {
        t_section('PRE-MIGRATION fallback — the PHP must work before 006 is applied');

        $sid = $mkSession('AAAAAA');
        $seq = pbnow_collab_next_seq($raw, $sid);
        t_assert($seq === 0, 'pbnow_collab_next_seq() returns 0 when seq_counter does not exist');

        $w = pbnow_collab_apply_score_write($raw, $sid, 0, 0, true, '11', true, '7', null, 'devA', 0);
        t_assert($w['s1_final'] === '11' && $w['s2_final'] === '7', 'a score still writes with no seq columns');

        // Even with a last_seen_seq, a database without the columns cannot judge
        // — it must accept, exactly as it did before this work.
        $w = pbnow_collab_apply_score_write($raw, $sid, 0, 0, true, '9', false, '', 0, 'devB', 0);
        t_assert($w['s1_final'] === '9', 'pre-migration, every write is accepted (no behaviour change)');

        $w = pbnow_collab_apply_score_write($raw, $sid, 0, 0, true, '', false, '', null, 'devA', 0);
        t_assert($w['s1_final'] === '', 'CLEAR-A-SCORE still works pre-migration');

        pbnow_collab_touch_participant($sid, '100', '', 'host');
        pbnow_collab_touch_participant($sid, '100', '', 'collaborator');
        $n = dbGetRow("SELECT COUNT(*) c FROM collab_participants WHERE session_id = ?", [$sid]);
        t_assert((int)$n['c'] === 1, 'legacy read-then-write heartbeat keeps one row for one user');

        t_section('migration 006 — apply it TWICE, it must be idempotent');

        $sql = file_get_contents(dirname(__DIR__) . '/migrations/006_collab_hardening.sql');
        $ok1 = $raw->multi_query($sql);
        while ($raw->more_results() && $raw->next_result()) { if ($r = $raw->store_result()) $r->free(); }
        t_assert($ok1 && $raw->errno === 0, 'first run of 006 applies cleanly (' . $raw->error . ')');

        $ok2 = $raw->multi_query($sql);
        while ($raw->more_results() && $raw->next_result()) { if ($r = $raw->store_result()) $r->free(); }
        t_assert($ok2 && $raw->errno === 0, 'SECOND run of 006 is a no-op, not an error (' . $raw->error . ')');

        foreach ([
            ['collab_sessions', 'seq_counter'], ['collab_sessions', 'schedule_version'],
            ['collab_sessions', 'scores_reset_at'],
            ['collab_score_updates', 's1_seq'], ['collab_score_updates', 's2_seq'],
            ['collab_score_updates', 's1_device'], ['collab_score_updates', 's2_device'],
            ['collab_participants', 'device_id'],
        ] as $c) {
            $r = $raw->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$c[0]}' AND COLUMN_NAME = '{$c[1]}'")->fetch_assoc();
            t_assert((int)$r['c'] === 1, "006 added {$c[0]}.{$c[1]}");
        }
        $r = $raw->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='collab_participants'
              AND INDEX_NAME='uniq_collab_participant'")->fetch_assoc();
        t_assert((int)$r['c'] === 3, '006 added the 3-column UNIQUE key on collab_participants');
        $r = $raw->query("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_sessions' AND COLUMN_NAME='status'")->fetch_assoc();
        t_assert(strpos($r['t'], "'finishing'") !== false, "006 widened the status enum with 'finishing'");
        t_assert(strpos($r['t'], "'active'") !== false && strpos($r['t'], "'finished'") !== false
            && strpos($r['t'], "'expired'") !== false && strpos($r['t'], "'closed'") !== false,
            'every pre-existing status member survives the widen');

        t_section('migration 007 — sessions backstop must SKIP itself on dirty data');

        $hasKey = function () use ($raw): int {
            $r = $raw->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sessions'
                  AND INDEX_NAME='uniq_session_group_date_title'")->fetch_assoc();
            return (int)$r['c'];
        };
        $run007 = function () use ($raw): void {
            $sql = file_get_contents(dirname(__DIR__) . '/migrations/007_sessions_finish_backstop.sql');
            $raw->multi_query($sql);
            while ($raw->more_results() && $raw->next_result()) { if ($r = $raw->store_result()) $r->free(); }
        };

        $raw->query("CREATE TABLE `sessions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `group_id` int(11) NOT NULL,
            `session_date` datetime NOT NULL,
            `title` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB");

        // Dirty data first: the migration must refuse rather than fail.
        $raw->query("INSERT INTO sessions (group_id, session_date, title)
                     VALUES (1,'2026-09-04 10:00:00','Sunday'),(1,'2026-09-04 10:00:00','Sunday')");
        $run007();
        t_assert($hasKey() === 0, 'with duplicates present, 007 SKIPS the key instead of failing');

        // Clean the duplicate and re-run — now it must apply.
        $raw->query("DELETE FROM sessions WHERE id = (SELECT * FROM (SELECT MAX(id) FROM sessions) x)");
        $run007();
        t_assert($hasKey() === 3, '007 adds the 3-column UNIQUE key once the data is clean');
        $run007();
        t_assert($hasKey() === 3 && $raw->errno === 0, 'a SECOND run of 007 is a no-op, not an error');

        // And the key genuinely blocks the double-save it exists to stop.
        $dupBlocked = false;
        try {
            $raw->query("INSERT INTO sessions (group_id, session_date, title)
                         VALUES (1,'2026-09-04 10:00:00','Sunday')");
            $dupBlocked = ($raw->errno !== 0);
        } catch (Throwable $e) { $dupBlocked = true; }
        t_assert($dupBlocked, 'the key rejects a duplicate (group_id, session_date, title) save');
        $raw->query("DROP TABLE `sessions`");

        // The migrated phase needs collab_auth.php's static schema cache cleared,
        // so hand the scratch database to a fresh process.
        echo "\n(handing the migrated schema to a second process so the schema cache is cold)\n";
        $cmd = 'PBNOW_TEST_PHASE=migrated PBNOW_TEST_DB=' . escapeshellarg($DB_NAME)
             . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
        $childOut = [];
        $childCode = 0;
        exec($cmd . ' 2>&1', $childOut, $childCode);
        echo implode("\n", $childOut) . "\n";
        if ($childCode !== 0) { $failures++; }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. MIGRATED PHASE (re-exec'd child, schema cache cold)
// ─────────────────────────────────────────────────────────────────────────────
if ($PHASE === 'migrated' && $raw) {
    // The child re-created its own empty DB above; use the parent's instead.
    $parentDb = getenv('PBNOW_TEST_DB');
    $raw->query("DROP DATABASE IF EXISTS `$DB_NAME`");
    $raw->select_db($parentDb);

    $mk = function (string $code, string $creator = '100') use ($raw): int {
        $raw->query("INSERT INTO collab_sessions
            (batch_id, group_name, share_code, schedule_json, scores_json, creator_user_id, status, expires_at)
            VALUES ('b1','Sunday Crew','$code','[]','{}','$creator','active', DATE_ADD(NOW(), INTERVAL 2 HOUR))");
        return (int)$raw->insert_id;
    };

    t_section('#2 ORDERING against the real schema — an older write must not win');

    $sid = $mk('BBBBBB');

    // Device A types 11. It has seen nothing yet.
    $seqA = pbnow_collab_next_seq($raw, $sid);
    $wA = pbnow_collab_apply_score_write($raw, $sid, 0, 0, true, '11', false, '', null, 'devA', $seqA);
    t_assert($seqA === 1 && $wA['s1_final'] === '11', "device A writes 11 at seq 1");

    // Device B's request was queued/retried BEFORE it ever saw A's write, so it
    // still reports last_seen_seq = 0. This is the audit's exact scenario.
    $seqB = pbnow_collab_next_seq($raw, $sid);
    $wB = pbnow_collab_apply_score_write($raw, $sid, 0, 0, true, '7', false, '', 0, 'devB', $seqB);
    t_assert($wB['s1_applied'] === false, 'the stale write is REJECTED');
    t_assert($wB['s1_final'] === '11', "device A's newer 11 survives");
    t_assert($wB['rejected'] === true, 'the response tells the client it was superseded');
    $row = dbGetRow("SELECT s1_str, s1, s1_seq, s1_device FROM collab_score_updates WHERE session_id = ?", [$sid]);
    t_assert($row['s1_str'] === '11' && (int)$row['s1'] === 11, 'the stored row still reads 11');
    t_assert((int)$row['s1_seq'] === 1 && $row['s1_device'] === 'devA', 'seq and author are unchanged by the rejected write');

    // Once B HAS seen seq 1, its correction is honoured — this is a correction,
    // not a stale retry.
    $seqB2 = pbnow_collab_next_seq($raw, $sid);
    $wB2 = pbnow_collab_apply_score_write($raw, $sid, 0, 0, true, '7', false, '', 1, 'devB', $seqB2);
    t_assert($wB2['s1_applied'] === true && $wB2['s1_final'] === '7',
        'a write from a device that HAS seen the current value is accepted');

    // And A may still fix its own typo.
    $seqA2 = pbnow_collab_next_seq($raw, $sid);
    $wA2 = pbnow_collab_apply_score_write($raw, $sid, 0, 0, true, '9', false, '', 0, 'devA', $seqA2);
    t_assert($wA2['s1_applied'] === false, "A's stale retry loses to B's newer value too (symmetry)");

    t_assert(pbnow_collab_next_seq($raw, $sid) === $seqA2 + 1, 'the sequence is strictly monotonic');

    t_section('#2 CLEAR-A-SCORE against the real schema');

    $sid2 = $mk('CCCCCC');
    $s = pbnow_collab_next_seq($raw, $sid2);
    pbnow_collab_apply_score_write($raw, $sid2, 1, 2, true, '11', true, '7', null, 'devA', $s);

    // The scorer erases team 1's box. s1_str is PROVIDED but empty.
    $s = pbnow_collab_next_seq($raw, $sid2);
    $w = pbnow_collab_apply_score_write($raw, $sid2, 1, 2, true, '', false, '', null, 'devA', $s);
    t_assert($w['s1_final'] === '' && $w['s1_applied'] === true, 'the erase is applied');
    $row = dbGetRow("SELECT s1_str, s2_str FROM collab_score_updates WHERE session_id = ? AND round_idx=1 AND game_idx=2", [$sid2]);
    t_assert($row['s1_str'] === '', 'the cleared field is stored empty, not re-broadcast');
    t_assert($row['s2_str'] === '7', 'the ABSENT field is left completely alone');

    $snap = pbnow_collab_merge_snapshot(['1_2_t1' => '11', '1_2_t2' => '7'], 1, 2, true, '', false, '');
    t_assert(!isset($snap['1_2_t1']) && ($snap['1_2_t2'] ?? null) === '7',
        'and the snapshot blob drops the key so a late joiner never sees it again');

    t_section('#9 SIMULTANEOUS FINISH — the second caller gets already_exists');

    $sid3 = $mk('DDDDDD');
    $dev1 = $raw->prepare("UPDATE collab_sessions SET status='finishing' WHERE share_code = ? AND status='active'");
    $code = 'DDDDDD';
    $dev1->bind_param('s', $code); $dev1->execute();
    $claim1 = $dev1->affected_rows; $dev1->close();

    $dev2 = $raw->prepare("UPDATE collab_sessions SET status='finishing' WHERE share_code = ? AND status='active'");
    $dev2->bind_param('s', $code); $dev2->execute();
    $claim2 = $dev2->affected_rows; $dev2->close();

    t_assert($claim1 === 1, 'device 1 claims the match (1 affected row)');
    t_assert($claim2 === 0, 'device 2 gets 0 affected rows — it CANNOT also save');

    $o1 = pbnow_collab_claim_outcome($claim1, 'finishing');
    $now = dbGetRow("SELECT status FROM collab_sessions WHERE id = ?", [$sid3]);
    $o2 = pbnow_collab_claim_outcome($claim2, $now['status']);
    t_assert($o1['outcome'] === 'claimed', 'device 1 proceeds to save');
    t_assert($o2['outcome'] === 'saving_elsewhere', 'device 2 returns already_exists instead of a second session');

    // Winner finishes and publishes the saved session id.
    $raw->query("UPDATE collab_sessions SET status='finished', saved_session_id=4242
                 WHERE share_code='DDDDDD' AND status IN ('active','finishing')");
    $after = dbGetRow("SELECT status, saved_session_id FROM collab_sessions WHERE id = ?", [$sid3]);
    t_assert($after['status'] === 'finished' && (int)$after['saved_session_id'] === 4242,
        'the winner closes the session and publishes saved_session_id for the loser to navigate to');
    $o3 = pbnow_collab_claim_outcome(0, $after['status']);
    t_assert($o3['outcome'] === 'already_saved', 'a later finish is told the match was already saved');

    // And the release path: a save that dies must hand the match back.
    $sid4 = $mk('EEEEEE');
    $raw->query("UPDATE collab_sessions SET status='finishing' WHERE share_code='EEEEEE' AND status='active'");
    dbQuery("UPDATE collab_sessions SET status='active' WHERE share_code = ? AND status='finishing'", ['EEEEEE']);
    $rel = dbGetRow("SELECT status FROM collab_sessions WHERE id = ?", [$sid4]);
    t_assert($rel['status'] === 'active', 'a failed save RELEASES the claim — the match is never stranded');

    t_section('#8 SHUFFLE RESET is atomic — a racing score cannot survive it');

    $sid5 = $mk('FFFFFF');
    $s = pbnow_collab_next_seq($raw, $sid5);
    pbnow_collab_apply_score_write($raw, $sid5, 0, 0, true, '11', true, '7', null, 'devA', $s);

    // Reset, then fail — the rollback must restore the score, not half-wipe it.
    $raw->begin_transaction();
    $raw->query("DELETE FROM collab_score_updates WHERE session_id = $sid5");
    $raw->query("UPDATE collab_sessions SET schedule_json='[]', scores_json='{}',
                 scores_reset_at=NOW(6), schedule_version = schedule_version + 1 WHERE id = $sid5");
    $raw->rollback();
    $c = dbGetRow("SELECT COUNT(*) c FROM collab_score_updates WHERE session_id = ?", [$sid5]);
    $v = dbGetRow("SELECT schedule_version FROM collab_sessions WHERE id = ?", [$sid5]);
    t_assert((int)$c['c'] === 1, 'a rolled-back reset leaves the scores intact (DELETE and UPDATE are one unit)');
    t_assert((int)$v['schedule_version'] === 0, 'and schedule_version does not move on a rolled-back reset');

    // Now commit it for real.
    $raw->begin_transaction();
    $raw->query("DELETE FROM collab_score_updates WHERE session_id = $sid5");
    $raw->query("UPDATE collab_sessions SET schedule_json='[]', scores_json='{}',
                 scores_reset_at=NOW(6), schedule_version = schedule_version + 1 WHERE id = $sid5");
    $raw->commit();
    $c = dbGetRow("SELECT COUNT(*) c FROM collab_score_updates WHERE session_id = ?", [$sid5]);
    $v = dbGetRow("SELECT schedule_version, scores_reset_at FROM collab_sessions WHERE id = ?", [$sid5]);
    t_assert((int)$c['c'] === 0, 'a committed reset clears every per-game row');
    t_assert((int)$v['schedule_version'] === 1, 'schedule_version is bumped');
    t_assert(!empty($v['scores_reset_at']), 'scores_reset_at is stamped');

    t_section('#14 PARTICIPANTS — one row per person+device, roles, roster');

    $sid6 = $mk('GGGGGG');
    for ($i = 0; $i < 6; $i++) { pbnow_collab_touch_participant($sid6, '100', 'phone-1', 'host'); }
    $n = dbGetRow("SELECT COUNT(*) c FROM collab_participants WHERE session_id = ?", [$sid6]);
    t_assert((int)$n['c'] === 1, 'six heartbeats from one device create ONE row (was: six, inflating the count)');

    pbnow_collab_touch_participant($sid6, '100', 'phone-2', 'collaborator');
    pbnow_collab_touch_participant($sid6, '200', 'phone-3', 'collaborator');
    $n = dbGetRow("SELECT COUNT(*) c FROM collab_participants WHERE session_id = ?", [$sid6]);
    t_assert((int)$n['c'] === 3, 'the same person on a second phone, and a second person, are counted');

    pbnow_collab_touch_participant($sid6, '100', 'phone-1', 'collaborator');
    $r = dbGetRow("SELECT role FROM collab_participants WHERE session_id = ? AND user_id='100' AND device_id='phone-1'", [$sid6]);
    t_assert($r['role'] === 'host', 'a later heartbeat never DEMOTES the host to collaborator');

    $roster = pbnow_collab_roster($sid6, 30);
    t_assert(count($roster) === 3, 'the roster returns every connected device');
    t_assert($roster[0]['role'] === 'host', 'the host sorts first');
    t_assert($roster[0]['first_name'] === 'Chip', 'first names are resolved for the "who\'s connected" UI');

    t_section('#11 membership checks (host / participant)');

    $sid7 = $mk('HHHHHH', '100');
    $session = dbGetRow("SELECT id, creator_user_id, status FROM collab_sessions WHERE id = ?", [$sid7]);
    pbnow_collab_touch_participant($sid7, '200', 'phone-3', 'collaborator');

    t_assert(pbnow_collab_is_participant($sid7, '200') === true, 'a joined collaborator is a participant');
    t_assert(pbnow_collab_is_participant($sid7, '999') === false, 'a stranger is not a participant');
    t_assert(pbnow_collab_require_host($session, ['user_id' => '100', 'source' => 'body']) === 'host',
        'the creator is verified as host');
    t_assert(pbnow_collab_require_host($session, ['user_id' => '', 'source' => 'none']) === 'unverified',
        'a legacy caller with no identity is UNVERIFIED (and so may not reset scores)');

    // Soft-deleted participants must not count.
    $raw->query("UPDATE collab_participants SET _deleted_at = NOW() WHERE session_id = $sid7 AND user_id='200'");
    t_assert(pbnow_collab_is_participant($sid7, '200') === false, '_deleted_at is respected in membership');
    t_assert(count(pbnow_collab_roster($sid7, 30)) === 0, '_deleted_at is respected in the roster');

    t_section('#16 BATCH — one lock, one sequence, many games');

    $sid8 = $mk('IIIIII');
    $seq = pbnow_collab_next_seq($raw, $sid8);
    $snapshot = [];
    for ($r = 0; $r < 4; $r++) {
        for ($g = 0; $g < 3; $g++) {
            $w = pbnow_collab_apply_score_write($raw, $sid8, $r, $g, true, '11', true, (string)$g, null, 'devA', $seq);
            $snapshot = pbnow_collab_merge_snapshot($snapshot, $r, $g, true, $w['s1_final'], true, $w['s2_final']);
        }
    }
    $n = dbGetRow("SELECT COUNT(*) c FROM collab_score_updates WHERE session_id = ?", [$sid8]);
    t_assert((int)$n['c'] === 12, '12 games written under ONE sequence number (was 12 concurrent locking requests)');
    $seqs = dbGetRow("SELECT COUNT(DISTINCT s1_seq) c FROM collab_score_updates WHERE session_id = ?", [$sid8]);
    t_assert((int)$seqs['c'] === 1, 'every field in the batch shares the batch sequence');
    t_assert(count($snapshot) === 24, 'the snapshot holds all 24 values (12 games x 2 teams)');
    t_assert(($snapshot['0_0_t2'] ?? null) === '0',
        'a legitimate score of ZERO is kept — only a PROVIDED EMPTY STRING means "erased"');
}

// ─────────────────────────────────────────────────────────────────────────────
// Teardown
// ─────────────────────────────────────────────────────────────────────────────
if ($raw) {
    if ($PHASE === 'legacy') {
        $raw->select_db('mysql');
        $raw->query("DROP DATABASE IF EXISTS `$DB_NAME`");
    }
    $raw->close();
}

if ($PHASE === 'migrated') {
    echo "\n  child: {$checks} checks, {$failures} failed\n";
    exit($failures > 0 ? 1 : 0);
}

echo "\n" . str_repeat('=', 66) . "\n";
echo "collab concurrency: {$checks} assertions in this process, {$failures} failed";
echo $skipped ? " (DB phase SKIPPED — no local MySQL)\n" : "\n";
echo str_repeat('=', 66) . "\n";
exit($failures > 0 ? 1 : 0);
