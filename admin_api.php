<?php
/**
 * Admin Dashboard API — Stats, management, and analytics
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

function requireAdmin($user_id) {
    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'user_id is required']); exit;
    }
    $userRow = dbGetRow("SELECT is_admin FROM users WHERE id = ?", [$user_id]);
    if (!$userRow || !$userRow['is_admin']) {
        echo json_encode(['status' => 'error', 'message' => 'Admin access required']); exit;
    }
    return true;
}

switch ($action) {

    // ===== DASHBOARD OVERVIEW =====
    case 'overview':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        // Users
        $totalUsers = dbGetRow("SELECT COUNT(*) as cnt FROM users")['cnt'] ?? 0;
        $activeTrials = dbGetRow("SELECT COUNT(*) as cnt FROM users WHERE subscription_status = 'trial' AND (subscription_end_date IS NULL OR subscription_end_date > NOW())")['cnt'] ?? 0;
        $activeSubscriptions = dbGetRow("SELECT COUNT(*) as cnt FROM users WHERE subscription_status = 'active'")['cnt'] ?? 0;
        $expiredUsers = dbGetRow("SELECT COUNT(*) as cnt FROM users WHERE subscription_status IN ('expired','none') OR (subscription_end_date IS NOT NULL AND subscription_end_date <= NOW())")['cnt'] ?? 0;

        // Pool players
        $totalPoolPlayers = dbGetRow("SELECT COUNT(*) as cnt FROM pool_players")['cnt'] ?? 0;
        $migratedPlayers = dbGetRow("SELECT COUNT(*) as cnt FROM pool_players WHERE migrated_from_pbpm = 1")['cnt'] ?? 0;
        $newSignups = dbGetRow("SELECT COUNT(*) as cnt FROM pool_players WHERE migrated_from_pbpm = 0 OR migrated_from_pbpm IS NULL")['cnt'] ?? 0;

        // Scoring players
        $totalPlayers = dbGetRow("SELECT COUNT(*) as cnt FROM players")['cnt'] ?? 0;
        $totalGroups = dbGetRow("SELECT COUNT(*) as cnt FROM `groups`")['cnt'] ?? 0;

        // Matches & Sessions
        $totalMatches = dbGetRow("SELECT COUNT(*) as cnt FROM matches")['cnt'] ?? 0;
        $totalSessions = dbGetRow("SELECT COUNT(*) as cnt FROM sessions")['cnt'] ?? 0;
        $activeSessions = dbGetRow("SELECT COUNT(*) as cnt FROM collab_sessions WHERE status = 'active'")['cnt'] ?? 0;

        // Invites
        $totalInvites = dbGetRow("SELECT COUNT(*) as cnt FROM match_invites")['cnt'] ?? 0;
        $activeInvites = dbGetRow("SELECT COUNT(*) as cnt FROM match_invites WHERE status = 'active'")['cnt'] ?? 0;
        $totalResponses = dbGetRow("SELECT COUNT(*) as cnt FROM invite_responses")['cnt'] ?? 0;
        $confirmedResponses = dbGetRow("SELECT COUNT(*) as cnt FROM invite_responses WHERE status = 'confirmed'")['cnt'] ?? 0;

        // Broadcasts
        $totalBroadcasts = dbGetRow("SELECT COUNT(*) as cnt FROM broadcasts")['cnt'] ?? 0;
        $totalBroadcastsSent = dbGetRow("SELECT COALESCE(SUM(sent_count),0) as cnt FROM broadcasts WHERE status = 'sent'")['cnt'] ?? 0;

        // SMS Credits
        $totalCreditsInSystem = dbGetRow("SELECT COALESCE(SUM(credits),0) as cnt FROM sms_credits")['cnt'] ?? 0;
        $totalPurchased = dbGetRow("SELECT COALESCE(SUM(credits_changed),0) as cnt FROM sms_credit_log WHERE change_type = 'purchase'")['cnt'] ?? 0;
        $totalDeducted = dbGetRow("SELECT COALESCE(ABS(SUM(credits_changed)),0) as cnt FROM sms_credit_log WHERE change_type = 'deduct'")['cnt'] ?? 0;

        // Courts
        $totalCourts = dbGetRow("SELECT COUNT(*) as cnt FROM courts")['cnt'] ?? 0;

        echo json_encode([
            'status' => 'success',
            'overview' => [
                'users' => [
                    'total' => (int)$totalUsers,
                    'active_trials' => (int)$activeTrials,
                    'active_subscriptions' => (int)$activeSubscriptions,
                    'expired' => (int)$expiredUsers,
                ],
                'pool_players' => [
                    'total' => (int)$totalPoolPlayers,
                    'migrated' => (int)$migratedPlayers,
                    'new_signups' => (int)$newSignups,
                ],
                'scoring' => [
                    'players' => (int)$totalPlayers,
                    'groups' => (int)$totalGroups,
                    'matches' => (int)$totalMatches,
                    'sessions' => (int)$totalSessions,
                    'active_collab' => (int)$activeSessions,
                ],
                'invites' => [
                    'total' => (int)$totalInvites,
                    'active' => (int)$activeInvites,
                    'responses' => (int)$totalResponses,
                    'confirmed' => (int)$confirmedResponses,
                ],
                'broadcasts' => [
                    'total' => (int)$totalBroadcasts,
                    'sms_sent' => (int)$totalBroadcastsSent,
                ],
                'sms_credits' => [
                    'in_system' => (int)$totalCreditsInSystem,
                    'purchased' => (int)$totalPurchased,
                    'used' => (int)$totalDeducted,
                ],
                'courts' => (int)$totalCourts,
            ],
        ]);
        break;

    // ===== RECENT ACTIVITY =====
    case 'activity':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        // Recent user signups
        $recentUsers = dbGetAll(
            "SELECT id, phone, first_name, last_name, subscription_status, subscription_tier, created_at, last_login_at
             FROM users ORDER BY created_at DESC LIMIT 20"
        );

        // Recent sessions (beacons / live scoring)
        $recentSessions = dbGetAll(
            "SELECT s.id, s.batch_id, s.share_code, s.title, s.session_date, s.player_count, s.created_at,
                    u.first_name, u.last_name
             FROM sessions s
             LEFT JOIN users u ON u.id = s.user_id
             ORDER BY s.created_at DESC LIMIT 20"
        );

        // Active collab sessions (live beacons)
        $activeCollabs = dbGetAll(
            "SELECT cs.id, cs.batch_id, cs.group_name, cs.share_code, cs.status, cs.created_at, cs.expires_at,
                    (SELECT COUNT(*) FROM collab_participants cp WHERE cp.session_id = cs.id) as participants
             FROM collab_sessions cs
             WHERE cs.status = 'active'
             ORDER BY cs.created_at DESC LIMIT 20"
        );

        // Recent invites
        $recentInvites = dbGetAll(
            "SELECT mi.id, mi.court_name, mi.match_date, mi.match_time, mi.match_code, mi.status, mi.max_spots, mi.spots_left, mi.created_at,
                    u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM invite_responses ir WHERE ir.invite_id = mi.id) as response_count,
                    (SELECT COUNT(*) FROM invite_responses ir WHERE ir.invite_id = mi.id AND ir.status = 'confirmed') as confirmed_count
             FROM match_invites mi
             LEFT JOIN users u ON u.id = mi.user_id
             ORDER BY mi.created_at DESC LIMIT 20"
        );

        // Recent pool player signups
        $recentPoolSignups = dbGetAll(
            "SELECT id, first_name, last_name, phone, play_level, cities_to_play, created_at
             FROM pool_players
             WHERE migrated_from_pbpm = 0 OR migrated_from_pbpm IS NULL
             ORDER BY created_at DESC LIMIT 20"
        );

        // Recent SMS credit purchases
        $recentPurchases = dbGetAll(
            "SELECT cl.id, cl.user_id, cl.change_type, cl.credits_changed, cl.reason, cl.created_at,
                    u.first_name, u.last_name
             FROM sms_credit_log cl
             LEFT JOIN users u ON u.id = cl.user_id
             WHERE cl.change_type = 'purchase'
             ORDER BY cl.created_at DESC LIMIT 20"
        );

        echo json_encode([
            'status' => 'success',
            'activity' => [
                'recent_users' => $recentUsers,
                'recent_sessions' => $recentSessions,
                'active_collabs' => $activeCollabs,
                'recent_invites' => $recentInvites,
                'recent_pool_signups' => $recentPoolSignups,
                'recent_purchases' => $recentPurchases,
            ],
        ]);
        break;

    // ===== USER MANAGEMENT =====
    case 'users':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);
        $page = (int)($input['page'] ?? 1);
        $per_page = (int)($input['per_page'] ?? 50);
        $search = trim($input['search'] ?? '');
        $offset = ($page - 1) * $per_page;

        if (!empty($search)) {
            $like = "%{$search}%";
            $users = dbGetAll(
                "SELECT id, phone, first_name, last_name, email, subscription_status, subscription_tier,
                        subscription_end_date, trial_start_date, is_admin, last_login_at, created_at
                 FROM users
                 WHERE first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ?
                 ORDER BY created_at DESC LIMIT ? OFFSET ?",
                [$like, $like, $like, $like, $per_page, $offset]
            );
        } else {
            $users = dbGetAll(
                "SELECT id, phone, first_name, last_name, email, subscription_status, subscription_tier,
                        subscription_end_date, trial_start_date, is_admin, last_login_at, created_at
                 FROM users
                 ORDER BY created_at DESC LIMIT ? OFFSET ?",
                [$per_page, $offset]
            );
        }

        echo json_encode(['status' => 'success', 'users' => $users]);
        break;

    // ===== PLAYER LEADERBOARD =====
    case 'leaderboard':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);
        $group_id = $input['group_id'] ?? null;

        if ($group_id) {
            $players = dbGetAll(
                "SELECT p.id, p.first_name, p.last_name, p.wins, p.losses, p.win_pct, p.dupr_rating, p.gender,
                        g.name as group_name
                 FROM players p
                 LEFT JOIN player_group_memberships pgm ON pgm.player_id = p.id
                 LEFT JOIN `groups` g ON g.id = pgm.group_id
                 WHERE pgm.group_id = ?
                 ORDER BY p.win_pct DESC, p.wins DESC LIMIT 100",
                [$group_id]
            );
        } else {
            $players = dbGetAll(
                "SELECT p.id, p.first_name, p.last_name, p.wins, p.losses, p.win_pct, p.dupr_rating, p.gender,
                        g.name as group_name
                 FROM players p
                 LEFT JOIN player_group_memberships pgm ON pgm.player_id = p.id
                 LEFT JOIN `groups` g ON g.id = pgm.group_id
                 ORDER BY p.wins DESC, p.win_pct DESC LIMIT 100"
            );
        }

        echo json_encode(['status' => 'success', 'players' => $players]);
        break;

    // ===== GROUPS LIST =====
    case 'groups':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        $groups = dbGetAll(
            "SELECT g.id, g.name, g.group_key, g.created_at,
                    u.first_name as owner_first, u.last_name as owner_last, u.phone as owner_phone,
                    (SELECT COUNT(*) FROM player_group_memberships pgm WHERE pgm.group_id = g.id) as player_count,
                    (SELECT COUNT(*) FROM matches m WHERE m.group_id = g.id) as match_count
             FROM `groups` g
             LEFT JOIN users u ON u.id = g.owner_user_id
             ORDER BY g.created_at DESC"
        );

        echo json_encode(['status' => 'success', 'groups' => $groups]);
        break;

    // ===== TABLE COUNTS (database overview) =====
    case 'table_counts':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        $tables = [
            'users', 'user_sessions', 'verification_codes', 'password_reset_codes',
            'groups', 'players', 'player_group_memberships', 'player_not_duplicates',
            'matches', 'sessions', 'collab_sessions', 'collab_participants', 'collab_score_updates',
            'pool_players', 'player_verification_codes',
            'match_invites', 'invite_responses', 'invite_messages',
            'sms_credits', 'sms_credit_log',
            'broadcasts', 'broadcast_recipients',
            'courts', 'feature_access',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $row = dbGetRow("SELECT COUNT(*) as cnt FROM `{$table}`");
            $counts[$table] = $row ? (int)$row['cnt'] : 0;
        }

        echo json_encode(['status' => 'success', 'counts' => $counts]);
        break;

    // ===== UPDATE USER SUBSCRIPTION =====
    case 'update_user':
        $user_id = $input['user_id'] ?? null;
        $target_user_id = $input['target_user_id'] ?? null;
        requireAdmin($user_id);

        if (!$target_user_id) {
            echo json_encode(['status' => 'error', 'message' => 'target_user_id required']); exit;
        }

        $updates = [];
        $params = [];

        if (isset($input['subscription_status'])) {
            $updates[] = 'subscription_status = ?';
            $params[] = $input['subscription_status'];
        }
        if (isset($input['subscription_tier'])) {
            $updates[] = 'subscription_tier = ?';
            $params[] = $input['subscription_tier'];
        }
        if (isset($input['is_admin'])) {
            $updates[] = 'is_admin = ?';
            $params[] = (int)$input['is_admin'];
        }

        if (empty($updates)) {
            echo json_encode(['status' => 'error', 'message' => 'No updates provided']); exit;
        }

        $params[] = $target_user_id;
        dbQuery("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?", $params);

        echo json_encode(['status' => 'success', 'message' => 'User updated']);
        break;

    // ===== DELETE POOL PLAYER =====
    case 'delete_pool_player':
        $user_id = $input['user_id'] ?? null;
        $player_id = $input['player_id'] ?? null;
        requireAdmin($user_id);

        if (!$player_id) {
            echo json_encode(['status' => 'error', 'message' => 'player_id required']); exit;
        }

        dbQuery("DELETE FROM pool_players WHERE id = ?", [$player_id]);
        echo json_encode(['status' => 'success', 'message' => 'Pool player deleted']);
        break;

    // ===== DELETE GROUP =====
    case 'delete_group':
        $user_id = $input['user_id'] ?? null;
        $group_id = $input['group_id'] ?? null;
        requireAdmin($user_id);

        if (!$group_id) {
            echo json_encode(['status' => 'error', 'message' => 'group_id required']); exit;
        }

        // Clean up related data
        dbQuery("DELETE FROM player_group_memberships WHERE group_id = ?", [$group_id]);
        dbQuery("DELETE FROM matches WHERE group_id = ?", [$group_id]);
        dbQuery("DELETE FROM sessions WHERE group_id = ?", [$group_id]);
        dbQuery("DELETE FROM `groups` WHERE id = ?", [$group_id]);

        echo json_encode(['status' => 'success', 'message' => 'Group and related data deleted']);
        break;

    // ===== CANCEL INVITE =====
    case 'cancel_invite':
        $user_id = $input['user_id'] ?? null;
        $invite_id = $input['invite_id'] ?? null;
        requireAdmin($user_id);

        if (!$invite_id) {
            echo json_encode(['status' => 'error', 'message' => 'invite_id required']); exit;
        }

        dbQuery("UPDATE match_invites SET status = 'cancelled' WHERE id = ?", [$invite_id]);
        echo json_encode(['status' => 'success', 'message' => 'Invite cancelled']);
        break;

    // ===== USER ENGAGEMENT STATS =====
    case 'engagement':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);

        // Top users by login count (users who log in most)
        $topLogins = dbGetAll(
            "SELECT u.id, u.first_name, u.last_name, u.phone, u.last_login_at,
                    (SELECT COUNT(*) FROM user_sessions us WHERE us.user_id = u.id) as login_count
             FROM users u
             ORDER BY login_count DESC LIMIT 30"
        );

        // Top inviters — users who send the most invites
        $topInviters = dbGetAll(
            "SELECT u.id, u.first_name, u.last_name, u.phone,
                    COUNT(mi.id) as invite_count,
                    MAX(mi.created_at) as last_invite_at
             FROM users u
             JOIN match_invites mi ON mi.user_id = u.id
             GROUP BY u.id
             ORDER BY invite_count DESC LIMIT 30"
        );

        // Top accepting players — pool players who accept/confirm invites most
        $topAcceptors = dbGetAll(
            "SELECT pp.id, pp.first_name, pp.last_name, pp.play_level, pp.cities_to_play,
                    COUNT(ir.id) as total_invites_received,
                    SUM(CASE WHEN ir.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                    SUM(CASE WHEN ir.status = 'interested' THEN 1 ELSE 0 END) as interested_count,
                    SUM(CASE WHEN ir.status = 'declined' THEN 1 ELSE 0 END) as declined_count,
                    SUM(CASE WHEN ir.status = 'pending' THEN 1 ELSE 0 END) as pending_count
             FROM pool_players pp
             JOIN invite_responses ir ON ir.player_id = pp.id
             GROUP BY pp.id
             ORDER BY confirmed_count DESC LIMIT 30"
        );

        // Top beacon creators — users who create the most sessions/beacons
        $topBeaconCreators = dbGetAll(
            "SELECT u.id, u.first_name, u.last_name, u.phone,
                    COUNT(s.id) as session_count,
                    MAX(s.created_at) as last_session_at
             FROM users u
             JOIN sessions s ON s.user_id = u.id
             GROUP BY u.id
             ORDER BY session_count DESC LIMIT 30"
        );

        // Top collab users — users who participate in the most collaborative sessions
        $topCollabUsers = dbGetAll(
            "SELECT u.id, u.first_name, u.last_name, u.phone,
                    COUNT(cp.id) as collab_count,
                    MAX(cp.joined_at) as last_collab_at
             FROM users u
             JOIN collab_participants cp ON cp.user_id = u.id
             GROUP BY u.id
             ORDER BY collab_count DESC LIMIT 30"
        );

        // Users who never logged in after signup
        $neverReturned = dbGetAll(
            "SELECT id, first_name, last_name, phone, subscription_status, created_at
             FROM users
             WHERE last_login_at IS NULL OR last_login_at = created_at
             ORDER BY created_at DESC LIMIT 30"
        );

        echo json_encode([
            'status' => 'success',
            'engagement' => [
                'top_logins' => $topLogins,
                'top_inviters' => $topInviters,
                'top_acceptors' => $topAcceptors,
                'top_beacon_creators' => $topBeaconCreators,
                'top_collab_users' => $topCollabUsers,
                'never_returned' => $neverReturned,
            ],
        ]);
        break;

    case 'table_columns':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);
        $table = $input['table'] ?? '';

        // Whitelist tables for safety
        $allowed = ['users','user_sessions','verification_codes','password_reset_codes','groups','players','player_group_memberships','player_not_duplicates','matches','sessions','collab_sessions','collab_participants','collab_score_updates','pool_players','player_verification_codes','match_invites','invite_responses','invite_messages','sms_credits','sms_credit_log','broadcasts','broadcast_recipients','courts','feature_access'];
        if (!in_array($table, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid table']); exit;
        }

        $columns = dbGetAll("SHOW COLUMNS FROM `{$table}`");
        echo json_encode(['status' => 'success', 'columns' => $columns]);
        break;

    case 'table_rows':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);
        $table = $input['table'] ?? '';
        $page = max(1, (int)($input['page'] ?? 1));
        $per_page = min(100, max(1, (int)($input['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;
        $search = trim($input['search'] ?? '');

        $allowed = ['users','user_sessions','verification_codes','password_reset_codes','groups','players','player_group_memberships','player_not_duplicates','matches','sessions','collab_sessions','collab_participants','collab_score_updates','pool_players','player_verification_codes','match_invites','invite_responses','invite_messages','sms_credits','sms_credit_log','broadcasts','broadcast_recipients','courts','feature_access'];
        if (!in_array($table, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid table']); exit;
        }

        $total = dbGetRow("SELECT COUNT(*) as cnt FROM `{$table}`")['cnt'] ?? 0;

        if (!empty($search)) {
            // Get text columns to search across
            $cols = dbGetAll("SHOW COLUMNS FROM `{$table}`");
            $searchCols = [];
            foreach ($cols as $c) {
                if (preg_match('/varchar|text|char/i', $c['Type'])) {
                    $searchCols[] = "`{$c['Field']}` LIKE ?";
                }
            }
            if (!empty($searchCols)) {
                $like = "%{$search}%";
                $params = array_fill(0, count($searchCols), $like);
                $params[] = $per_page;
                $params[] = $offset;
                $where = implode(' OR ', $searchCols);
                $rows = dbGetAll("SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?", $params);
                $totalFiltered = dbGetRow("SELECT COUNT(*) as cnt FROM `{$table}` WHERE {$where}", array_fill(0, count($searchCols), $like))['cnt'] ?? 0;
            } else {
                $rows = dbGetAll("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT ? OFFSET ?", [$per_page, $offset]);
                $totalFiltered = $total;
            }
        } else {
            $rows = dbGetAll("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT ? OFFSET ?", [$per_page, $offset]);
            $totalFiltered = $total;
        }

        echo json_encode([
            'status' => 'success',
            'rows' => $rows,
            'total' => (int)$total,
            'total_filtered' => (int)($totalFiltered ?? $total),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int)ceil(($totalFiltered ?? $total) / $per_page),
        ]);
        break;

    case 'update_record':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);
        $table = $input['table'] ?? '';
        $record_id = $input['record_id'] ?? null;
        $fields = $input['fields'] ?? [];

        $allowed = ['users','user_sessions','verification_codes','password_reset_codes','groups','players','player_group_memberships','player_not_duplicates','matches','sessions','collab_sessions','collab_participants','collab_score_updates','pool_players','player_verification_codes','match_invites','invite_responses','invite_messages','sms_credits','sms_credit_log','broadcasts','broadcast_recipients','courts','feature_access'];
        if (!in_array($table, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid table']); exit;
        }
        if (!$record_id || empty($fields)) {
            echo json_encode(['status' => 'error', 'message' => 'record_id and fields required']); exit;
        }

        // Build SET clause from fields, only allow known columns
        $cols = dbGetAll("SHOW COLUMNS FROM `{$table}`");
        $colNames = array_column($cols, 'Field');

        $updates = [];
        $params = [];
        foreach ($fields as $key => $val) {
            if (in_array($key, $colNames) && $key !== 'id') {
                $updates[] = "`{$key}` = ?";
                $params[] = $val;
            }
        }

        if (empty($updates)) {
            echo json_encode(['status' => 'error', 'message' => 'No valid fields to update']); exit;
        }

        $params[] = $record_id;
        dbQuery("UPDATE `{$table}` SET " . implode(', ', $updates) . " WHERE id = ?", $params);

        echo json_encode(['status' => 'success', 'message' => 'Record updated']);
        break;

    case 'delete_record':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);
        $table = $input['table'] ?? '';
        $record_id = $input['record_id'] ?? null;

        $allowed = ['users','user_sessions','verification_codes','password_reset_codes','groups','players','player_group_memberships','player_not_duplicates','matches','sessions','collab_sessions','collab_participants','collab_score_updates','pool_players','player_verification_codes','match_invites','invite_responses','invite_messages','sms_credits','sms_credit_log','broadcasts','broadcast_recipients','courts','feature_access'];
        if (!in_array($table, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid table']); exit;
        }
        if (!$record_id) {
            echo json_encode(['status' => 'error', 'message' => 'record_id required']); exit;
        }

        dbQuery("DELETE FROM `{$table}` WHERE id = ?", [$record_id]);
        echo json_encode(['status' => 'success', 'message' => 'Record deleted']);
        break;

    case 'insert_record':
        $user_id = $input['user_id'] ?? null;
        requireAdmin($user_id);
        $table = $input['table'] ?? '';
        $fields = $input['fields'] ?? [];

        $allowed = ['users','user_sessions','verification_codes','password_reset_codes','groups','players','player_group_memberships','player_not_duplicates','matches','sessions','collab_sessions','collab_participants','collab_score_updates','pool_players','player_verification_codes','match_invites','invite_responses','invite_messages','sms_credits','sms_credit_log','broadcasts','broadcast_recipients','courts','feature_access'];
        if (!in_array($table, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid table']); exit;
        }
        if (empty($fields)) {
            echo json_encode(['status' => 'error', 'message' => 'fields required']); exit;
        }

        // Only allow known columns
        $cols = dbGetAll("SHOW COLUMNS FROM `{$table}`");
        $colNames = array_column($cols, 'Field');

        $insertCols = [];
        $placeholders = [];
        $params = [];
        foreach ($fields as $key => $val) {
            if (in_array($key, $colNames) && $key !== 'id') {
                $insertCols[] = "`{$key}`";
                $placeholders[] = '?';
                $params[] = $val;
            }
        }

        if (empty($insertCols)) {
            echo json_encode(['status' => 'error', 'message' => 'No valid fields']); exit;
        }

        $newId = dbInsert(
            "INSERT INTO `{$table}` (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")",
            $params
        );

        echo json_encode(['status' => 'success', 'message' => 'Record created', 'id' => $newId]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
