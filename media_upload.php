<?php
/**
 * Media Upload — Upload images/videos for broadcasts
 * Resizes images to web-friendly dimensions, stores in uploads/ directory
 */

set_time_limit(120); // Allow up to 2 minutes for video compression
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

// Config
$UPLOAD_DIR = __DIR__ . '/uploads/';
$UPLOAD_URL = 'https://peoplestar.com/PlayPBNow/api/uploads/';
$MAX_IMAGE_WIDTH = 1200;  // Max width for web
$MAX_IMAGE_HEIGHT = 900;  // Max height for web
$MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB (for videos)
$ALLOWED_IMAGE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$ALLOWED_VIDEO = ['video/mp4', 'video/quicktime', 'video/webm'];

// Create uploads directory if needed
if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
}

// Clean up any orphaned temp files older than 5 minutes (from crashed uploads)
foreach (glob($UPLOAD_DIR . 'tmp_*') as $tmpFile) {
    if (filemtime($tmpFile) < time() - 300) {
        @unlink($tmpFile);
    }
}

// Require admin
$user_id = $_POST['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'user_id is required']); exit;
}
$userRow = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$user_id]);
if (!$userRow || !$userRow['is_admin']) {
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']); exit;
}

// Check file upload
if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['media']['error'] ?? 'No file';
    echo json_encode(['status' => 'error', 'message' => "Upload failed (error: $err)"]); exit;
}

$file = $_FILES['media'];
$mime = mime_content_type($file['tmp_name']);
$isImage = in_array($mime, $ALLOWED_IMAGE);
$isVideo = in_array($mime, $ALLOWED_VIDEO);

if (!$isImage && !$isVideo) {
    echo json_encode(['status' => 'error', 'message' => 'Unsupported file type. Use JPG, PNG, GIF, WebP, MP4, MOV, or WebM.']); exit;
}

if ($file['size'] > $MAX_FILE_SIZE) {
    echo json_encode(['status' => 'error', 'message' => 'File too large. Max 50MB.']); exit;
}

// Check if this exact file already exists (by content hash) — skip re-upload
$fileHash = md5_file($file['tmp_name']);
$hashFile = $UPLOAD_DIR . '.hashes.json';
$hashes = file_exists($hashFile) ? json_decode(file_get_contents($hashFile), true) : [];
if ($hashes && isset($hashes[$fileHash])) {
    $existing = $hashes[$fileHash];
    $existingPath = $UPLOAD_DIR . $existing['filename'];
    if (file_exists($existingPath)) {
        echo json_encode([
            'status' => 'success',
            'type' => $existing['type'],
            'url' => $UPLOAD_URL . $existing['filename'],
            'width' => $existing['width'] ?? 0,
            'height' => $existing['height'] ?? 0,
            'size' => filesize($existingPath),
            'filename' => $existing['filename'],
            'reused' => true,
        ]);
        exit;
    }
    // File record exists but file is gone — remove stale hash entry
    unset($hashes[$fileHash]);
}

// Generate unique filename
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!$ext) {
    $ext = $isImage ? 'jpg' : 'mp4';
}
$filename = 'broadcast_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$filepath = $UPLOAD_DIR . $filename;

if ($isImage) {
    // Resize image for web
    $resized = resizeImage($file['tmp_name'], $filepath, $mime, $MAX_IMAGE_WIDTH, $MAX_IMAGE_HEIGHT);
    if (!$resized) {
        // Fallback: just move original
        move_uploaded_file($file['tmp_name'], $filepath);
    }

    // Get final dimensions
    $info = getimagesize($filepath);
    $width = $info[0] ?? 0;
    $height = $info[1] ?? 0;

    // Save hash for deduplication
    $hashes[$fileHash] = ['filename' => $filename, 'type' => 'image', 'width' => $width, 'height' => $height];
    file_put_contents($hashFile, json_encode($hashes));

    echo json_encode([
        'status' => 'success',
        'type' => 'image',
        'url' => $UPLOAD_URL . $filename,
        'width' => $width,
        'height' => $height,
        'size' => filesize($filepath),
        'filename' => $filename,
    ]);
} else {
    // Video: compress to small MP4 with ffmpeg (YouTube-like quality)
    $FFMPEG = __DIR__ . '/bin/ffmpeg';
    $outputFilename = 'broadcast_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.mp4';
    $outputPath = $UPLOAD_DIR . $outputFilename;
    $tmpInput = $file['tmp_name'];

    // Move uploaded file to a temp location ffmpeg can read
    $tmpVideo = $UPLOAD_DIR . 'tmp_' . bin2hex(random_bytes(4)) . '.' . $ext;
    move_uploaded_file($tmpInput, $tmpVideo);

    // Compress: scale to max 720p height, H.264, AAC audio, fast preset, CRF 28
    // -movflags +faststart enables progressive web playback
    $cmd = escapeshellcmd($FFMPEG) . ' -i ' . escapeshellarg($tmpVideo)
        . ' -vf "scale=-2:\'min(720,ih)\'"'
        . ' -c:v libx264 -preset fast -crf 28'
        . ' -c:a aac -b:a 96k -ac 2'
        . ' -movflags +faststart'
        . ' -y ' . escapeshellarg($outputPath)
        . ' 2>&1';

    $output = shell_exec($cmd);
    $success = file_exists($outputPath) && filesize($outputPath) > 0;

    // Clean up temp input
    @unlink($tmpVideo);

    if ($success) {
        // Get video dimensions from ffmpeg
        $probeCmd = escapeshellcmd($FFMPEG) . ' -i ' . escapeshellarg($outputPath) . ' 2>&1';
        $probeOut = shell_exec($probeCmd);
        $width = 0; $height = 0;
        if (preg_match('/(\d{2,5})x(\d{2,5})/', $probeOut, $m)) {
            $width = (int)$m[1];
            $height = (int)$m[2];
        }

        $origSize = $file['size'];
        $newSize = filesize($outputPath);

        // Save hash for deduplication
        $hashes[$fileHash] = ['filename' => $outputFilename, 'type' => 'video', 'width' => $width, 'height' => $height];
        file_put_contents($hashFile, json_encode($hashes));

        echo json_encode([
            'status' => 'success',
            'type' => 'video',
            'url' => $UPLOAD_URL . $outputFilename,
            'width' => $width,
            'height' => $height,
            'size' => $newSize,
            'original_size' => $origSize,
            'compression' => $origSize > 0 ? round((1 - $newSize / $origSize) * 100) . '% smaller' : 'n/a',
            'filename' => $outputFilename,
        ]);
    } else {
        // ffmpeg failed — delete everything, don't keep large files on server
        @unlink($tmpVideo);
        @unlink($outputPath);
        error_log("ffmpeg compression failed: " . substr($output ?? '', -500));

        echo json_encode([
            'status' => 'error',
            'message' => 'Video compression failed. Please try a shorter or smaller video.',
        ]);
    }
}

/**
 * Resize image to fit within max dimensions, preserving aspect ratio.
 * Outputs as JPEG (quality 85) or PNG depending on source.
 */
function resizeImage($source, $dest, $mime, $maxW, $maxH) {
    $img = null;
    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($source); break;
        case 'image/png':  $img = @imagecreatefrompng($source); break;
        case 'image/gif':  $img = @imagecreatefromgif($source); break;
        case 'image/webp': $img = @imagecreatefromwebp($source); break;
    }
    if (!$img) return false;

    $origW = imagesx($img);
    $origH = imagesy($img);

    // Only resize if larger than max
    if ($origW <= $maxW && $origH <= $maxH) {
        // Still save through GD to strip EXIF and normalize
        $ratio = 1;
        $newW = $origW;
        $newH = $origH;
    } else {
        $ratioW = $maxW / $origW;
        $ratioH = $maxH / $origH;
        $ratio = min($ratioW, $ratioH);
        $newW = (int)round($origW * $ratio);
        $newH = (int)round($origH * $ratio);
    }

    $resized = imagecreatetruecolor($newW, $newH);

    // Preserve transparency for PNG/GIF/WebP
    if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
    }

    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    // Save
    if ($mime === 'image/png') {
        imagepng($resized, $dest, 8);
    } elseif ($mime === 'image/webp') {
        imagewebp($resized, $dest, 85);
    } else {
        imagejpeg($resized, $dest, 85);
    }

    imagedestroy($img);
    imagedestroy($resized);
    return true;
}
