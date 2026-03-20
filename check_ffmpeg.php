<?php
header('Content-Type: application/json');
$results = [];
$results['which'] = trim(shell_exec('which ffmpeg 2>&1') ?? '');
$results['version'] = trim(shell_exec('ffmpeg -version 2>&1 | head -1') ?? '');
$results['exec_enabled'] = function_exists('exec');
$results['shell_exec_enabled'] = function_exists('shell_exec');
$results['proc_open_enabled'] = function_exists('proc_open');
// Also check common paths
foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/cpanel/3rdparty/bin/ffmpeg'] as $p) {
    $results['exists_' . basename(dirname($p))] = file_exists($p) ? $p : false;
}
echo json_encode($results, JSON_PRETTY_PRINT);
