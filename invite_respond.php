<?php
/**
 * Invite Respond — Public endpoint for players to respond to invites (via SMS link)
 * Includes waitlist management with auto-promotion and SMS notification
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

/**
 * Auto-promote the next waitlisted player for an invite.
 * Sends them an SMS notification that a spot opened up.
 */
function promoteNextWaitlisted($invite_id) {
    // Find the earliest waitlisted player (first come, first promoted)
    $next = dbGetRow(
        "SELECT ir.*, pp.first_name, pp.phone
         FROM invite_responses ir
         JOIN pool_players pp ON pp.id = ir.player_id
         WHERE ir.invite_id = ? AND ir.status = 'waitlisted'
         ORDER BY ir.response_time ASC, ir.id ASC
         LIMIT 1",
        [$invite_id]
    );

    if (!$next) return null;

    // Promote to confirmed
    dbQuery(
        "UPDATE invite_responses SET status = 'confirmed', response_time = NOW() WHERE id = ?",
        [$next['id']]
    );

    // Recalculate spots_left
    $invite = dbGetRow("SELECT * FROM match_invites WHERE id = ?", [$invite_id]);
    $confirmed = dbGetRow("SELECT COUNT(*) AS c FROM invite_responses WHERE invite_id = ? AND status = 'confirmed'", [$invite_id]);
    $spots_left = max(0, (int)$invite['max_spots'] - (int)$confirmed['c']);
    dbQuery("UPDATE match_invites SET spots_left = ? WHERE id = ?", [$spots_left, $invite_id]);

    // Post system message
    $playerName = trim($next['first_name']);
    dbInsert(
        "INSERT INTO invite_messages (invite_id, player_id, sender_name, message, is_system) VALUES (?, ?, ?, ?, 1)",
        [$invite_id, $next['player_id'], 'System', "{$playerName} moved from waitlist to CONFIRMED! 🎉"]
    );

    // Send SMS notification to promoted player
    $phone = cleanPhoneNumber($next['phone'] ?? '');
    if (!empty($phone) && strlen(preg_replace('/[^0-9]/', '', $phone)) >= 10) {
        try {
            $shortDate = date('D M j', strtotime($invite['match_date']));
            $shortTime = date('g:iA', strtotime($invite['match_time']));
            $message = "{$playerName}, a spot opened up! You're IN for pickleball {$shortDate} {$shortTime} @ {$invite['court_name']}. See you there!";

            $client = new \Twilio\Rest\Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
            $client->messages->create($phone, ['from' => TWILIO_PHONE_NUMBER, 'body' => $message]);

            // Note: No credit deduction for waitlist promotions — it's part of the original invite cost
        } catch (\Exception $e) {
            error_log("Waitlist promotion SMS failed for player {$next['player_id']}: " . $e->getMessage());
        }
    }

    return $next;
}

switch ($action) {

    case 'view':
        $match_code = trim($input['match_code'] ?? '');
        if (empty($match_code)) { echo json_encode(['status' => 'error', 'message' => 'match_code is required']); exit; }

        $invite = dbGetRow(
            "SELECT id, match_code, court_name, court_address, match_date, match_time,
                    max_spots, spots_left, message_body, cost, match_type, status
             FROM match_invites WHERE match_code = ?",
            [$match_code]
        );

        if (!$invite) { echo json_encode(['status' => 'error', 'message' => 'Invite not found']); exit; }
        if ($invite['status'] === 'cancelled') { echo json_encode(['status' => 'error', 'message' => 'This invite has been cancelled']); exit; }

        // Include waitlist count so the response page can show it
        $waitlistCount = dbGetRow(
            "SELECT COUNT(*) AS c FROM invite_responses WHERE invite_id = ? AND status = 'waitlisted'",
            [$invite['id']]
        )['c'] ?? 0;
        $invite['waitlisted'] = (int)$waitlistCount;

        echo json_encode(['status' => 'success', 'invite' => $invite]);
        break;

    case 'respond':
        $match_code = trim($input['match_code'] ?? '');
        $player_id = $input['player_id'] ?? null;
        $status = trim($input['status'] ?? '');

        if (empty($match_code) || !$player_id || empty($status)) {
            echo json_encode(['status' => 'error', 'message' => 'match_code, player_id, and status are required']); exit;
        }

        if (!in_array($status, ['confirmed', 'interested', 'declined'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid status']); exit;
        }

        $invite = dbGetRow("SELECT * FROM match_invites WHERE match_code = ?", [$match_code]);
        if (!$invite) { echo json_encode(['status' => 'error', 'message' => 'Invite not found']); exit; }
        if ($invite['status'] === 'cancelled') { echo json_encode(['status' => 'error', 'message' => 'This invite has been cancelled']); exit; }

        // Check player was invited
        $response = dbGetRow(
            "SELECT * FROM invite_responses WHERE invite_id = ? AND player_id = ?",
            [$invite['id'], $player_id]
        );

        if (!$response) {
            echo json_encode(['status' => 'error', 'message' => 'You were not invited to this match']); exit;
        }

        $previousStatus = $response['status'];
        $actualStatus = $status;
        $wasWaitlisted = false;

        // ── Waitlist Logic ──
        if ($status === 'confirmed' && $previousStatus !== 'confirmed' && $previousStatus !== 'waitlisted') {
            // Player wants to confirm — check if there are spots
            if ((int)$invite['spots_left'] <= 0) {
                // No spots — add to waitlist instead
                $actualStatus = 'waitlisted';
                $wasWaitlisted = true;
            }
        }

        // Update response
        dbQuery(
            "UPDATE invite_responses SET status = ?, response_time = NOW() WHERE invite_id = ? AND player_id = ?",
            [$actualStatus, $invite['id'], $player_id]
        );

        // Recalculate spots_left
        $confirmed = dbGetRow("SELECT COUNT(*) AS c FROM invite_responses WHERE invite_id = ? AND status = 'confirmed'", [$invite['id']]);
        $spots_left = max(0, (int)$invite['max_spots'] - (int)$confirmed['c']);
        dbQuery("UPDATE match_invites SET spots_left = ? WHERE id = ?", [$spots_left, $invite['id']]);

        // Auto-post system message to invite chat
        $player = dbGetRow("SELECT first_name, last_name FROM pool_players WHERE id = ?", [$player_id]);
        $playerName = $player ? trim($player['first_name'] . ' ' . ($player['last_name'] ?? '')) : 'A player';

        if ($wasWaitlisted) {
            $sysMsg = "{$playerName} joined the waitlist (all spots full)";
        } else {
            $statusEmoji = $actualStatus === 'confirmed' ? 'is IN!' : ($actualStatus === 'interested' ? 'is interested' : 'declined');
            $sysMsg = "{$playerName} {$statusEmoji}";
        }

        dbInsert(
            "INSERT INTO invite_messages (invite_id, player_id, sender_name, message, is_system) VALUES (?, ?, ?, ?, 1)",
            [$invite['id'], $player_id, 'System', $sysMsg]
        );

        // ── Auto-Promote from Waitlist ──
        // If someone just declined or changed FROM confirmed, a spot opened up
        if (($previousStatus === 'confirmed' && $actualStatus !== 'confirmed') ||
            ($status === 'declined' && $previousStatus === 'waitlisted')) {
            // A spot may have opened — try to promote next waitlisted player
            $promoted = promoteNextWaitlisted($invite['id']);
        }

        // Also handle: if player was waitlisted and now declines, remove from waitlist
        // (already handled by the status update above)

        // Build response message
        if ($wasWaitlisted) {
            $waitlistPos = dbGetRow(
                "SELECT COUNT(*) AS c FROM invite_responses WHERE invite_id = ? AND status = 'waitlisted' AND (response_time < (SELECT response_time FROM invite_responses WHERE invite_id = ? AND player_id = ?) OR (response_time = (SELECT response_time FROM invite_responses WHERE invite_id = ? AND player_id = ?) AND id < (SELECT id FROM invite_responses WHERE invite_id = ? AND player_id = ?)))",
                [$invite['id'], $invite['id'], $player_id, $invite['id'], $player_id, $invite['id'], $player_id]
            );
            $position = ($waitlistPos['c'] ?? 0) + 1;
            echo json_encode([
                'status' => 'success',
                'message' => "All spots are full! You're on the waitlist at position #{$position}. We'll text you if a spot opens up!",
                'waitlisted' => true,
                'waitlist_position' => $position,
            ]);
        } else {
            echo json_encode(['status' => 'success', 'message' => 'Response recorded']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
