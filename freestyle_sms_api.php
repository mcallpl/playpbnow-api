<?php
/**
 * Freestyle SMS API — Admin sends a custom text message to selected pool players
 * No broadcast landing page needed. Just a direct SMS.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

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

    case 'list_players':
        $user_id = $input['user_id'] ?? $_GET['user_id'] ?? null;
        requireAdmin($user_id);

        $players = dbGetAll(
            "SELECT id, first_name, last_name, phone, email, play_level, cities_to_play, tournament_interest
             FROM pool_players
             WHERE phone IS NOT NULL AND phone != ''
             ORDER BY first_name ASC, last_name ASC",
            []
        );

        // Mask phone numbers for display
        foreach ($players as &$p) {
            $clean = preg_replace('/[^0-9]/', '', $p['phone']);
            if (strlen($clean) >= 10) {
                $p['phone_display'] = '(' . substr($clean, -10, 3) . ') ' . substr($clean, -7, 3) . '-' . substr($clean, -4);
            } else {
                $p['phone_display'] = $p['phone'];
            }
        }

        echo json_encode(['status' => 'success', 'players' => $players, 'total' => count($players)]);
        break;

    case 'send':
        $user_id = $input['user_id'] ?? null;
        $player_ids = $input['player_ids'] ?? [];
        $message_text = trim($input['message'] ?? '');
        $personalize = $input['personalize'] ?? true; // prepend first name

        if (!$user_id || empty($player_ids) || empty($message_text)) {
            echo json_encode(['status' => 'error', 'message' => 'user_id, player_ids, and message are required']); exit;
        }

        requireAdmin($user_id);

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
                $failedNames[] = $player['first_name'] . ' (bad phone)';
                continue;
            }

            $smsBody = $message_text;
            if ($personalize && stripos($smsBody, $player['first_name']) === false) {
                $smsBody = "{$player['first_name']}, {$smsBody}";
            }

            try {
                $client->messages->create(['to' => $phone, 'from' => TWILIO_PHONE_NUMBER, 'body' => $smsBody]);
                $sent++;
                $sentNames[] = $player['first_name'];
            } catch (\Exception $e) {
                $failed++;
                $failedNames[] = $player['first_name'] . ' (' . $e->getMessage() . ')';
                error_log("Freestyle SMS failed to player {$player['id']}: " . $e->getMessage());
            }
        }

        echo json_encode([
            'status' => 'success',
            'sent_count' => $sent,
            'failed_count' => $failed,
            'sent_names' => $sentNames,
            'failed_names' => $failedNames,
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action. Use: list_players, send']);
        break;
}
