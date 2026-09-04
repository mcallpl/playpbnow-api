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
require_once __DIR__ . '/sms_throttle.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

/**
 * Atomically take one seat on an invite. Returns true only if THIS call
 * decremented spots_left (audit C3): two simultaneous "I'm In" taps can no
 * longer both confirm on a stale spots_left read. dbQuery() hands back the
 * un-closed statement, so affected_rows is readable; if it ever isn't, fall
 * back to a follow-up SELECT and treat "seat count moved" as taken.
 */
function pbnow_take_seat(int $invite_id, int $before): bool {
    $stmt = dbQuery("UPDATE match_invites SET spots_left = spots_left - 1 WHERE id = ? AND spots_left > 0", [$invite_id]);
    if ($stmt === false) return false;
    $rows = null;
    try { $rows = $stmt->affected_rows; } catch (\Throwable $e) { $rows = null; }
    try { $stmt->close(); } catch (\Throwable $e) { /* already closed */ }
    if ($rows !== null && $rows >= 0) return $rows > 0;
    // affected_rows unavailable: follow-up SELECT
    $after = dbGetRow("SELECT spots_left FROM match_invites WHERE id = ?", [$invite_id]);
    return $after !== null && (int)$after['spots_left'] < $before;
}

/**
 * Whether the match has already started (Pacific; db_config sets the default
 * timezone to America/Los_Angeles). Unparseable rows are treated as upcoming
 * so a data glitch never locks players out of a real invite.
 */
function pbnow_invite_is_past(array $invite): bool {
    $ts = strtotime(($invite['match_date'] ?? '') . ' ' . ($invite['match_time'] ?? '00:00:00'));
    if ($ts === false) return false;
    return $ts < time();
}

/**
 * Auto-promote the next waitlisted player for an invite.
 * Sends them an SMS notification that a spot opened up.
 *
 * Only promotes when a seat is genuinely free (audit C3): recomputes the
 * confirmed count against max_spots and takes the seat atomically, so a
 * decline by a wait-listed player (which frees nothing) can no longer push
 * the invite over capacity.
 */
function promoteNextWaitlisted($invite_id) {
    $invite_id = (int) $invite_id;

    // Is there really a seat? Reconcile spots_left from the confirmed count
    // first, then take one atomically.
    $inviteRow = dbGetRow("SELECT * FROM match_invites WHERE id = ?", [$invite_id]);
    if (!$inviteRow) return null;
    if (($inviteRow['status'] ?? 'active') !== 'active') return null;
    $confirmedNow = dbGetRow("SELECT COUNT(*) AS c FROM invite_responses WHERE invite_id = ? AND status = 'confirmed'", [$invite_id]);
    $freeSeats = (int)$inviteRow['max_spots'] - (int)($confirmedNow['c'] ?? 0);
    if ($freeSeats <= 0) return null;
    dbQuery("UPDATE match_invites SET spots_left = ? WHERE id = ?", [$freeSeats, $invite_id]);

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

    if (!pbnow_take_seat($invite_id, $freeSeats)) return null;

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

    // Send SMS notification to promoted player. Throttled per invite so a
    // decline/confirm loop can't turn into an unbounded Twilio burst.
    $phone = cleanPhoneNumber($next['phone'] ?? '');
    if (!empty($phone) && strlen(preg_replace('/[^0-9]/', '', $phone)) >= 10
        && sms_throttle("promote_invite_{$invite_id}", 40, 3600)) {
        try {
            $shortDate = date('D M j', strtotime($invite['match_date']));
            $shortTime = date('g:iA', strtotime($invite['match_time']));
            $message = "PlayPBNow: {$playerName}, a spot opened up! You're IN for pickleball {$shortDate} {$shortTime} @ {$invite['court_name']}. See you there!";

            $client = new \Twilio\Rest\Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
            $client->messages->create($phone, ['from' => TWILIO_PHONE_NUMBER, 'body' => $message]);

            // Note: No credit deduction for waitlist promotions — it's part of the original invite cost
        } catch (\Throwable $e) {
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

        // Has the match already started? The page hides the RSVP buttons.
        $invite['is_past'] = pbnow_invite_is_past($invite);

        // ── Your current answer (audit M4) ──
        // When the link carries player_id, tell the page what this player has
        // already said so it can preselect it and offer "Change your answer"
        // instead of pretending it's a blank slate.
        $your_status = null;
        $your_waitlist_position = null;
        $view_player_id = (int) ($input['player_id'] ?? 0);
        if ($view_player_id > 0) {
            $mine = dbGetRow(
                "SELECT id, status, response_time FROM invite_responses WHERE invite_id = ? AND player_id = ? LIMIT 1",
                [$invite['id'], $view_player_id]
            );
            if ($mine) {
                $your_status = $mine['status'];
                if ($your_status === 'waitlisted') {
                    $ahead = dbGetRow(
                        "SELECT COUNT(*) AS c FROM invite_responses
                         WHERE invite_id = ? AND status = 'waitlisted'
                           AND (response_time < ? OR (response_time = ? AND id < ?))",
                        [$invite['id'], $mine['response_time'], $mine['response_time'], (int)$mine['id']]
                    );
                    $your_waitlist_position = (int)($ahead['c'] ?? 0) + 1;
                }
            } else {
                $your_status = 'not_invited';
            }
        }

        echo json_encode([
            'status' => 'success',
            'invite' => $invite,
            'your_status' => $your_status,
            'your_waitlist_position' => $your_waitlist_position,
        ]);
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

        // ── Expired invites stay closed (audit M5) ──
        if (pbnow_invite_is_past($invite)) {
            echo json_encode(['status' => 'error', 'message' => 'This match has already happened', 'is_past' => true]); exit;
        }

        // Check player was invited
        $player_id = (int) $player_id;
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
        $stayedWaitlisted = false;   // waitlisted player tapped "I'm In" again and there is still no seat

        // ── Waitlist Logic (atomic seat grab, audit C3) ──
        // Previously this read spots_left, then decided. Two players tapping
        // "I'm In" at the same moment both saw 1 seat and both confirmed. Now
        // the seat is taken with a conditional UPDATE and only the caller whose
        // UPDATE actually changed a row is confirmed; the other is wait-listed.
        // A wait-listed player tapping "I'm In" again goes through the same
        // grab (they used to be confirmed outright, over capacity).
        if ($status === 'confirmed' && $previousStatus !== 'confirmed') {
            $took = pbnow_take_seat((int)$invite['id'], (int)$invite['spots_left']);
            if (!$took) {
                // No spots — add to waitlist instead
                $actualStatus = 'waitlisted';
                if ($previousStatus === 'waitlisted') {
                    $stayedWaitlisted = true;   // keep their place in line (no response_time bump)
                } else {
                    $wasWaitlisted = true;
                }
            }
        }

        // ── No-op responses (audit H3) ──
        // Same answer as before: nothing to change, nothing to announce, and
        // above all no organizer text. Just report the current state.
        $unchanged = ($actualStatus === $previousStatus);

        // Update response
        if ($stayedWaitlisted) {
            // Status unchanged and their queue position must not move.
            $unchanged = true;
        } elseif (!$unchanged) {
            dbQuery(
                "UPDATE invite_responses SET status = ?, response_time = NOW() WHERE invite_id = ? AND player_id = ?",
                [$actualStatus, $invite['id'], $player_id]
            );
        }

        // Recalculate spots_left (reconciliation: the atomic grab above already
        // moved it, this keeps it exact against the confirmed count).
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

        if (!$unchanged) {
            dbInsert(
                "INSERT INTO invite_messages (invite_id, player_id, sender_name, message, is_system) VALUES (?, ?, ?, ?, 1)",
                [$invite['id'], $player_id, 'System', $sysMsg]
            );
        }

        // ── Auto-Promote from Waitlist (audit C3) ──
        // Only a CONFIRMED player changing their answer frees a seat. A
        // wait-listed player declining frees nothing (they never held a seat),
        // so promoting on that path was what overbooked matches. Note
        // promoteNextWaitlisted() re-checks capacity itself as well.
        if ($previousStatus === 'confirmed' && $actualStatus !== 'confirmed') {
            // A spot opened — try to promote next waitlisted player
            $promoted = promoteNextWaitlisted($invite['id']);
        }

        // Also handle: if player was waitlisted and now declines, remove from waitlist
        // (already handled by the status update above)

        // ── Notify the Invite Organizer via SMS ──
        // The person who created the invite needs to know when someone responds.
        // Skipped when the answer didn't change (audit H3) and rate-limited per
        // organizer so a tap-happy player can't run up their Twilio bill.
        $organizer = dbGetRow(
            "SELECT u.phone, u.first_name FROM users u
             JOIN match_invites mi ON mi.user_id = u.id
             WHERE mi.id = ?",
            [$invite['id']]
        );
        if (!$unchanged && $organizer && !empty($organizer['phone'])) {
            $orgPhone = cleanPhoneNumber($organizer['phone']);
            if (!empty($orgPhone) && strlen(preg_replace('/[^0-9]/', '', $orgPhone)) >= 10
                && sms_throttle("rsvp_notify_{$invite['id']}_{$player_id}", 6, 3600)
                && sms_throttle("rsvp_notify_org_" . $orgPhone, 60, 3600)) {
                try {
                    $shortDate = date('D M j', strtotime($invite['match_date']));
                    $shortTime = date('g:iA', strtotime($invite['match_time']));
                    $courtShort = $invite['court_name'];

                    if ($wasWaitlisted) {
                        $notifyMsg = "📋 {$playerName} joined the WAITLIST for {$shortDate} {$shortTime} @ {$courtShort} (all spots full).";
                    } elseif ($actualStatus === 'confirmed') {
                        $notifyMsg = "✅ {$playerName} is IN for {$shortDate} {$shortTime} @ {$courtShort}! ({$spots_left} spot" . ($spots_left !== 1 ? 's' : '') . " left)";
                    } elseif ($actualStatus === 'interested') {
                        $notifyMsg = "🤔 {$playerName} is INTERESTED in {$shortDate} {$shortTime} @ {$courtShort}.";
                    } elseif ($actualStatus === 'declined') {
                        $notifyMsg = "❌ {$playerName} DECLINED {$shortDate} {$shortTime} @ {$courtShort}.";
                    } else {
                        $notifyMsg = "";
                    }

                    if ($notifyMsg) {
                        $client = new \Twilio\Rest\Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
                        $client->messages->create($orgPhone, ['from' => TWILIO_PHONE_NUMBER, 'body' => $notifyMsg]);
                    }
                } catch (\Throwable $e) {
                    error_log("Organizer notification SMS failed: " . $e->getMessage());
                }
            }
        }

        // Build response message
        if ($wasWaitlisted || $stayedWaitlisted) {
            $waitlistPos = dbGetRow(
                "SELECT COUNT(*) AS c FROM invite_responses WHERE invite_id = ? AND status = 'waitlisted' AND (response_time < (SELECT response_time FROM invite_responses WHERE invite_id = ? AND player_id = ?) OR (response_time = (SELECT response_time FROM invite_responses WHERE invite_id = ? AND player_id = ?) AND id < (SELECT id FROM invite_responses WHERE invite_id = ? AND player_id = ?)))",
                [$invite['id'], $invite['id'], $player_id, $invite['id'], $player_id, $invite['id'], $player_id]
            );
            $position = ($waitlistPos['c'] ?? 0) + 1;
            echo json_encode([
                'status' => 'success',
                'message' => $stayedWaitlisted
                    ? "Still full — you're on the waitlist at position #{$position}. We'll text you the moment a spot opens up!"
                    : "All spots are full! You're on the waitlist at position #{$position}. We'll text you if a spot opens up!",
                'waitlisted' => true,
                'waitlist_position' => $position,
                'your_status' => 'waitlisted',
                'unchanged' => $unchanged,
                'spots_left' => $spots_left,
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => $unchanged ? 'Your answer is already recorded' : 'Response recorded',
                'your_status' => $actualStatus,
                'unchanged' => $unchanged,
                'spots_left' => $spots_left,
            ]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
