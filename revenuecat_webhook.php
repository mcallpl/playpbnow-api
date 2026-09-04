<?php
// RevenueCat Webhook Handler
// Receives subscription events and updates user subscription status
header('Content-Type: application/json');

// ── Webhook authorization (hardened during the UAT pass, 2026-09-04) ──────
//
// This check used to run BEFORE db_config.php was loaded, and the constant it
// looked for was never defined anywhere, so $expectedToken was always '' and
// the `if` never fired. The endpoint accepted any anonymous POST, and an
// INITIAL_PURCHASE event naming any users.id granted that account a
// subscription with a NULL end date — which no expiry check ever revokes.
//
// It is now checked properly, but deliberately NOT fail-closed yet: no
// RevenueCat secret exists in the vault today and the dashboard sends no
// Authorization header, so refusing unsigned calls would silently stop every
// real purchase from activating. Until the secret is in place the call is
// still honoured and every request is logged loudly.
//
// TO SECURE IT (two steps, either order):
//   1. Add $vault_revenuecat_webhook_secret = '<random string>'; to
//      /var/www/html/vault/secrets.php
//   2. In the RevenueCat dashboard set the webhook's Authorization header to
//      "Bearer <that same string>"
// Once step 1 lands, unsigned calls are rejected automatically.
require_once __DIR__ . '/db_config.php';

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

$expectedToken = '';
if (defined('REVENUECAT_WEBHOOK_SECRET')) {
    $expectedToken = (string)REVENUECAT_WEBHOOK_SECRET;
}
if ($expectedToken === '') {
    foreach (['/var/www/html/vault/secrets.php', __DIR__ . '/../vault/secrets.php'] as $vaultPath) {
        if (is_readable($vaultPath)) {
            include_once $vaultPath;
            break;
        }
    }
    if (isset($vault_revenuecat_webhook_secret) && $vault_revenuecat_webhook_secret !== '') {
        $expectedToken = (string)$vault_revenuecat_webhook_secret;
    }
}

if ($expectedToken !== '') {
    if (!hash_equals('Bearer ' . $expectedToken, $authHeader)) {
        error_log('revenuecat_webhook: REJECTED request with bad/missing Authorization header');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
} else {
    error_log('revenuecat_webhook: NO $vault_revenuecat_webhook_secret in vault — this endpoint is currently UNAUTHENTICATED. See the header comment in revenuecat_webhook.php.');
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || !isset($input['event'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook payload']);
    exit;
}

$event = $input['event'];
$eventType = $event['type'] ?? '';
$appUserId = $event['app_user_id'] ?? null;
$expirationDate = $event['expiration_at_ms'] ?? null;
$productId = $event['product_id'] ?? '';

if (!$appUserId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing app_user_id']);
    exit;
}

// Convert expiration timestamp to datetime
$expiryDate = null;
if ($expirationDate) {
    $expiryDate = date('Y-m-d H:i:s', $expirationDate / 1000);
}

// Map RevenueCat event types to actions
$activateEvents = [
    'INITIAL_PURCHASE',
    'RENEWAL',
    'UNCANCELLATION',
    'NON_RENEWING_PURCHASE',
    'PRODUCT_CHANGE',
];

$deactivateEvents = [
    'CANCELLATION',
    'EXPIRATION',
    'BILLING_ISSUE',
    'SUBSCRIPTION_PAUSED',
];

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

if (in_array($eventType, $activateEvents)) {
    // Activate subscription
    $stmt = $conn->prepare(
        "UPDATE users SET subscription_status = 'active', subscription_tier = 'pro', subscription_end_date = ? WHERE id = ?"
    );
    $stmt->bind_param('ss', $expiryDate, $appUserId);
    $stmt->execute();
    $stmt->close();

    // Unlock pro features
    $stmt2 = $conn->prepare(
        "INSERT INTO feature_access (user_id, can_create_matches, can_edit_matches, can_delete_matches, can_generate_reports, can_create_groups, max_groups, max_collab_sessions, max_players_per_group)
         VALUES (?, 1, 1, 1, 1, 1, 999, 999, 999)
         ON DUPLICATE KEY UPDATE
         can_edit_matches = 1, can_delete_matches = 1, can_generate_reports = 1, max_groups = 999, max_collab_sessions = 999, max_players_per_group = 999"
    );
    $stmt2->bind_param('s', $appUserId);
    $stmt2->execute();
    $stmt2->close();

    $conn->close();
    echo json_encode(['status' => 'success', 'action' => 'activated', 'event' => $eventType]);

} elseif (in_array($eventType, $deactivateEvents)) {
    // Deactivate / downgrade to free
    $stmt = $conn->prepare(
        "UPDATE users SET subscription_status = 'expired', subscription_tier = 'free' WHERE id = ?"
    );
    $stmt->bind_param('s', $appUserId);
    $stmt->execute();
    $stmt->close();

    // Revert to free tier limits
    $stmt2 = $conn->prepare(
        "UPDATE feature_access SET can_edit_matches = 0, can_delete_matches = 0, can_generate_reports = 0, max_groups = 2, max_collab_sessions = 1, max_players_per_group = 100 WHERE user_id = ?"
    );
    $stmt2->bind_param('s', $appUserId);
    $stmt2->execute();
    $stmt2->close();

    $conn->close();
    echo json_encode(['status' => 'success', 'action' => 'deactivated', 'event' => $eventType]);

} else {
    // Unhandled event type — acknowledge receipt
    $conn->close();
    echo json_encode(['status' => 'success', 'action' => 'ignored', 'event' => $eventType]);
}
