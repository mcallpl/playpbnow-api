<?php
/**
 * Stripe Webhook Handler — Processes payment events for SMS credits and subscriptions.
 *
 * Security: the signature is now REQUIRED and verified (fail-closed). Previously
 * verification only ran "if a signature header was present," so an unsigned POST
 * skipped it entirely and anyone could forge free credits with a curl command.
 * Also enforces a 5-minute timestamp tolerance (replay protection) and credits
 * atomically inside a transaction guarded by the unique stripe_session_id.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/db_config.php';

define('STRIPE_WEBHOOK_SECRET', $vault_stripe_webhook_secrets['PlayPBNow'] ?? '');

$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Fail closed: the endpoint must be configured and the request must be signed.
if (!STRIPE_WEBHOOK_SECRET) {
    http_response_code(500);
    echo json_encode(['error' => 'Webhook not configured']);
    exit;
}
if (!$sig_header) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing signature']);
    exit;
}

$elements = [];
foreach (explode(',', $sig_header) as $element) {
    $parts = explode('=', $element, 2);
    if (count($parts) === 2) {
        $elements[$parts[0]] = $parts[1];
    }
}
$timestamp = $elements['t'] ?? '';
$signature = $elements['v1'] ?? '';

// Replay protection: reject signatures older/newer than 5 minutes.
if (!$timestamp || abs(time() - (int)$timestamp) > 300) {
    http_response_code(400);
    echo json_encode(['error' => 'Stale or missing timestamp']);
    exit;
}

$expected = hash_hmac('sha256', $timestamp . '.' . $payload, STRIPE_WEBHOOK_SECRET);
if (!$signature || !hash_equals($expected, $signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$type = $event['type'];
$object = $event['data']['object'] ?? [];

error_log("Stripe webhook: $type");

switch ($type) {
    case 'checkout.session.completed':
        $session_mode = $object['mode'] ?? '';
        $user_id = $object['metadata']['user_id'] ?? ($object['client_reference_id'] ?? null);

        // ── SMS Credit purchase ──
        if ($session_mode === 'payment' && isset($object['metadata']['credits'])) {
            $stripe_session_id = $object['id'] ?? null;
            $credits = (int)($object['metadata']['credits'] ?? 0);
            $amount_total = $object['amount_total'] ?? 0;

            if (!$stripe_session_id || !$user_id || $credits <= 0) {
                error_log("Stripe credits webhook: missing metadata");
                break;
            }

            // Atomic credit + log, idempotent on stripe_session_id (which has a
            // UNIQUE index). Concurrent Stripe retries can't double-credit: the
            // second log INSERT hits the unique constraint and rolls back.
            $conn = getDBConnection();
            $conn->begin_transaction();
            try {
                $dupe = dbGetRow("SELECT id FROM sms_credit_log WHERE stripe_session_id = ?", [$stripe_session_id]);
                if ($dupe) {
                    $conn->rollback();
                    error_log("Stripe: already processed session $stripe_session_id");
                    break;
                }

                $dollars = number_format($amount_total / 100, 2);
                // Insert the log row FIRST so the unique stripe_session_id acts as
                // the idempotency lock before any credit is granted.
                $logStmt = $conn->prepare(
                    "INSERT INTO sms_credit_log (user_id, change_type, credits_changed, reason, stripe_session_id) VALUES (?, 'purchase', ?, ?, ?)"
                );
                $reason = "Purchased {$credits} credits (\${$dollars})";
                $logStmt->bind_param('iiss', $user_id, $credits, $reason, $stripe_session_id);
                $logStmt->execute();
                $logStmt->close();

                $upsert = $conn->prepare(
                    "INSERT INTO sms_credits (user_id, credits) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE credits = credits + VALUES(credits)"
                );
                $upsert->bind_param('ii', $user_id, $credits);
                $upsert->execute();
                $upsert->close();

                $conn->commit();
                error_log("Stripe: added $credits SMS credits to user $user_id");
            } catch (Throwable $e) {
                $conn->rollback();
                error_log("Stripe credits webhook failed: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'processing failed']);
                exit;
            }
            break;
        }

        // ── Subscription checkout ──
        $stripe_customer_id = $object['customer'] ?? null;
        $stripe_subscription_id = $object['subscription'] ?? null;

        if ($user_id && $stripe_subscription_id) {
            dbQuery("UPDATE users SET stripe_customer_id = ?, stripe_subscription_id = ? WHERE id = ?", [$stripe_customer_id, $stripe_subscription_id, $user_id]);

            $period_end = date('Y-m-d H:i:s', strtotime('+1 month'));
            dbQuery("UPDATE users SET subscription_status = 'active', subscription_tier = 'pro', subscription_end_date = ? WHERE id = ?", [$period_end, $user_id]);
            dbQuery(
                "INSERT INTO feature_access (user_id, can_create_matches, can_edit_matches, can_delete_matches, can_generate_reports, can_create_groups, max_groups, max_collab_sessions, max_players_per_group)
                 VALUES (?, 1, 1, 1, 1, 1, 999, 999, 999)
                 ON DUPLICATE KEY UPDATE can_edit_matches = 1, can_delete_matches = 1, can_generate_reports = 1, max_groups = 999, max_collab_sessions = 999, max_players_per_group = 999",
                [$user_id]
            );
        }
        break;

    case 'customer.subscription.deleted':
    case 'customer.subscription.paused':
        $stripe_subscription_id = $object['id'] ?? null;
        if ($stripe_subscription_id) {
            $user = dbGetRow("SELECT id FROM users WHERE stripe_subscription_id = ?", [$stripe_subscription_id]);
            if ($user) {
                dbQuery("UPDATE users SET subscription_status = 'expired', subscription_tier = 'free' WHERE id = ?", [$user['id']]);
            }
        }
        break;
}

echo json_encode(['status' => 'success', 'event' => $type]);
