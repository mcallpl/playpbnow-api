<?php
/**
 * SMS Credits API — Balance, Stripe checkout, transaction history.
 * Auth: identity is taken from the verified session token, never a client-
 * supplied user_id (which previously allowed reading anyone's balance/history
 * or crediting an arbitrary account).
 */

header('Access-Control-Allow-Origin: https://peoplestar.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

// Every action operates on the authenticated user only.
$user_id = pbnow_require_session_user();

// PlayPBNow's own least-privilege restricted key (Checkout Sessions only),
// isolated from the shared $vault_stripe_secret_key other apps use.
define('STRIPE_SECRET_KEY', $vault_stripe_playpbnow_key);

$CREDIT_PACKAGES = [
    '20'   => ['credits' => 20,   'price_cents' => 100],
    '120'  => ['credits' => 120,  'price_cents' => 500],
    '300'  => ['credits' => 300,  'price_cents' => 1000],
    '600'  => ['credits' => 600,  'price_cents' => 1800],
    '1200' => ['credits' => 1200, 'price_cents' => 3300],
    '2400' => ['credits' => 2400, 'price_cents' => 6000],
    '5000' => ['credits' => 5000, 'price_cents' => 12000],
];

function stripeRequest(string $endpoint, array $data = []): array {
    $ch = curl_init('https://api.stripe.com/v1/' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: ['error' => ['message' => 'Invalid Stripe response']];
}

switch ($action) {

    case 'balance':
        $row = dbGetRow("SELECT credits FROM sms_credits WHERE user_id = ?", [$user_id]);
        echo json_encode(['status' => 'success', 'credits' => (int)($row['credits'] ?? 0)]);
        break;

    case 'checkout':
        $credits = (string)($input['credits'] ?? '');
        if (!$credits || !isset($CREDIT_PACKAGES[$credits])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid package']); exit;
        }
        $pkg = $CREDIT_PACKAGES[$credits];

        // Price/amount is fixed server-side from the package table — the client
        // cannot influence the charge amount.
        $session = stripeRequest('checkout/sessions', [
            'payment_method_types[0]' => 'card',
            'mode' => 'payment',
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => $pkg['price_cents'],
            'line_items[0][price_data][product_data][name]' => "{$pkg['credits']} SMS Credits",
            'line_items[0][price_data][product_data][description]' => 'PlayPBNow SMS invite credits',
            'line_items[0][quantity]' => 1,
            'metadata[app]' => 'playpbnow',
            'metadata[user_id]' => $user_id,
            'metadata[credits]' => $pkg['credits'],
            // Absolute URLs (Stripe rejects relative ones).
            'success_url' => 'https://peoplestar.com/PlayPBNow/app.html?credits=success',
            'cancel_url' => 'https://peoplestar.com/PlayPBNow/app.html?credits=cancel',
        ]);

        if (isset($session['error'])) {
            echo json_encode(['status' => 'error', 'message' => $session['error']['message'] ?? 'Stripe error']); exit;
        }
        echo json_encode(['status' => 'success', 'checkout_url' => $session['url'] ?? null]);
        break;

    case 'history':
        $history = dbGetAll(
            "SELECT change_type, credits_changed, reason, created_at FROM sms_credit_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
            [$user_id]
        );
        $row = dbGetRow("SELECT credits FROM sms_credits WHERE user_id = ?", [$user_id]);
        echo json_encode(['status' => 'success', 'credits' => (int)($row['credits'] ?? 0), 'history' => $history]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
