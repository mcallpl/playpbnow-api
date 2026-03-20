<?php
// ============================================
// save_group_roster.php — Save or create group with full player roster
// - Same name as current group: overwrite players & order
// - Different name: create new group, copy players to it
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$group_key = $input['group_key'] ?? '';
$user_id   = $input['user_id'] ?? '';
$new_name  = trim($input['new_name'] ?? '');
$players   = $input['players'] ?? [];

if (empty($group_key)) { echo json_encode(['status' => 'error', 'message' => 'Group key required']); exit; }
if (empty($user_id))   { echo json_encode(['status' => 'error', 'message' => 'User ID required']); exit; }
if (empty($new_name))  { echo json_encode(['status' => 'error', 'message' => 'Group name required']); exit; }

try {
    // Get current group
    $group = dbGetRow("SELECT * FROM `groups` WHERE group_key = ?", [$group_key]);
    if (!$group) { echo json_encode(['status' => 'error', 'message' => 'Group not found']); exit; }

    $current_name = $group['name'];
    $is_same_name = (strtolower($current_name) === strtolower($new_name));

    if ($is_same_name) {
        // ── OVERWRITE: sync roster to current group ──
        $group_id = (int)$group['id'];
        $target_key = $group_key;
        $target_name = $current_name;

        // Update group name (in case capitalization changed)
        dbQuery("UPDATE `groups` SET name = ?, updated_at = NOW() WHERE id = ?", [$new_name, $group_id]);

        syncRoster($group_id, $players);

        echo json_encode([
            'status' => 'success',
            'message' => 'Group roster updated',
            'group_key' => $target_key,
            'group_name' => $new_name,
            'group_id' => $group_id,
            'player_count' => count($players)
        ]);

    } else {
        // ── NEW GROUP: check if name already exists for this user ──
        $existing = dbGetRow(
            "SELECT * FROM `groups` WHERE name = ? AND owner_user_id = ?",
            [$new_name, $user_id]
        );

        if ($existing) {
            // Name already taken by another group — overwrite that group's roster
            $group_id = (int)$existing['id'];
            $target_key = $existing['group_key'];

            dbQuery("UPDATE `groups` SET court_id = ?, updated_at = NOW() WHERE id = ?",
                [$group['court_id'], $group_id]);

            syncRoster($group_id, $players);

            echo json_encode([
                'status' => 'success',
                'message' => 'Existing group roster overwritten',
                'group_key' => $target_key,
                'group_name' => $new_name,
                'group_id' => $group_id,
                'player_count' => count($players)
            ]);
        } else {
            // Create brand new group
            $new_key = "group_" . time() . "_" . $user_id;
            $new_group_id = dbInsert(
                "INSERT INTO `groups` (name, group_key, owner_user_id, court_id, device_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, '', NOW(), NOW())",
                [$new_name, $new_key, $user_id, $group['court_id']]
            );

            syncRoster($new_group_id, $players);

            echo json_encode([
                'status' => 'success',
                'message' => 'New group created',
                'group_key' => $new_key,
                'group_name' => $new_name,
                'group_id' => $new_group_id,
                'player_count' => count($players)
            ]);
        }
    }

} catch (Exception $e) {
    error_log("save_group_roster error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

/**
 * Sync the full roster: remove players not in the list, add missing ones, update order.
 */
function syncRoster(int $group_id, array $players): int {
    $conn = getDBConnection();

    // Resolve player IDs from player_key, with fallback to name+gender lookup in this group
    $player_ids = [];
    $resolved = 0;
    foreach ($players as $index => $p) {
        $key = $p['id'] ?? '';
        if (!$key) continue;

        // Primary: lookup by player_key
        $row = dbGetRow("SELECT id FROM players WHERE player_key = ?", [$key]);
        if ($row) {
            $player_ids[$index] = (int)$row['id'];
            $resolved++;
            continue;
        }

        // Fallback: lookup by first_name + gender among current group members
        $fname = $p['first_name'] ?? '';
        $gender = $p['gender'] ?? '';
        if ($fname && $gender) {
            $row = dbGetRow(
                "SELECT p.id FROM players p
                 INNER JOIN player_group_memberships pgm ON p.id = pgm.player_id
                 WHERE pgm.group_id = ? AND LOWER(TRIM(p.first_name)) = LOWER(TRIM(?)) AND LOWER(p.gender) = LOWER(?)",
                [$group_id, $fname, $gender]
            );
            if ($row) {
                $player_ids[$index] = (int)$row['id'];
                $resolved++;
                continue;
            }
        }

        error_log("syncRoster: could not resolve player key=$key name=" . ($p['first_name'] ?? '?'));
    }

    // Only delete memberships if we resolved ALL players. If some failed, keep existing memberships safe.
    $unique_ids = array_unique(array_values($player_ids));
    if ($resolved === count($players) && !empty($unique_ids)) {
        $placeholders = implode(',', array_fill(0, count($unique_ids), '?'));
        $params = array_merge([$group_id], $unique_ids);
        $stmt = $conn->prepare(
            "DELETE FROM player_group_memberships WHERE group_id = ? AND player_id NOT IN ($placeholders)"
        );
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    // Upsert memberships with correct order
    foreach ($player_ids as $index => $pid) {
        $existing = dbGetRow(
            "SELECT id FROM player_group_memberships WHERE player_id = ? AND group_id = ?",
            [$pid, $group_id]
        );
        if ($existing) {
            $stmt = $conn->prepare("UPDATE player_group_memberships SET order_index = ? WHERE id = ?");
            $stmt->bind_param('ii', $index, $existing['id']);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO player_group_memberships (player_id, group_id, order_index, joined_at) VALUES (?, ?, ?, NOW())"
            );
            $stmt->bind_param('iii', $pid, $group_id, $index);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->close();
    return $resolved;
}
