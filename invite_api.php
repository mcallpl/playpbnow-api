<?php
/**
 * Invite API — Create and manage match invites
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

function generateMatchCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $existing = dbGetRow("SELECT id FROM match_invites WHERE match_code = ?", [$code]);
    } while ($existing);
    return $code;
}

switch ($action) {

    case 'create':
        $user_id = $input['user_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'user_id is required']); exit; }

        if (!userHasActiveSubscription($user_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Active subscription required']); exit;
        }

        $court_name = trim($input['court_name'] ?? '');
        $match_date = trim($input['match_date'] ?? '');
        $match_time = trim($input['match_time'] ?? '');
        if (empty($court_name) || empty($match_date) || empty($match_time)) {
            echo json_encode(['status' => 'error', 'message' => 'Court name, date, and time are required']); exit;
        }

        $match_code = generateMatchCode();
        $max_spots = (int)($input['max_spots'] ?? 4);

        $invite_id = dbInsert(
            "INSERT INTO match_invites (user_id, court_name, court_address, match_date, match_time, max_spots, spots_left, message_body, match_code, cost, match_type, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            [
                $user_id,
                $court_name,
                trim($input['court_address'] ?? ''),
                $match_date,
                $match_time,
                $max_spots,
                $max_spots,
                trim($input['message_body'] ?? ''),
                $match_code,
                trim($input['cost'] ?? 'Free'),
                trim($input['match_type'] ?? 'Open Play'),
            ]
        );

        echo json_encode(['status' => 'success', 'invite_id' => $invite_id, 'match_code' => $match_code]);
        break;

    case 'send':
        $user_id = $input['user_id'] ?? null;
        $invite_id = $input['invite_id'] ?? null;
        $player_ids = $input['player_ids'] ?? [];

        if (!$user_id || !$invite_id || empty($player_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'user_id, invite_id, and player_ids are required']); exit;
        }

        $invite = dbGetRow("SELECT * FROM match_invites WHERE id = ? AND user_id = ?", [$invite_id, $user_id]);
        if (!$invite) { echo json_encode(['status' => 'error', 'message' => 'Invite not found']); exit; }

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

        $dateFormatted = date('l, M j', strtotime($invite['match_date']));
        $timeFormatted = date('g:i A', strtotime($invite['match_time']));
        $costDisplay = $invite['cost'] ?: 'Free';

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

            $responseUrl = "https://peoplestar.com/PlayPBNow/invite.html?code={$invite['match_code']}&player_id={$player['id']}";

            // Keep SMS ultra-short to save Twilio costs. All details are on the web page.
            $shortDate = date('D M j', strtotime($invite['match_date']));
            $shortTime = date('g:iA', strtotime($invite['match_time']));
            $message = "{$player['first_name']}, pickleball {$shortDate} {$shortTime} @ {$invite['court_name']}. RSVP: {$responseUrl}";

            try {
                $client->messages->create($phone, ['from' => TWILIO_PHONE_NUMBER, 'body' => $message]);

                // Record invite response
                dbInsert(
                    "INSERT INTO invite_responses (invite_id, player_id, player_phone, player_name, status) VALUES (?, ?, ?, ?, 'pending')",
                    [$invite_id, $player['id'], $phone, $player['first_name']]
                );

                // Deduct credit (admins are exempt)
                if (!$is_admin) {
                    dbQuery("UPDATE sms_credits SET credits = credits - 1 WHERE user_id = ?", [$user_id]);
                    dbInsert(
                        "INSERT INTO sms_credit_log (user_id, change_type, credits_changed, reason) VALUES (?, 'deduct', -1, ?)",
                        [$user_id, "SMS invite to player {$player['id']} for invite {$invite_id}"]
                    );
                }

                $sent++;
                $sentNames[] = $player['first_name'];
            } catch (\Exception $e) {
                $failed++;
                $failedNames[] = $player['first_name'] . ' (' . $e->getMessage() . ')';
                error_log("SMS send failed to player {$player['id']}: " . $e->getMessage());
            }
        }

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

    case 'list':
        $user_id = $input['user_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'user_id is required']); exit; }

        $invites = dbGetAll(
            "SELECT mi.*,
                    (SELECT COUNT(*) FROM invite_responses WHERE invite_id = mi.id AND status = 'confirmed') AS confirmed,
                    (SELECT COUNT(*) FROM invite_responses WHERE invite_id = mi.id AND status = 'interested') AS interested,
                    (SELECT COUNT(*) FROM invite_responses WHERE invite_id = mi.id AND status = 'declined') AS declined,
                    (SELECT COUNT(*) FROM invite_responses WHERE invite_id = mi.id AND status = 'pending') AS pending,
                    (SELECT COUNT(*) FROM invite_responses WHERE invite_id = mi.id AND status = 'waitlisted') AS waitlisted
             FROM match_invites mi
             WHERE mi.user_id = ?
             ORDER BY mi.created_at DESC",
            [$user_id]
        );

        echo json_encode(['status' => 'success', 'invites' => $invites]);
        break;

    case 'detail':
        $user_id = $input['user_id'] ?? null;
        $invite_id = $input['invite_id'] ?? null;

        if (!$user_id || !$invite_id) {
            echo json_encode(['status' => 'error', 'message' => 'user_id and invite_id required']); exit;
        }

        $invite = dbGetRow("SELECT * FROM match_invites WHERE id = ? AND user_id = ?", [$invite_id, $user_id]);
        if (!$invite) { echo json_encode(['status' => 'error', 'message' => 'Invite not found']); exit; }

        $responses = dbGetAll(
            "SELECT ir.status, ir.response_time, ir.player_id, pp.first_name, pp.last_name, pp.play_level
             FROM invite_responses ir
             JOIN pool_players pp ON pp.id = ir.player_id
             WHERE ir.invite_id = ?
             ORDER BY FIELD(ir.status, 'confirmed', 'waitlisted', 'interested', 'pending', 'declined'), ir.response_time ASC",
            [$invite_id]
        );

        // Add waitlist position for waitlisted players
        $waitlistPos = 0;
        foreach ($responses as &$r) {
            if ($r['status'] === 'waitlisted') {
                $waitlistPos++;
                $r['waitlist_position'] = $waitlistPos;
            }
        }
        unset($r);

        $waitlistCount = dbGetRow(
            "SELECT COUNT(*) AS c FROM invite_responses WHERE invite_id = ? AND status = 'waitlisted'",
            [$invite_id]
        )['c'] ?? 0;

        echo json_encode(['status' => 'success', 'invite' => $invite, 'responses' => $responses, 'waitlist_count' => (int)$waitlistCount]);
        break;

    case 'cancel':
        $user_id = $input['user_id'] ?? null;
        $invite_id = $input['invite_id'] ?? null;

        if (!$user_id || !$invite_id) {
            echo json_encode(['status' => 'error', 'message' => 'user_id and invite_id required']); exit;
        }

        $invite = dbGetRow("SELECT * FROM match_invites WHERE id = ? AND user_id = ?", [$invite_id, $user_id]);
        if (!$invite) { echo json_encode(['status' => 'error', 'message' => 'Invite not found']); exit; }

        dbQuery("UPDATE match_invites SET status = 'cancelled' WHERE id = ?", [$invite_id]);
        echo json_encode(['status' => 'success', 'message' => 'Invite cancelled']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
