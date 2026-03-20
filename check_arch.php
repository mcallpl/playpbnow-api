<?php
header('Content-Type: application/json');
echo json_encode([
    'uname' => php_uname(),
    'arch' => php_uname('m'),
    'os' => php_uname('s'),
    'home' => getenv('HOME'),
    'tmp' => sys_get_temp_dir(),
    'disk_free' => round(disk_free_space('/') / 1024 / 1024) . ' MB',
    'upload_max' => ini_get('upload_max_filesize'),
    'post_max' => ini_get('post_max_size'),
    'max_exec' => ini_get('max_execution_time'),
], JSON_PRETTY_PRINT);
