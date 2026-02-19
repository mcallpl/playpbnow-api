<?php
// ============================================
// add_player.php V3 — UNIVERSAL PLAYERS
// Phone is OPTIONAL. NULL phone = no unique conflict.
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$group_key          = $input['group_key'] ?? '';
$first_name         = trim($input['first_name'] ?? '');
$last_name          = trim($input['last_name'] ?? '');
$gender             = $input['gender'] ?? '';
$player_key         = $input['player_key'] ?? 'pk_' . time() . '_' . rand(1000,9999);
$cell_phone         = trim($input['cell_phone'] ?? '');
$existing_player_id = $input['existing_player_id'] ?? null;
$force_new           = $input['force_new'] ?? false;

// Normalize phone: empty string → NULL
if ($cell_phone === '') $cell_phone = null;

if (empty($group_key)) { echo json_encode(['status' => 'error', 'message' => 'Group key required']); exit; }
if (!$existing_player_id && (empty($first_name) || empty($gender))) {
    echo json_encode(['status' => 'error', 'message' => 'Name and gender required']); exit;
}

try {
    $group = dbGetRow(
        "SELECT g.id, g.court_id FROM `groups` g WHERE g.group_key = ?",
        [$group_key]
    );
    if (!$group) { echo json_encode(['status' => 'error', 'message' => 'Group not found']); exit; }
    
    $group_id = (int)$group['id'];
    $court_id = $group['court_id'] ? (int)$group['court_id'] : null;

    // ── OPTION A: Link existing player by ID ─────────────────
    if ($existing_player_id) {
        $player_id = (int)$existing_player_id;
        $player = dbGetRow("SELECT id, first_name, player_key FROM players WHERE id = ?", [$player_id]);
        if (!$player) { echo json_encode(['status' => 'error', 'message' => 'Player not found']); exit; }
        $player_key = $player['player_key'];
        goto add_membership;
    }

    // ── OPTION B: Search by phone first (if provided) ────────
    if ($cell_phone) {
        $byPhone = dbGetRow(
            "SELECT id, player_key FROM players WHERE cell_phone = ?",
            [$cell_phone]
        );
        if ($byPhone) {
            $player_id = (int)$byPhone['id'];
            $player_key = $byPhone['player_key'];
            goto add_membership;
        }
    }

    // ── OPTION B2: Check for same name in this group ─────────
    // Don't auto-merge — return the match so frontend can ask the user
    if (!$force_new) {
        $byName = dbGetAll(
            "SELECT p.id, p.player_key, p.first_name, p.last_name, p.cell_phone, p.gender
             FROM players p
             INNER JOIN player_group_memberships pgm ON p.id = pgm.player_id
             WHERE LOWER(TRIM(p.first_name)) = LOWER(TRIM(?)) AND pgm.group_id = ?",
            [$first_name, $group_id]
        );
        if (!empty($byName)) {
            echo json_encode([
                'status' => 'duplicate_name',
                'message' => 'A player with this name already exists in the group',
                'existing_players' => $byName
            ]);
            exit;
        }
    }

    // ── OPTION C: Create new player ──────────────────────────
    // Use NULL for cell_phone if not provided (avoids unique constraint on empty string)
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "INSERT INTO players (group_id, player_key, first_name, last_name, gender, cell_phone, home_court_id, device_id, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?, '', NOW())"
    );
    $stmt->bind_param('isssssi', $group_id, $player_key, $first_name, $last_name, $gender, $cell_phone, $court_id);
    $stmt->execute();
    $player_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    
    error_log("✅ Created player: $first_name (ID: $player_id, court: $court_id, phone: " . ($cell_phone ?? 'none') . ")");

    add_membership:
    
    // Check existing membership
    $existingMem = dbGetRow(
        "SELECT id FROM player_group_memberships WHERE player_id = ? AND group_id = ?",
        [$player_id, $group_id]
    );
    
    if ($existingMem) {
        echo json_encode([
            'status' => 'success', 'message' => 'Player already in group',
            'player_id' => $player_id, 'player_key' => $player_key, 'already_existed' => true
        ]);
        exit;
    }
    
    // Get next order_index
    $maxOrder = dbGetRow(
        "SELECT COALESCE(MAX(order_index), -1) as mx FROM player_group_memberships WHERE group_id = ?",
        [$group_id]
    );
    $nextOrder = (int)$maxOrder['mx'] + 1;
    
    // Add membership
    $memId = dbInsert(
        "INSERT INTO player_group_memberships (player_id, group_id, order_index, joined_at) VALUES (?, ?, ?, NOW())",
        [$player_id, $group_id, $nextOrder]
    );
    
    // Get player details to return
    $pd = dbGetRow("SELECT * FROM players WHERE id = ?", [$player_id]);
    
    echo json_encode([
        'status' => 'success', 'message' => 'Player added to group',
        'player_id' => $player_id,
        'player_key' => $pd['player_key'] ?? $player_key,
        'first_name' => $pd['first_name'] ?? $first_name,
        'membership_id' => $memId
    ]);
    
} catch (Exception $e) {
    error_log("❌ Add player error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
