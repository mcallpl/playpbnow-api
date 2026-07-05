<?php
/**
 * delete_account.php — permanent in-app account deletion (Apple 5.1.1(v)).
 *
 * Auth: valid session token required, and it must belong to the user_id being
 * deleted — you can only delete YOURSELF. If the account has a password, it
 * must be re-entered (the app prompts for it); accounts created without a
 * password (phone-claim flow) are authorized by the session alone.
 *
 * Deletes, in one transaction: sessions, saved match sessions + their matches,
 * groups + memberships, players the user created, invites + responses, SMS
 * credits + logs, feature access, and the user row. Universal identities are
 * UNCLAIMED (claimed_by_user_id -> NULL), never deleted — the shared identity
 * table is append-only and holds no raw personal data (peppered hash only).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/require_admin.php';

$session_uid = pbnow_require_session_user(); // 401 if no/invalid token

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$user_id  = (int)($input['user_id'] ?? 0);
$password = (string)($input['password'] ?? '');

if (!$user_id || $user_id !== $session_uid) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You can only delete your own account.']);
    exit;
}

$user = dbGetRow("SELECT id, password_hash FROM users WHERE id = ?", [$user_id]);
if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'Account not found.']);
    exit;
}

// Re-authenticate with password when the account has one.
if (!empty($user['password_hash'])) {
    if ($password === '' || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['status' => 'error', 'message' => 'Incorrect password.']);
        exit;
    }
}

$conn = getDBConnection();
$conn->begin_transaction();

try {
    $run = function (string $sql) use ($conn, $user_id) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
    };

    // Unclaim universal identities (append-only table — never deleted here).
    $run("UPDATE player_identities SET claimed_by_user_id = NULL WHERE claimed_by_user_id = ?");

    // Invite system: responses to their invites, then the invites.
    $run("DELETE ir FROM invite_responses ir JOIN match_invites mi ON ir.invite_id = mi.id WHERE mi.user_id = ?");
    $run("DELETE FROM match_invites WHERE user_id = ?");

    // SMS credits.
    $run("DELETE FROM sms_credit_log WHERE user_id = ?");
    $run("DELETE FROM sms_credits WHERE user_id = ?");

    // Match history: matches inside their groups, then their saved sessions.
    $run("DELETE m FROM matches m JOIN `groups` g ON m.group_id = g.id WHERE g.owner_user_id = ?");
    $run("DELETE FROM sessions WHERE user_id = ?");

    // Live collab sessions they created: end them.
    $run("UPDATE collab_sessions SET status = 'expired' WHERE creator_user_id = ?");

    // Roster: memberships touching their players OR their groups, then players
    // they created, then their groups.
    $run("DELETE pgm FROM player_group_memberships pgm JOIN players p ON pgm.player_id = p.id WHERE p.created_by_user_id = ?");
    $run("DELETE pgm FROM player_group_memberships pgm JOIN `groups` g ON pgm.group_id = g.id WHERE g.owner_user_id = ?");
    $run("DELETE FROM players WHERE created_by_user_id = ?");
    $run("DELETE FROM `groups` WHERE owner_user_id = ?");

    // Access + auth.
    $run("DELETE FROM feature_access WHERE user_id = ?");
    $run("DELETE FROM user_sessions WHERE user_id = ?");
    $run("DELETE FROM users WHERE id = ?");

    $conn->commit();
    error_log("🗑️ Account $user_id permanently deleted (self-service).");
    echo json_encode(['status' => 'success', 'message' => 'Your account has been permanently deleted.']);
} catch (Throwable $e) {
    $conn->rollback();
    error_log("delete_account error for user $user_id: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Deletion failed. Please try again or contact support.']);
}
