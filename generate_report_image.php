<?php
// api/generate_report_image.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';

// 1. INPUT
$input = json_decode(file_get_contents('php://input'), true);
$schedule = $input['schedule'] ?? [];
$group_name = $input['group_name'] ?? 'Pickleball Group';
$date_str = $input['date_str'] ?? date('F j, Y');
$court_name = $input['court_name'] ?? '';
$user_id = $input['user_id'] ?? null;

// Check if user has pro access (clean reports)
$is_pro = false;
if ($user_id) {
    $is_pro = userHasActiveSubscription($user_id);
}

if (empty($schedule)) {
    echo json_encode(['status' => 'error', 'message' => 'No schedule data']);
    exit;
}

// 2. CONFIG
$font_file = './fonts/DejaVuSans.ttf'; 
$use_ttf = file_exists($font_file);

// --- SETTINGS ---
$margin = 20;
$col_round_width = 80;      
$col_court_width = 360;    
$col_bye_width = 120; // New Column for Byes
$header_height = 160;       
$table_header_height = 60;  
$row_height = 140;          
$edge_pad_x = 6; 
$edge_pad_y = 4;

// 3. CALCULATE SIZE & CHECK BYES
$max_courts = 0;
$has_byes = false;

foreach ($schedule as $round) {
    if (isset($round['games'])) $max_courts = max($max_courts, count($round['games']));
    if (!empty($round['byes'])) $has_byes = true;
}
$max_courts = max(1, $max_courts); 

$img_width = ($margin * 2) + $col_round_width + ($max_courts * $col_court_width);
if ($has_byes) {
    $img_width += $col_bye_width; // Add width if needed
}

$img_height = $header_height + $table_header_height + (count($schedule) * $row_height) + $margin;

// 4. INIT IMAGE
$im = imagecreatetruecolor($img_width, $img_height);

// Colors
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
$dark_bg = imagecolorallocate($im, 40, 40, 40);
$light_bg = imagecolorallocate($im, 245, 245, 245);
$grid_color = imagecolorallocate($im, 180, 180, 180);
$vs_color = imagecolorallocate($im, 150, 150, 150);
$bye_bg = imagecolorallocate($im, 255, 245, 230); // Slight tint for Bye column

imagefilledrectangle($im, 0, 0, $img_width, $img_height, $white);

// --- FUNCTIONS ---
function calculateMaxFontSize($text, $maxWidth, $maxHeight, $fontFile) {
    if (!file_exists($fontFile)) return 10;
    $size = 60; 
    while ($size > 8) {
        $bbox = imagettfbbox($size, 0, $fontFile, $text);
        $text_w = abs($bbox[4] - $bbox[0]);
        $text_h = abs($bbox[5] - $bbox[1]);
        if ($text_w <= $maxWidth && $text_h <= $maxHeight) return $size;
        $size--;
    }
    return 8;
}

function drawCenteredText($im, $font, $size, $x1, $y1, $x2, $y2, $color, $text) {
    if (!file_exists($font)) {
        $mid_x = $x1 + (($x2 - $x1)/2) - (strlen($text)*3);
        $mid_y = $y1 + (($y2 - $y1)/2) - 8;
        imagestring($im, 5, intval($mid_x), intval($mid_y), $text, $color);
        return;
    }
    $bbox = imagettfbbox($size, 0, $font, $text);
    $text_w = abs($bbox[4] - $bbox[0]);
    $text_h = abs($bbox[5] - $bbox[1]);
    $center_x = $x1 + (($x2 - $x1) / 2);
    $center_y = $y1 + (($y2 - $y1) / 2);
    $draw_x = $center_x - ($text_w / 2);
    $draw_y = $center_y + ($text_h / 3);
    imagettftext($im, $size, 0, intval($draw_x), intval($draw_y), $color, $font, $text);
}

// --- SCAN FOR FONT SIZE ---
$name_box_w = $col_court_width - ($edge_pad_x * 2);
$name_box_h = ($row_height * 0.45) - ($edge_pad_y * 2); 
$global_font_size = 60; 

foreach ($schedule as $round) {
    $games = $round['games'] ?? [];
    foreach ($games as $g) {
        $t1 = ($g['team1'][0]['first_name'] ?? '?') . " & " . ($g['team1'][1]['first_name'] ?? '?');
        $t2 = ($g['team2'][0]['first_name'] ?? '?') . " & " . ($g['team2'][1]['first_name'] ?? '?');
        $s1 = calculateMaxFontSize($t1, $name_box_w, $name_box_h, $font_file);
        $s2 = calculateMaxFontSize($t2, $name_box_w, $name_box_h, $font_file);
        if ($s1 < $global_font_size) $global_font_size = $s1;
        if ($s2 < $global_font_size) $global_font_size = $s2;
    }
}
$final_font_size = min(28, $global_font_size); 

// --- DRAWING ---

// Header
drawCenteredText($im, $font_file, 40, 0, 10, $img_width, 80, $black, "Play PB Now!");
drawCenteredText($im, $font_file, 18, 0, 80, $img_width, 120, $black, "$group_name ($date_str)");
if ($court_name) {
    $court_color = imagecolorallocate($im, 80, 80, 80);
    drawCenteredText($im, $font_file, 16, 0, 118, $img_width, 158, $court_color, $court_name);
}

// Table Header
$y_cur = $header_height;
imagefilledrectangle($im, $margin, $y_cur, $img_width - $margin, $y_cur + $table_header_height, $dark_bg);

$x_cur = $margin;
// ROUND
drawCenteredText($im, $font_file, 12, $x_cur, $y_cur, $x_cur+$col_round_width, $y_cur+$table_header_height, $white, "#");
$x_cur += $col_round_width;

// COURTS
for ($i=0; $i<$max_courts; $i++) {
    $x_next = $x_cur + $col_court_width;
    drawCenteredText($im, $font_file, 14, $x_cur, $y_cur, $x_next, $y_cur+$table_header_height, $white, "COURT " . ($i + 1));
    $x_cur = $x_next;
}

// BYES HEADER
if ($has_byes) {
    drawCenteredText($im, $font_file, 14, $x_cur, $y_cur, $x_cur + $col_bye_width, $y_cur+$table_header_height, $white, "BYES");
}

$y_cur += $table_header_height;

// Matches
foreach ($schedule as $idx => $round) {
    
    // Fill Row Background
    if ($idx % 2 == 1) {
        imagefilledrectangle($im, $margin, $y_cur, $img_width - $margin, $y_cur + $row_height, $light_bg);
    }
    
    // Border
    imagerectangle($im, $margin, $y_cur, $img_width - $margin, $y_cur + $row_height, $grid_color);

    $x_cur = $margin;

    // Round #
    drawCenteredText($im, $font_file, 32, $x_cur, $y_cur, $x_cur+$col_round_width, $y_cur+$row_height, $black, (string)($idx+1));
    $x_cur += $col_round_width;
    imageline($im, $x_cur, $y_cur, $x_cur, $y_cur+$row_height, $grid_color);

    // Games
    $games = $round['games'] ?? [];
    for ($i=0; $i<$max_courts; $i++) {
        $x_next = $x_cur + $col_court_width;
        
        if (isset($games[$i])) {
            $g = $games[$i];
            $t1 = ($g['team1'][0]['first_name'] ?? '?') . " & " . ($g['team1'][1]['first_name'] ?? '?');
            $t2 = ($g['team2'][0]['first_name'] ?? '?') . " & " . ($g['team2'][1]['first_name'] ?? '?');

            $h_zone = $row_height * 0.45;
            $y_t1_start = $y_cur + 2; 
            $y_t1_end   = $y_cur + $h_zone;
            $y_vs_start = $y_t1_end;
            $y_vs_end   = $y_vs_start + ($row_height * 0.1);
            $y_t2_start = $y_vs_end;
            $y_t2_end   = $y_cur + $row_height - 2;

            drawCenteredText($im, $font_file, $final_font_size, $x_cur, $y_t1_start, $x_next, $y_t1_end, $black, $t1);
            drawCenteredText($im, $font_file, 10, $x_cur, $y_vs_start, $x_next, $y_vs_end, $vs_color, "vs");
            drawCenteredText($im, $font_file, $final_font_size, $x_cur, $y_t2_start, $x_next, $y_t2_end, $black, $t2);
        } else {
             drawCenteredText($im, $font_file, 16, $x_cur, $y_cur, $x_next, $y_cur+$row_height, $grid_color, "---");
        }
        
        imageline($im, $x_next, $y_cur, $x_next, $y_cur+$row_height, $grid_color);
        $x_cur = $x_next;
    }

    // DRAW BYES COLUMN
    if ($has_byes) {
        $x_bye_end = $x_cur + $col_bye_width;
        // Optional tint for byes
        // imagefilledrectangle($im, $x_cur+1, $y_cur+1, $x_bye_end-1, $y_cur+$row_height-1, $bye_bg);

        $byes = $round['byes'] ?? [];
        if (!empty($byes)) {
            // Draw list of byes
            $count = count($byes);
            $step = $row_height / ($count + 1);
            foreach ($byes as $b_idx => $b_player) {
                $y_pos = $y_cur + ($step * ($b_idx + 1));
                // Smaller elegant text for byes (e.g. 14pt)
                drawCenteredText($im, $font_file, 14, $x_cur, $y_pos - 15, $x_bye_end, $y_pos + 15, $black, $b_player['first_name']);
            }
        }
        imageline($im, $x_bye_end, $y_cur, $x_bye_end, $y_cur+$row_height, $grid_color);
    }

    $y_cur += $row_height;
}

// WATERMARK FOR FREE TIER
if (!$is_pro) {
    // Enable alpha blending for transparency
    imagealphablending($im, true);

    // 1. Diagonal watermark text repeated across the image
    $wm_color = imagecolorallocatealpha($im, 180, 180, 180, 80); // Light gray, ~70% transparent
    if ($use_ttf) {
        $wm_text = "PlayPBNow PRO";
        $wm_size = 36;
        // Tile the watermark diagonally across the entire image
        for ($wy = -100; $wy < $img_height + 100; $wy += 160) {
            for ($wx = -200; $wx < $img_width + 200; $wx += 400) {
                imagettftext($im, $wm_size, 35, $wx, $wy, $wm_color, $font_file, $wm_text);
            }
        }
    }

    // 2. Bottom banner with upgrade CTA
    $banner_h = 48;
    $banner_y = $img_height - $banner_h;
    $banner_bg = imagecolorallocatealpha($im, 27, 51, 88, 30); // Dark blue, semi-transparent
    imagefilledrectangle($im, 0, $banner_y, $img_width, $img_height, $banner_bg);

    $banner_text_color = imagecolorallocate($im, 255, 255, 255);
    $banner_msg = "Upgrade to PlayPBNow Pro for clean reports";
    if ($use_ttf) {
        drawCenteredText($im, $font_file, 14, 0, $banner_y, $img_width, $img_height, $banner_text_color, $banner_msg);
    } else {
        $mid_x = ($img_width / 2) - (strlen($banner_msg) * 4);
        imagestring($im, 4, intval($mid_x), $banner_y + 15, $banner_msg, $banner_text_color);
    }
}

// SAVE
$reports_dir = '../reports';
if (!is_dir($reports_dir)) mkdir($reports_dir, 0755, true);
$filename = 'match_report_' . time() . '.png';
$filepath = $reports_dir . '/' . $filename;

imagepng($im, $filepath);
imagedestroy($im);

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$url = "$protocol://$_SERVER[HTTP_HOST]" . dirname($_SERVER['REQUEST_URI']) . '/../reports/' . $filename;

echo json_encode(['status' => 'success', 'url' => $url]);
?>