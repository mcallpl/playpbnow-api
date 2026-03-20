<?php
/**
 * Pool Players API — Browse/search the free talent pool
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {

    case 'search':
        $user_id = $input['user_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'user_id is required']); exit; }

        if (!userHasActiveSubscription($user_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Active subscription required']); exit;
        }

        $page = max(1, (int)($input['page'] ?? 1));
        $per_page = max(1, min(5000, (int)($input['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;

        $where = ["(`private` != 'Yes' OR `private` IS NULL)"];
        $params = [];

        if (!empty($input['search_name'])) {
            $where[] = "(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
            $term = '%' . $input['search_name'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if (!empty($input['play_level'])) {
            $where[] = "play_level = ?";
            $params[] = $input['play_level'];
        }
        if (!empty($input['gender'])) {
            $where[] = "gender = ?";
            $params[] = $input['gender'];
        }
        if (!empty($input['cities_to_play'])) {
            $where[] = "cities_to_play LIKE ?";
            $params[] = '%' . $input['cities_to_play'] . '%';
        }
        if (!empty($input['short_notice'])) {
            $where[] = "short_notice = ?";
            $params[] = $input['short_notice'];
        }
        if (!empty($input['tournament_interest'])) {
            $where[] = "tournament_interest = ?";
            $params[] = $input['tournament_interest'];
        }

        $whereSQL = implode(' AND ', $where);

        $players = dbGetAll(
            "SELECT id, first_name, last_name, gender, play_level, days_to_play, times_to_play, cities_to_play, short_notice, tournament_interest, max_travel_minutes
             FROM pool_players WHERE $whereSQL ORDER BY first_name, last_name LIMIT $per_page OFFSET $offset",
            $params
        );

        echo json_encode(['status' => 'success', 'players' => $players]);
        break;

    case 'count':
        $row = dbGetRow("SELECT COUNT(*) AS total FROM pool_players WHERE `private` != 'Yes' OR `private` IS NULL");
        echo json_encode(['status' => 'success', 'count' => (int)$row['total']]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
