<?php
// api/check_subscription.php
// Returns the user's current subscription tier, features, and trial info
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

// Default free tier features (used as fallback if DB queries fail)
$default_features = [
    'can_create_matches' => 1,
    'can_edit_matches' => 0,
    'can_delete_matches' => 0,
    'can_generate_reports' => 0,
    'can_create_groups' => 1,
    'max_groups' => 2,
    'max_collab_sessions' => 1,
    'max_players_per_group' => 100,
];

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $input['user_id'] ?? null;
    $device_id = $input['device_id'] ?? null;

    if (!$user_id && !$device_id) {
        echo json_encode(['status' => 'error', 'message' => 'user_id or device_id required']);
        exit;
    }

    // Look up user
    if ($user_id) {
        $user = dbGetRow("SELECT * FROM users WHERE id = ?", [$user_id]);
    } else {
        $user = dbGetRow("SELECT * FROM users WHERE device_id = ?", [$device_id]);
    }

    if (!$user) {
        // User not found — return free tier defaults (don't crash)
        echo json_encode([
            'status' => 'success',
            'subscription' => [
                'tier' => 'free',
                'subscriptionStatus' => 'none',
                'expiryDate' => null,
                'trialStartDate' => null,
                'trialDaysRemaining' => 0,
                'trialExpired' => false,
                'isPro' => false,
            ],
            'features' => [
                'canGenerateCleanReports' => false,
                'canEditMatches' => false,
                'canDeleteMatches' => false,
                'maxGroups' => 2,
                'maxCollabSessions' => 1,
                'maxPlayersPerGroup' => 100,
            ]
        ]);
        exit;
    }

    $uid = $user['id'];
    $status = $user['subscription_status'] ?? 'none';
    $tier = $user['subscription_tier'] ?? 'free';
    $end_date = $user['subscription_end_date'] ?? null;
    $trial_start = $user['trial_start_date'] ?? null;

    // Check if trial or subscription has expired
    $now = time();
    $is_expired = false;
    $trial_days_remaining = 0;

    if ($end_date && strtotime($end_date) < $now) {
        if ($status === 'trial' || $status === 'active') {
            $is_expired = true;
            try {
                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE users SET subscription_status = 'expired', subscription_tier = 'free' WHERE id = ?");
                if ($stmt) { $stmt->bind_param('i', $uid); $stmt->execute(); $stmt->close(); }

                // Try to downgrade feature_access (table may not exist yet)
                $stmt2 = $conn->prepare("UPDATE feature_access SET can_edit_matches = 0, can_delete_matches = 0, can_generate_reports = 0, max_groups = 2, max_collab_sessions = 1 WHERE user_id = ?");
                if ($stmt2) { $stmt2->bind_param('i', $uid); $stmt2->execute(); $stmt2->close(); }

                $conn->close();
            } catch (Exception $e) {
                error_log("check_subscription: downgrade error: " . $e->getMessage());
            }
            $status = 'expired';
            $tier = 'free';
        }
    }

    // Calculate trial days remaining
    if ($status === 'trial' && $end_date) {
        $remaining_seconds = strtotime($end_date) - $now;
        $trial_days_remaining = max(0, ceil($remaining_seconds / 86400));
    }

    // Get feature access (safely — table may not exist)
    $features = $default_features;
    try {
        $fa = dbGetRow("SELECT * FROM feature_access WHERE user_id = ?", [$uid]);
        if ($fa) {
            $features = $fa;
        }
    } catch (Exception $e) {
        error_log("check_subscription: feature_access query failed (table may not exist): " . $e->getMessage());
        // Keep default features
    }

    // Determine if user has clean report access (pro or active trial)
    $can_clean_reports = ($tier === 'pro') || ($status === 'trial' && !$is_expired) || ($status === 'active' && !$is_expired);

    echo json_encode([
        'status' => 'success',
        'subscription' => [
            'tier' => $tier,
            'subscriptionStatus' => $status,
            'expiryDate' => $end_date,
            'trialStartDate' => $trial_start,
            'trialDaysRemaining' => $trial_days_remaining,
            'trialExpired' => ($status === 'expired' && $trial_start !== null),
            'isPro' => $can_clean_reports,
        ],
        'features' => [
            'canGenerateCleanReports' => $can_clean_reports,
            'canEditMatches' => (bool)($features['can_edit_matches'] ?? false),
            'canDeleteMatches' => (bool)($features['can_delete_matches'] ?? false),
            'maxGroups' => (int)($features['max_groups'] ?? 2),
            'maxCollabSessions' => (int)($features['max_collab_sessions'] ?? 1),
            'maxPlayersPerGroup' => (int)($features['max_players_per_group'] ?? 100),
        ]
    ]);

} catch (Exception $e) {
    // Catch-all: if ANYTHING goes wrong, return valid JSON with free-tier defaults
    error_log("check_subscription FATAL: " . $e->getMessage());
    echo json_encode([
        'status' => 'success',
        'subscription' => [
            'tier' => 'free',
            'subscriptionStatus' => 'none',
            'expiryDate' => null,
            'trialStartDate' => null,
            'trialDaysRemaining' => 0,
            'trialExpired' => false,
            'isPro' => false,
        ],
        'features' => [
            'canGenerateCleanReports' => false,
            'canEditMatches' => false,
            'canDeleteMatches' => false,
            'maxGroups' => 2,
            'maxCollabSessions' => 1,
            'maxPlayersPerGroup' => 100,
        ]
    ]);
}
?>
