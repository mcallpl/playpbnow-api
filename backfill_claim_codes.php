<?php
/**
 * backfill_claim_codes.php — one-time: give every pre-Phase-3 identity a
 * claim_code. CLI-only (run via SSH), so it can't be triggered over the web.
 *
 *   php backfill_claim_codes.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/identity.php';

$rows = dbGetAll("SELECT id FROM player_identities WHERE claim_code IS NULL OR claim_code = ''");
$n = 0;
foreach ($rows as $r) {
    $code = pbnow_unique_claim_code();
    dbQuery("UPDATE player_identities SET claim_code = ? WHERE id = ?", [$code, (int)$r['id']]);
    echo "identity {$r['id']} -> {$code}\n";
    $n++;
}
echo "Backfilled {$n} identity claim code(s).\n";
