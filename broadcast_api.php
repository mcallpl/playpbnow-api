<?php
/**
 * Broadcast API — Create and send admin mass messages
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

function generateBroadcastCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $existing = dbGetRow("SELECT id FROM broadcasts WHERE broadcast_code = ?", [$code]);
    } while ($existing);
    return $code;
}

function requireAdmin($user_id) {
    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'user_id is required']); exit;
    }
    $userRow = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$user_id]);
    if (!$userRow || !$userRow['is_admin']) {
        echo json_encode(['status' => 'error', 'message' => 'Admin access required']); exit;
    }
    return true;
}

switch ($action) {

    case 'create':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        $subject = trim($input['subject'] ?? '');
        $body_html = trim($input['body_html'] ?? '');
        $sms_text = trim($input['sms_text'] ?? '');
        $featured_image = trim($input['featured_image'] ?? '');

        if (empty($subject) || empty($body_html) || empty($sms_text)) {
            echo json_encode(['status' => 'error', 'message' => 'subject, body_html, and sms_text are required']); exit;
        }

        $broadcast_code = generateBroadcastCode();

        $broadcast_id = dbInsert(
            "INSERT INTO broadcasts (user_id, subject, body_html, sms_text, featured_image, broadcast_code, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW())",
            [
                $user_id,
                $subject,
                $body_html,
                $sms_text,
                $featured_image,
                $broadcast_code,
            ]
        );

        echo json_encode(['status' => 'success', 'broadcast_id' => $broadcast_id, 'broadcast_code' => $broadcast_code]);
        break;

    case 'send':
        $user_id = $input['user_id'] ?? null;
        $broadcast_id = $input['broadcast_id'] ?? null;
        $player_ids = $input['player_ids'] ?? [];

        if (!$user_id || !$broadcast_id || empty($player_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'user_id, broadcast_id, and player_ids are required']); exit;
        }

        requireAdmin($user_id);

        $broadcast = dbGetRow("SELECT * FROM broadcasts WHERE id = ?", [$broadcast_id]);
        if (!$broadcast) { echo json_encode(['status' => 'error', 'message' => 'Broadcast not found']); exit; }

        // Check if admin (admins bypass credit checks)
        $userRow = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$user_id]);
        $is_admin = (bool)($userRow['is_admin'] ?? false);

        // Check credits (admins bypass)
        $creditRow = dbGetRow("SELECT credits FROM sms_credits WHERE user_id = ?", [$user_id]);
        $balance = $creditRow ? (int)$creditRow['credits'] : 0;
        $needed = count($player_ids);

        if (!$is_admin && $balance < $needed) {
            echo json_encode(['status' => 'error', 'message' => "Not enough credits. You have $balance but need $needed."]); exit;
        }

        // Fetch players
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));
        $players = dbGetAll("SELECT id, first_name, phone FROM pool_players WHERE id IN ($placeholders)", $player_ids);

        if (empty($players)) {
            echo json_encode(['status' => 'error', 'message' => 'No valid players found']); exit;
        }

        $client = new \Twilio\Rest\Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
        $sent = 0;
        $failed = 0;
        $sentNames = [];
        $failedNames = [];

        foreach ($players as $player) {
            $phone = cleanPhoneNumber($player['phone'] ?? '');
            if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
                $failed++;
                $failedNames[] = $player['first_name'];
                continue;
            }

            $broadcastUrl = "https://peoplestar.com/PlayPBNow/broadcast.html?code={$broadcast['broadcast_code']}";

            // Personalize: prepend first name if SMS doesn't already contain it
            $smsBody = $broadcast['sms_text'];
            if (stripos($smsBody, $player['first_name']) === false) {
                $smsBody = "{$player['first_name']}, {$smsBody}";
            }
            $message = "{$smsBody} Details: {$broadcastUrl}";

            try {
                $client->messages->create($phone, ['from' => TWILIO_PHONE_NUMBER, 'body' => $message]);

                // Record recipient
                dbInsert(
                    "INSERT INTO broadcast_recipients (broadcast_id, player_id, player_phone, player_name, status, sent_at) VALUES (?, ?, ?, ?, 'sent', NOW())",
                    [$broadcast_id, $player['id'], $phone, $player['first_name']]
                );

                // Deduct credit (admins are exempt)
                if (!$is_admin) {
                    dbQuery("UPDATE sms_credits SET credits = credits - 1 WHERE user_id = ?", [$user_id]);
                    dbInsert(
                        "INSERT INTO sms_credit_log (user_id, change_type, credits_changed, reason) VALUES (?, 'deduct', -1, ?)",
                        [$user_id, "SMS broadcast to player {$player['id']} for broadcast {$broadcast_id}"]
                    );
                }

                $sent++;
                $sentNames[] = $player['first_name'];
            } catch (\Exception $e) {
                $failed++;
                $failedNames[] = $player['first_name'] . ' (' . $e->getMessage() . ')';
                error_log("Broadcast SMS send failed to player {$player['id']}: " . $e->getMessage());

                // Record failed recipient
                dbInsert(
                    "INSERT INTO broadcast_recipients (broadcast_id, player_id, player_phone, player_name, status, sent_at) VALUES (?, ?, ?, ?, 'failed', NOW())",
                    [$broadcast_id, $player['id'], $phone, $player['first_name']]
                );
            }
        }

        // Update broadcast with sent count and status
        dbQuery("UPDATE broadcasts SET sent_count = ?, status = 'sent' WHERE id = ?", [$sent, $broadcast_id]);

        $newBalance = dbGetRow("SELECT credits FROM sms_credits WHERE user_id = ?", [$user_id]);

        echo json_encode([
            'status' => 'success',
            'sent_count' => $sent,
            'failed_count' => $failed,
            'sent_names' => $sentNames,
            'failed_names' => $failedNames,
            'credits_remaining' => (int)($newBalance['credits'] ?? 0),
        ]);
        break;

    case 'update':
        $user_id = $input['user_id'] ?? null;
        $broadcast_id = $input['broadcast_id'] ?? null;
        requireAdmin($user_id);

        if (!$broadcast_id) {
            echo json_encode(['status' => 'error', 'message' => 'broadcast_id required']); exit;
        }

        $broadcast = dbGetRow("SELECT * FROM broadcasts WHERE id = ? AND status = 'draft'", [$broadcast_id]);
        if (!$broadcast) {
            echo json_encode(['status' => 'error', 'message' => 'Draft broadcast not found']); exit;
        }

        $updates = [];
        $params = [];

        if (isset($input['subject']) && trim($input['subject']) !== '') {
            $updates[] = 'subject = ?';
            $params[] = trim($input['subject']);
        }
        if (isset($input['body_html']) && trim($input['body_html']) !== '') {
            $updates[] = 'body_html = ?';
            $params[] = trim($input['body_html']);
        }
        if (isset($input['sms_text']) && trim($input['sms_text']) !== '') {
            $updates[] = 'sms_text = ?';
            $params[] = trim($input['sms_text']);
        }
        if (isset($input['featured_image'])) {
            $updates[] = 'featured_image = ?';
            $params[] = trim($input['featured_image']);
        }

        if (empty($updates)) {
            echo json_encode(['status' => 'error', 'message' => 'No updates provided']); exit;
        }

        $params[] = $broadcast_id;
        dbQuery("UPDATE broadcasts SET " . implode(', ', $updates) . " WHERE id = ?", $params);

        echo json_encode(['status' => 'success', 'message' => 'Broadcast updated', 'broadcast_code' => $broadcast['broadcast_code']]);
        break;

    case 'view':
        $broadcast_code = $input['broadcast_code'] ?? $_GET['broadcast_code'] ?? $_GET['code'] ?? '';
        if (empty($broadcast_code)) {
            echo json_encode(['status' => 'error', 'message' => 'broadcast_code is required']); exit;
        }

        $broadcast = dbGetRow("SELECT subject, body_html, featured_image, created_at FROM broadcasts WHERE broadcast_code = ?", [$broadcast_code]);
        if (!$broadcast) {
            echo json_encode(['status' => 'error', 'message' => 'Broadcast not found']); exit;
        }

        echo json_encode([
            'status' => 'success',
            'subject' => $broadcast['subject'],
            'body_html' => $broadcast['body_html'],
            'featured_image' => $broadcast['featured_image'] ?? '',
            'created_at' => $broadcast['created_at'],
        ]);
        break;

    case 'list':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        $broadcasts = dbGetAll(
            "SELECT * FROM broadcasts ORDER BY created_at DESC",
            []
        );

        echo json_encode(['status' => 'success', 'broadcasts' => $broadcasts]);
        break;

    case 'schema':
        $results = [];

        $stmt = dbQuery("CREATE TABLE IF NOT EXISTS broadcasts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            subject VARCHAR(255),
            body_html TEXT,
            sms_text VARCHAR(320),
            featured_image VARCHAR(500) DEFAULT '',
            broadcast_code VARCHAR(10) UNIQUE,
            sent_count INT DEFAULT 0,
            status ENUM('draft','sent','cancelled') DEFAULT 'draft',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $results[] = $stmt ? 'broadcasts table OK' : 'broadcasts table FAILED';

        // Add featured_image column if missing (existing tables)
        try { dbQuery("ALTER TABLE broadcasts ADD COLUMN featured_image VARCHAR(500) DEFAULT '' AFTER sms_text"); $results[] = 'featured_image column added'; }
        catch (\Exception $e) { $results[] = 'featured_image column exists'; }

        $stmt = dbQuery("CREATE TABLE IF NOT EXISTS broadcast_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            broadcast_id INT,
            player_id INT,
            player_phone VARCHAR(20),
            player_name VARCHAR(100),
            status ENUM('sent','failed') DEFAULT 'sent',
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(broadcast_id),
            INDEX(player_id)
        )");
        $results[] = $stmt ? 'broadcast_recipients table OK' : 'broadcast_recipients table FAILED';

        echo json_encode(['status' => 'success', 'results' => $results]);
        break;

    case 'delete':
        $user_id = $input['user_id'] ?? null;
        $broadcast_id = $input['broadcast_id'] ?? null;
        requireAdmin($user_id);

        if (!$broadcast_id) {
            echo json_encode(['status' => 'error', 'message' => 'broadcast_id required']); exit;
        }

        $broadcast = dbGetRow("SELECT * FROM broadcasts WHERE id = ?", [$broadcast_id]);
        if (!$broadcast) {
            echo json_encode(['status' => 'error', 'message' => 'Broadcast not found']); exit;
        }

        // Collect all media URLs to delete (featured image + any in body_html)
        $UPLOAD_DIR = __DIR__ . '/uploads/';
        $UPLOAD_URL = 'https://peoplestar.com/PlayPBNow/api/uploads/';
        $hashFile = $UPLOAD_DIR . '.hashes.json';
        $deletedFiles = [];

        // Extract media filenames from featured_image and body_html
        $mediaUrls = [];
        if (!empty($broadcast['featured_image'])) {
            $mediaUrls[] = $broadcast['featured_image'];
        }
        // Find all upload URLs in body_html (images and videos)
        if (!empty($broadcast['body_html'])) {
            preg_match_all('#https?://peoplestar\.com/PlayPBNow/api/uploads/([^\s"\'<>]+)#', $broadcast['body_html'], $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $fn) {
                    $mediaUrls[] = $UPLOAD_URL . $fn;
                }
            }
        }

        // Check if each media file is used by OTHER broadcasts before deleting
        $mediaUrls = array_unique($mediaUrls);
        foreach ($mediaUrls as $url) {
            $filename = basename($url);
            $filepath = $UPLOAD_DIR . $filename;

            // Check if any OTHER broadcast uses this file
            $otherUse = dbGetRow(
                "SELECT id FROM broadcasts WHERE id != ? AND (featured_image LIKE ? OR body_html LIKE ?)",
                [$broadcast_id, '%' . $filename . '%', '%' . $filename . '%']
            );

            if (!$otherUse && file_exists($filepath)) {
                @unlink($filepath);
                $deletedFiles[] = $filename;

                // Remove from hash index
                if (file_exists($hashFile)) {
                    $hashes = json_decode(file_get_contents($hashFile), true) ?: [];
                    foreach ($hashes as $hash => $entry) {
                        if (($entry['filename'] ?? '') === $filename) {
                            unset($hashes[$hash]);
                        }
                    }
                    file_put_contents($hashFile, json_encode($hashes));
                }
            }
        }

        // Delete recipients and broadcast
        dbQuery("DELETE FROM broadcast_recipients WHERE broadcast_id = ?", [$broadcast_id]);
        dbQuery("DELETE FROM broadcasts WHERE id = ?", [$broadcast_id]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Broadcast deleted',
            'deleted_files' => $deletedFiles,
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
