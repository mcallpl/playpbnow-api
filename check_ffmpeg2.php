<?php
header('Content-Type: application/json');
$ffmpeg = __DIR__ . '/bin/ffmpeg';
$output = shell_exec("$ffmpeg -version 2>&1 | head -1");
echo json_encode([
    'path' => $ffmpeg,
    'exists' => file_exists($ffmpeg),
    'executable' => is_executable($ffmpeg),
    'version' => trim($output ?? ''),
]);
