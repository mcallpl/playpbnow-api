<?php
/**
 * Invite API — Create and manage match invites
 */

require_once __DIR__ . '/cors_origin.php';
pbnow_send_cors_headers(); // was: hard-coded legacy origin only (UAT 2026-09-04)
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/sms_throttle.php';

// Rebrandly URL shortener with custom OG metadata for rich link previews
function rebrandlyShorten(string $url, string $title = '', string $description = '', string $image = ''): ?string {
    $apiKey = $GLOBALS['vault_rebrandly_api_key'] ?? '';
    if (empty($apiKey)) return null;

    $payload = [
        'destination' => $url,
        'domain' => ['fullName' => 'go.peoplestar.com'],
    ];

    // Set custom OG metadata so iMessage shows our branding
    if ($title || $description || $image) {
        $payload['openGraph'] = [
            'title' => $title ?: 'Play PB Now',
            'description' => $description ?: '',
            'image' => $image ?: '',
        ];
    }

    $ch = curl_init('https://api.rebrandly.com/v1/links');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'apikey: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $resp) {
        $data = json_decode($resp, true);
        return !empty($data['shortUrl']) ? ('https://' . $data['shortUrl']) : null;
    }
    error_log("Rebrandly failed (HTTP $code): $resp");
    return null;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

// All invite actions are organizer actions made from within the app (the public
// pool-player flow lives in invite_respond.php). Require a valid session token
// and force every action to act as the authenticated user — previously a
// spoofable body user_id let anyone create/send invites as, and drain the SMS
// credits of, another account.
require_once __DIR__ . '/require_admin.php';
$__auth_uid = pbnow_require_session_user();
$input['user_id'] = (string) $__auth_uid;

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

        // ── Input validation / caps (audit M5, H5) ──
        // Everything typed here is later rendered on the public RSVP page and
        // inside SMS bodies, so cap lengths server-side (the page also escapes
        // on output) and make sure the date/time are real and not in the past.
        $court_name    = mb_substr($court_name, 0, 200, 'UTF-8');
        $court_address = mb_substr(trim($input['court_address'] ?? ''), 0, 200, 'UTF-8');
        $message_body  = mb_substr(trim($input['message_body'] ?? ''), 0, 500, 'UTF-8');
        $cost_in       = mb_substr(trim($input['cost'] ?? 'Free'), 0, 50, 'UTF-8');
        $match_type_in = mb_substr(trim($input['match_type'] ?? 'Open Play'), 0, 50, 'UTF-8');
        if ($cost_in === '') { $cost_in = 'Free'; }
        if ($match_type_in === '') { $match_type_in = 'Open Play'; }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $match_date, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
            echo json_encode(['status' => 'error', 'message' => 'Match date must be a valid date (YYYY-MM-DD)']); exit;
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $match_time)) {
            echo json_encode(['status' => 'error', 'message' => 'Match time must be a valid time (HH:MM)']); exit;
        }
        // Pacific "today" — db_config sets date_default_timezone_set('America/Los_Angeles').
        if ($match_date < date('Y-m-d')) {
            echo json_encode(['status' => 'error', 'message' => 'Match date cannot be in the past']); exit;
        }

        $match_code = generateMatchCode();
        $max_spots = (int)($input['max_spots'] ?? 4);
        if ($max_spots < 1 || $max_spots > 40) {
            echo json_encode(['status' => 'error', 'message' => 'Max spots must be between 1 and 40']); exit;
        }

        $invite_id = dbInsert(
            "INSERT INTO match_invites (user_id, court_name, court_address, match_date, match_time, max_spots, spots_left, message_body, match_code, cost, match_type, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            [
                $user_id,
                $court_name,
                $court_address,
                $match_date,
                $match_time,
                $max_spots,
                $max_spots,
                $message_body,
                $match_code,
                $cost_in,
                $match_type_in,
            ]
        );

        if (!$invite_id) {
            echo json_encode(['status' => 'error', 'message' => 'Could not create the invite. Please try again.']); exit;
        }

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
        $skippedNames = [];   // already invited to this match — no text, no charge (audit M3)

        foreach ($players as $player) {
            $phone = cleanPhoneNumber($player['phone'] ?? '');
            if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
                $failed++;
                $failedNames[] = $player['first_name'] . ' (invalid phone number)';
                continue;
            }

            // ── Duplicate guard (audit M3) ──
            // Re-sending to a player who already has a row for this invite
            // used to create a second invite_responses row AND charge a second
            // credit. One row per (invite, player); skip silently and free.
            $already = dbGetRow(
                "SELECT id, status FROM invite_responses WHERE invite_id = ? AND player_id = ? LIMIT 1",
                [$invite_id, $player['id']]
            );
            if ($already) {
                $skippedNames[] = $player['first_name'];
                continue;
            }

            $responseUrl = "https://playpbnow.com/invite.html?code={$invite['match_code']}&player_id={$player['id']}";

            // Keep SMS ultra-short to save Twilio costs. All details are on the web page.
            // "PlayPBNow:" prefix so the recipient knows who is texting (carrier
            // filters also treat identified senders more kindly).
            $shortDate = date('D M j', strtotime($invite['match_date']));
            $shortTime = date('g:iA', strtotime($invite['match_time']));
            $message = "PlayPBNow: {$player['first_name']}, pickleball {$shortDate} {$shortTime} @ {$invite['court_name']}. RSVP: {$responseUrl}";

            // ── Credit accounting: reserve → record → send → refund on failure (audit H1) ──
            // The previous order (send, then decrement WHERE credits > 0) could
            // send with no credit left under concurrency and logged a deduction
            // even when 0 rows changed. Now the credit is reserved atomically
            // FIRST; if the reservation fails the text is never sent.
            $reserved = false;
            if (!$is_admin) {
                $rsv = dbQuery("UPDATE sms_credits SET credits = credits - 1 WHERE user_id = ? AND credits >= 1", [$user_id]);
                $reserved = ($rsv && $rsv->affected_rows > 0);
                if ($rsv && method_exists($rsv, 'close')) { $rsv->close(); }
                if (!$reserved) {
                    $failed++;
                    $failedNames[] = $player['first_name'] . ' (no credits left)';
                    continue;
                }
                dbInsert(
                    "INSERT INTO sms_credit_log (user_id, change_type, credits_changed, reason) VALUES (?, 'deduct', -1, ?)",
                    [$user_id, "SMS invite to player {$player['id']} for invite {$invite_id}"]
                );
            }

            // Record the invite response BEFORE sending so the RSVP link in the
            // text always resolves to a row the moment it lands.
            $respId = dbInsert(
                "INSERT INTO invite_responses (invite_id, player_id, player_phone, player_name, status) VALUES (?, ?, ?, ?, 'pending')",
                [$invite_id, $player['id'], $phone, $player['first_name']]
            );

            try {
                // Twilio SDK signature is create(string $to, array $options) —
                // passing one combined array is a TypeError that (being an
                // Error, not an Exception) escaped the catch below and killed
                // the whole request with an empty 500.
                $client->messages->create($phone, ['from' => TWILIO_PHONE_NUMBER, 'body' => $message]);

                $sent++;
                $sentNames[] = $player['first_name'];
            } catch (\Throwable $e) {
                // \Throwable (not \Exception): TypeError/Error must also be
                // contained so one bad send can never blank the whole response.
                $failed++;
                $failedNames[] = $player['first_name'] . ' (' . pbnow_friendly_twilio_error($e->getMessage()) . ')';
                error_log("SMS send failed to player {$player['id']}: " . $e->getMessage());

                // Undo: the player never got a text, so drop the pending row
                // and hand the reserved credit back.
                if ($respId) {
                    dbQuery("DELETE FROM invite_responses WHERE id = ?", [$respId]);
                }
                if ($reserved) {
                    dbQuery("UPDATE sms_credits SET credits = credits + 1 WHERE user_id = ?", [$user_id]);
                    dbInsert(
                        "INSERT INTO sms_credit_log (user_id, change_type, credits_changed, reason) VALUES (?, 'refund', 1, ?)",
                        [$user_id, "Refund: SMS to player {$player['id']} for invite {$invite_id} failed"]
                    );
                }
            }
        }

        $newBalance = dbGetRow("SELECT credits FROM sms_credits WHERE user_id = ?", [$user_id]);

        echo json_encode([
            'status' => 'success',
            'sent_count' => $sent,
            'failed_count' => $failed,
            'sent_names' => $sentNames,
            'failed_names' => $failedNames,
            'skipped_names' => $skippedNames,
            'skipped_count' => count($skippedNames),
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

        $wasActive = ($invite['status'] === 'active');

        dbQuery("UPDATE match_invites SET status = 'cancelled' WHERE id = ?", [$invite_id]);

        // ── Tell the people who were counting on this match (audit H4) ──
        // Confirmed and wait-listed players have made plans around this slot;
        // a silent cancel leaves them showing up at an empty court. Text them
        // once (only on the active → cancelled transition, so a second cancel
        // click never re-texts). No credit charge: it is part of the original
        // invite, like waitlist promotions. Pending/interested/declined players
        // are not texted — they had not committed.
        $notified = 0;
        $notifiedNames = [];
        if ($wasActive) {
            $affected = dbGetAll(
                "SELECT ir.player_id, ir.status, pp.first_name, pp.phone
                 FROM invite_responses ir
                 JOIN pool_players pp ON pp.id = ir.player_id
                 WHERE ir.invite_id = ? AND ir.status IN ('confirmed', 'waitlisted')",
                [$invite_id]
            );
            if (!empty($affected)) {
                $shortDate = date('D M j', strtotime($invite['match_date']));
                $shortTime = date('g:iA', strtotime($invite['match_time']));
                $cancelMsg = "PlayPBNow: Cancelled: {$shortDate} {$shortTime} @ {$invite['court_name']}. Sorry — the organizer had to call this one off.";
                try {
                    $cancelClient = new \Twilio\Rest\Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
                } catch (\Throwable $e) {
                    $cancelClient = null;
                    error_log("Cancel notify: Twilio client init failed: " . $e->getMessage());
                }
                foreach ($affected as $p) {
                    $phone = cleanPhoneNumber($p['phone'] ?? '');
                    if (!$cancelClient || empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) { continue; }
                    // One cancel text per player per invite, ever — and a
                    // per-invite ceiling so a 40-spot invite can't be used to
                    // fire off an unbounded burst.
                    if (!sms_throttle("cancel_{$invite_id}_{$p['player_id']}", 1, 86400)) { continue; }
                    if (!sms_throttle("cancel_invite_{$invite_id}", 60, 3600)) { break; }
                    try {
                        $cancelClient->messages->create($phone, ['from' => TWILIO_PHONE_NUMBER, 'body' => $cancelMsg]);
                        $notified++;
                        $notifiedNames[] = $p['first_name'];
                    } catch (\Throwable $e) {
                        error_log("Cancel notify SMS failed for player {$p['player_id']}: " . $e->getMessage());
                    }
                }
                // Leave a trace in the invite chat so the organizer can see it went out.
                dbInsert(
                    "INSERT INTO invite_messages (invite_id, player_id, sender_name, message, is_system) VALUES (?, NULL, ?, ?, 1)",
                    [$invite_id, 'System', "Invite cancelled by organizer. {$notified} player" . ($notified === 1 ? '' : 's') . " notified by text."]
                );
            }
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Invite cancelled',
            'notified_count' => $notified,
            'notified_names' => $notifiedNames,
        ]);
        break;

    // ── iMessage flow: queue invites for the Mac sender daemon (admin only) ──

    case 'send_imessage':
        $user_id = $input['user_id'] ?? null;
        $invite_id = $input['invite_id'] ?? null;
        $player_ids = $input['player_ids'] ?? [];

        if (!$user_id || !$invite_id || empty($player_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'user_id, invite_id, and player_ids are required']); exit;
        }

        // Only admins can use iMessage flow
        $userRow = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$user_id]);
        if (!(bool)($userRow['is_admin'] ?? false)) {
            echo json_encode(['status' => 'error', 'message' => 'iMessage send is admin-only']); exit;
        }

        $invite = dbGetRow("SELECT * FROM match_invites WHERE id = ? AND user_id = ?", [$invite_id, $user_id]);
        if (!$invite) { echo json_encode(['status' => 'error', 'message' => 'Invite not found']); exit; }

        // Fetch players
        $placeholders = implode(',', array_fill(0, count($player_ids), '?'));
        $players = dbGetAll("SELECT id, first_name, phone FROM pool_players WHERE id IN ($placeholders)", $player_ids);

        if (empty($players)) {
            echo json_encode(['status' => 'error', 'message' => 'No valid players found']); exit;
        }

        // Auto-create text_queue table if needed. Guarded: a DDL statement on
        // every admin send takes a metadata lock for nothing once the table
        // exists, so probe first and only create when it is genuinely missing.
        $textQueueExists = (bool) dbGetRow("SHOW TABLES LIKE 'text_queue'");
        if (!$textQueueExists) dbQuery("CREATE TABLE IF NOT EXISTS text_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invite_id INT DEFAULT NULL,
            player_id INT DEFAULT NULL,
            phone VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            link_message TEXT DEFAULT NULL,
            status ENUM('pending','sent','failed') DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_invite (invite_id)
        )", []);

        $queued = 0;
        $queuedNames = [];
        $skippedNames = [];
        $alreadyNames = [];   // already invited to this match — not re-queued (audit M3)

        foreach ($players as $player) {
            $phone = cleanPhoneNumber($player['phone'] ?? '');
            if (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
                $skippedNames[] = $player['first_name'];
                continue;
            }

            // Duplicate guard: one invite_responses row per (invite, player).
            $already = dbGetRow(
                "SELECT id FROM invite_responses WHERE invite_id = ? AND player_id = ? LIMIT 1",
                [$invite_id, $player['id']]
            );
            if ($already) {
                $alreadyNames[] = $player['first_name'];
                continue;
            }

            $responseUrl = "https://playpbnow.com/invite.html?code={$invite['match_code']}&player_id={$player['id']}";
            $fullDate = date('l, F j', strtotime($invite['match_date']));
            $fullTime = date('g:i A', strtotime($invite['match_time']));
            $costDisplay = ($invite['cost'] && strtolower($invite['cost']) !== 'free') ? "Cost: {$invite['cost']}" : "No cost to play";
            $matchTypeDisplay = $invite['match_type'] ?: 'Open Play';
            $courtAddress = $invite['court_address'] ? "\n{$invite['court_address']}" : '';
            $customNote = trim($invite['message_body'] ?? '');

            // Shorten the RSVP link via Rebrandly on go.peoplestar.com with custom OG for link preview
            $shortUrl = rebrandlyShorten(
                $responseUrl,
                "You're Invited to Play Pickleball!",
                "{$matchTypeDisplay} — {$fullDate} at {$fullTime} @ {$invite['court_name']}",
                "https://peoplestar.com/PlayPBNow/og-invite.jpg"
            );
            $linkMessage = $shortUrl ?: $responseUrl;

            // Message 1: The invite details
            $message  = "Hi {$player['first_name']}! You're invited to play pickleball!\n\n";
            $message .= "{$matchTypeDisplay}\n";
            $message .= "{$fullDate} at {$fullTime}\n";
            $message .= "{$invite['court_name']}{$courtAddress}\n";
            $message .= "{$costDisplay}\n";
            if ($customNote) {
                $message .= "\n{$customNote}\n";
            }
            $message .= "\nWe'd love to have you on the court. Spots are limited, so don't wait!\n\n";
            $message .= "Tap the link in my next message to RSVP.\n\n";
            $message .= "— Chip McAllister, Play PB Now";

            // Message 2: Just the short link (iMessage will show OG preview card with logo)
            dbInsert(
                "INSERT INTO text_queue (invite_id, player_id, phone, message, link_message) VALUES (?, ?, ?, ?, ?)",
                [$invite_id, $player['id'], $phone, $message, $linkMessage]
            );

            // Status must be 'pending' — matching the Twilio path above. This
            // previously inserted 'sending', which is NOT a member of
            // invite_responses.status enum('pending','confirmed','interested',
            // 'declined','waitlisted'). Under MySQL strict mode that throws
            // "Data truncated for column 'status'", killing the whole endpoint
            // mid-response after the text was already queued; the browser then
            // failed to parse the truncated body and surfaced the opaque
            // "The string did not match the expected pattern."
            // (Before strict mode was enabled it silently wrote '' instead —
            // that is the origin of the empty-status rows from April.)
            // No daemon ever transitioned 'sending' to anything; the Mac sender
            // reads text_queue, not this table.
            dbInsert(
                "INSERT INTO invite_responses (invite_id, player_id, player_phone, player_name, status) VALUES (?, ?, ?, ?, 'pending')",
                [$invite_id, $player['id'], $phone, $player['first_name']]
            );

            $queued++;
            $queuedNames[] = $player['first_name'];
        }

        echo json_encode([
            'status' => 'success',
            'sent_count' => $queued,
            'failed_count' => count($skippedNames),
            'sent_names' => $queuedNames,
            'failed_names' => $skippedNames,
            'skipped_names' => $alreadyNames,
            'skipped_count' => count($alreadyNames),
            'credits_remaining' => 0,
            // Honest status for the UI (audit M2): these are QUEUED for the Mac
            // iMessage daemon, not yet delivered. The app titles the results
            // screen "Queued for delivery" when this flag is set.
            'queued' => true,
            'channel' => 'imessage',
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
