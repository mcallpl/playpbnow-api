<?php
// api/activate_subscription.php
// Activates or updates a user's subscription (called by RevenueCat webhook or manual)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? null;
$tier = $input['tier'] ?? 'pro';          // 'pro' or 'free'
$expiry_date = $input['expiry_date'] ?? null;
$action = $input['action'] ?? 'activate'; // 'activate', 'cancel', 'expire'

if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'user_id required']);
    exit;
}

$conn = getDBConnection();

if ($action === 'activate') {
    // Activate or renew subscription
    $stmt = $conn->prepare(
        "UPDATE users SET subscription_status = 'active', subscription_tier = ?, subscription_end_date = ? WHERE id = ?"
    );
    $stmt->bind_param('ssi', $tier, $expiry_date, $user_id);
    $stmt->execute();
    $stmt->close();

    // Unlock pro features
    $stmt2 = $conn->prepare(
        "INSERT INTO feature_access (user_id, can_create_matches, can_edit_matches, can_delete_matches, can_generate_reports, can_create_groups, max_groups, max_collab_sessions, max_players_per_group)
         VALUES (?, 1, 1, 1, 1, 1, 999, 999, 999)
         ON DUPLICATE KEY UPDATE
         can_edit_matches = 1, can_delete_matches = 1, can_generate_reports = 1, max_groups = 999, max_collab_sessions = 999, max_players_per_group = 999"
    );
    $stmt2->bind_param('i', $user_id);
    $stmt2->execute();
    $stmt2->close();

    $conn->close();
    echo json_encode(['status' => 'success', 'message' => 'Subscription activated']);

} elseif ($action === 'cancel' || $action === 'expire') {
    // Downgrade to free
    $stmt = $conn->prepare(
        "UPDATE users SET subscription_status = 'expired', subscription_tier = 'free' WHERE id = ?"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();

    // Revert to free tier limits
    $stmt2 = $conn->prepare(
        "UPDATE feature_access SET can_edit_matches = 0, can_delete_matches = 0, can_generate_reports = 0, max_groups = 2, max_collab_sessions = 1, max_players_per_group = 100 WHERE user_id = ?"
    );
    $stmt2->bind_param('i', $user_id);
    $stmt2->execute();
    $stmt2->close();

    $conn->close();
    echo json_encode(['status' => 'success', 'message' => 'Subscription cancelled']);

} else {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
}
?>
