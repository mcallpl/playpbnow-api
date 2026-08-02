<?php
/**
 * PlayPBNow — Text Queue Poller
 *
 * The admin iMessage flow (invite_api.php, action=send_imessage) writes rows to
 * `text_queue` and returns success. Until now NOTHING drained that table, so
 * every admin-sent invite sat at status='pending' forever while the app said
 * "Invites Sent". This endpoint is the missing half: the Mac sender daemon
 * (playpbnow-sender.py) polls it, sends via iMessage, and reports back.
 *
 * Mirrors the working ShowPoppy poller (/var/www/html/showpoppy/api/poll-queue.php).
 *
 * Machine-to-machine: the Mac daemon has no browser session, so this uses a
 * shared vault token rather than require_admin(). Token lives in
 * $vault_playpbnow_sender_token (vault/secrets.php, not in git).
 *
 *   GET  poll_text_queue.php?action=fetch   -> { items: [ {id, phone, message, link_message} ] }
 *   POST poll_text_queue.php?action=update  -> { id, status: sent|failed, error_message }
 */

header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

// db_config.php pulls in vault/secrets.php when present.
$expected = $vault_playpbnow_sender_token ?? '';
$supplied = $_SERVER['HTTP_X_PBNOW_TOKEN'] ?? ($_GET['token'] ?? '');

if (empty($expected) || !hash_equals((string) $expected, (string) $supplied)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Fetch pending messages ───────────────────────────────────────────────────
if ($action === 'fetch') {
    // LIMIT keeps a backlog from being blasted out in one burst if the daemon
    // has been down; it drains a batch per poll instead.
    $items = dbGetAll(
        "SELECT id, invite_id, player_id, phone, message, link_message
         FROM text_queue
         WHERE status = 'pending'
         ORDER BY created_at ASC, id ASC
         LIMIT 10",
        []
    );
    echo json_encode(['status' => 'success', 'items' => $items]);
    exit;
}

// ── Report the result of a send attempt ──────────────────────────────────────
if ($action === 'update') {
    $input    = json_decode(file_get_contents('php://input'), true);
    $id       = (int) ($input['id'] ?? 0);
    $status   = $input['status'] ?? '';
    $errorMsg = $input['error_message'] ?? '';

    if (!$id || !in_array($status, ['sent', 'failed'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid params']);
        exit;
    }

    if ($status === 'sent') {
        dbQuery("UPDATE text_queue SET status = 'sent', sent_at = NOW() WHERE id = ?", [$id]);

        // Keep the RSVP side in step. invite_responses rows are created as
        // 'pending' at queue time; nothing else moves them, so this is
        // currently a no-op placeholder kept explicit for when a distinct
        // delivered state is added. Deliberately NOT writing a status value
        // that is outside the enum — that mistake is what made the whole
        // send_imessage request fatal in the first place.
    } else {
        dbQuery("UPDATE text_queue SET status = 'failed', error_message = ? WHERE id = ?", [$errorMsg, $id]);
    }

    echo json_encode(['status' => 'success']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
