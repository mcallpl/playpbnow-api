<?php
// api/test_data.php
// DIAGNOSTIC TOOL - DOES NOT CHANGE DATA

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$response = [];

// 1. CHECK CURRENT FOLDER (API)
$path = __DIR__ . '/pickleball_data.json';
$response['checking_path'] = $path;

if (file_exists($path)) {
    $response['status'] = 'FILE FOUND ✅';
    
    // Check Permissions
    $perms = substr(sprintf('%o', fileperms($path)), -4);
    $response['permissions'] = $perms;
    $response['is_writable'] = is_writable($path) ? 'YES ✅' : 'NO ❌ (Fix Permissions!)';
    
    // Check Content
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    
    if (json_last_error() === JSON_ERROR_NONE) {
        $response['json_valid'] = 'YES ✅';
        $response['groups_found'] = array_keys($data);
        
        // Show recent timestamps to verify freshness
        $response['sample_data'] = [];
        foreach ($data as $group => $info) {
            $count = count($info['history'] ?? []);
            $last_update = 'Never';
            if ($count > 0) {
                $last = end($info['history']);
                $last_update = date('Y-m-d H:i:s', $last['timestamp']);
            }
            $response['sample_data'][$group] = "$count matches recorded. Last update: $last_update";
        }
    } else {
        $response['json_valid'] = 'NO ❌ (File is corrupt)';
        $response['json_error'] = json_last_error_msg();
    }

} else {
    $response['status'] = 'FILE NOT FOUND ❌';
    $response['location'] = 'It is NOT in the api folder.';
    
    // List what IS in the folder to help debug
    $files = scandir(__DIR__);
    $response['files_in_current_folder'] = array_values(array_diff($files, ['.', '..']));
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>