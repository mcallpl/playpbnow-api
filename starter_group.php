<?php
/**
 * Starter group for every new member.
 *
 * On registration we create a ready-to-use "Test Group" pre-loaded with 16
 * practice players (8 male / 8 female) so a brand-new user can generate a match
 * and explore the app immediately, before adding their own crew.
 *
 * Fresh player records are created per user (not shared globally), so one user's
 * edits/stats never leak into another's starter group. Best-effort: any failure
 * is logged and NEVER blocks account creation.
 */
function pbnow_create_starter_group($user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) return null;

    try {
        // 1. The group (owned by the new user), defaulted to the first court in
        //    the shared catalog (same ordering the app's picker uses). The user
        //    changes it to their real court — we just never leave it blank.
        $default_court = dbGetRow("SELECT id FROM courts ORDER BY name ASC LIMIT 1");
        $court_id = $default_court ? (int)$default_court['id'] : null;
        $group_key = 'group_' . time() . '_' . $user_id;
        $group_id = dbInsert(
            "INSERT INTO `groups` (name, group_key, owner_user_id, court_id, device_id, created_at, updated_at)
             VALUES ('Test Group', ?, ?, ?, '', NOW(), NOW())",
            [$group_key, $user_id, $court_id]
        );
        if (!$group_id) {
            error_log("starter_group: failed to create group for user {$user_id}");
            return null;
        }

        // 2. The 16 starter players (order preserved via order_index).
        $roster = [
            ['Abe', 'male'],    ['Bob', 'male'],    ['Chip', 'male'],   ['David', 'male'],
            ['Edward', 'male'], ['Fred', 'male'],   ['Greg', 'male'],   ['Howard', 'male'],
            ['Ingrid', 'female'], ['Janine', 'female'], ['Kim', 'female'],    ['Lauren', 'female'],
            ['Marie', 'female'],  ['Nancy', 'female'],  ['Olivia', 'female'], ['Pauline', 'female'],
        ];

        $created = 0;
        foreach ($roster as $i => $p) {
            list($first, $gender) = $p;
            $player_key = 'pk_' . time() . '_' . $user_id . '_' . $i . '_' . rand(1000, 9999);

            $player_id = dbInsert(
                "INSERT INTO players (group_id, player_key, first_name, last_name, gender, cell_phone, home_court_id, device_id, created_at)
                 VALUES (?, ?, ?, '', ?, NULL, NULL, '', NOW())",
                [$group_id, $player_key, $first, $gender]
            );
            if (!$player_id) {
                error_log("starter_group: failed to create player {$first} for user {$user_id}");
                continue;
            }

            dbInsert(
                "INSERT INTO player_group_memberships (player_id, group_id, order_index, joined_at) VALUES (?, ?, ?, NOW())",
                [$player_id, $group_id, $i]
            );
            $created++;
        }

        error_log("starter_group: created 'Test Group' (id={$group_id}, {$created} players) for user {$user_id}");
        return $group_id;
    } catch (Throwable $e) {
        error_log("starter_group error for user {$user_id}: " . $e->getMessage());
        return null;
    }
}
