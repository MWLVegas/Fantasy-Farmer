<?php

function columnExists(mysqli $db, string $tableName, string $columnName): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $tableName, $columnName);
    if (!$stmt->execute()) return false;
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

function getGameConfig(mysqli $db): array
{
    $order = columnExists($db, 'game_config', 'config_id') ? ' ORDER BY config_id ASC' : '';
    $result = $db->query("SELECT * FROM game_config" . $order . " LIMIT 1");
    $config = $result ? $result->fetch_assoc() : null;
    if (!$config) {
        throw new RuntimeException('Missing game config.');
    }
    return $config;
}

function getAppVersion(mysqli $db): string
{
    try {
        $config = getGameConfig($db);
        return $config['app_version'] ?? GAME_VERSION;
    } catch (Throwable $e) {
        return GAME_VERSION;
    }
}


function getMapConfig(mysqli $db): array
{
    $config = getGameConfig($db);
    $markers = [];

    $hasMarkerTable = false;
    try {
        $res = $db->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config'");
        $hasMarkerTable = $res && (int)($res->fetch_assoc()['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        $hasMarkerTable = false;
    }

    if ($hasMarkerTable) {
        $select = "location_key, map_x, map_y, map_icon";
        $select .= columnExists($db, 'map_location_config', 'icon_size') ? ", icon_size" : ", NULL AS icon_size";
        $select .= columnExists($db, 'map_location_config', 'glow_color') ? ", glow_color" : ", NULL AS glow_color";
        $select .= columnExists($db, 'map_location_config', 'side_menu_html') ? ", side_menu_html" : ", NULL AS side_menu_html";
        $select .= columnExists($db, 'map_location_config', 'day_background_image') ? ", day_background_image" : ", NULL AS day_background_image";
        $select .= columnExists($db, 'map_location_config', 'night_background_image') ? ", night_background_image" : ", NULL AS night_background_image";
        $select .= columnExists($db, 'map_location_config', 'active_map_icon') ? ", active_map_icon" : ", NULL AS active_map_icon";
        $select .= columnExists($db, 'map_location_config', 'inactive_map_icon') ? ", inactive_map_icon" : ", NULL AS inactive_map_icon";
        $select .= columnExists($db, 'map_location_config', 'active_day_background_image') ? ", active_day_background_image" : ", NULL AS active_day_background_image";
        $select .= columnExists($db, 'map_location_config', 'active_night_background_image') ? ", active_night_background_image" : ", NULL AS active_night_background_image";
        $select .= columnExists($db, 'map_location_config', 'inactive_day_background_image') ? ", inactive_day_background_image" : ", NULL AS inactive_day_background_image";
        $select .= columnExists($db, 'map_location_config', 'inactive_night_background_image') ? ", inactive_night_background_image" : ", NULL AS inactive_night_background_image";
        $res = $db->query("SELECT {$select} FROM map_location_config WHERE is_active = 1 ORDER BY sort_order ASC, location_key ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $markers[$row['location_key']] = [
                    'x' => (int)$row['map_x'],
                    'y' => (int)$row['map_y'],
                    'icon' => $row['map_icon'] ?? '',
                    'size' => (int)($row['icon_size'] ?? 78),
                    'glow_color' => $row['glow_color'] ?? 'rgba(255, 214, 94, .78)',
                    'side_menu_html' => $row['side_menu_html'] ?? null,
                    'day_background_image' => $row['day_background_image'] ?? null,
                    'night_background_image' => $row['night_background_image'] ?? null,
                    'active_map_icon' => $row['active_map_icon'] ?? null,
                    'inactive_map_icon' => $row['inactive_map_icon'] ?? null,
                    'active_day_background_image' => $row['active_day_background_image'] ?? null,
                    'active_night_background_image' => $row['active_night_background_image'] ?? null,
                    'inactive_day_background_image' => $row['inactive_day_background_image'] ?? null,
                    'inactive_night_background_image' => $row['inactive_night_background_image'] ?? null
                ];
            }
        }
    }

    $sideMenus = [];
    foreach ($markers as $key => $marker) {
        if (!empty($marker['side_menu_html'])) $sideMenus[$key] = $marker['side_menu_html'];
    }

    return [
        'title' => $config['map_title'] ?? 'Town',
        'background_image' => $config['map_background_image'] ?? '',
        'day_background_image' => $config['map_day_background_image'] ?? '',
        'night_background_image' => $config['map_night_background_image'] ?? '',
        'button_positions_json' => $config['map_button_positions_json'] ?? '',
        'side_menu' => $config['map_side_menu_html'] ?? '',
        'side_menus' => $sideMenus,
        'location_markers' => $markers
    ];
}

function getGameClock(mysqli $db, int $userId): array
{
    $config = getGameConfig($db);
    $stmt = $db->prepare("SELECT started_at FROM player_state WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();

    $startedAt = $state ? strtotime($state['started_at']) : time();
    $elapsed = max(0, time() - $startedAt);
    $dayLength = max(60, (int) $config['day_length_seconds']);
    $dayFloat = $elapsed / $dayLength;
    $absoluteDay = (int) floor($dayFloat) + 1;
    $yearLength = max(30, (int)($config['year_length_days'] ?? 300));
    $year = (int) floor(($absoluteDay - 1) / $yearLength) + 1;
    $day = (($absoluteDay - 1) % $yearLength) + 1;
    $dayProgress = $dayFloat - floor($dayFloat);
    $mins = (int) floor($dayProgress * 1440);

    return [
        'day_length_seconds' => $dayLength,
        'year_length_days' => $yearLength,
        'absolute_day' => $absoluteDay,
        'year' => $year,
        'day' => $day,
        'hour' => (int) floor($mins / 60),
        'minute' => $mins % 60,
        'day_progress' => $dayProgress,
        'total_game_hours_elapsed' => $dayFloat * 24,
        'sun_icon' => $config['sun_icon'],
        'moon_icon' => $config['moon_icon'] ?? '🌙',
        'pouch_icon' => $config['pouch_icon'] ?? '🌱'
    ];
}

function cycleIndexForElapsedHours(float $elapsedHours, int $cycleHour, int $cycleLengthHours = 24): int
{
    return (int) floor(($elapsedHours - $cycleHour) / max(1, $cycleLengthHours));
}

function ensurePlayerDefaults(mysqli $db, int $userId): void
{
    $stmt = $db->prepare("INSERT IGNORE INTO player_state (user_id, last_pouch_at, next_shop_refresh_at, next_order_at) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 60 MINUTE), DATE_ADD(NOW(), INTERVAL FLOOR(3+RAND()*3) MINUTE))");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    ensureStartingGarden($db, $userId);
    ensureStartingTools($db, $userId);
    ensureStartingInventory($db, $userId);
    ensurePlayerProgressDefaults($db, $userId);
    ensureStartingUnlocks($db, $userId);
}

function ensureStartingInventory(mysqli $db, int $userId): void
{
    $stmt = $db->prepare("
        SELECT inventory_id
        FROM player_inventory inv
        JOIN items i ON i.item_id = inv.item_id
        WHERE inv.user_id = ? AND i.code = 'carrot_seed'
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    if ($stmt->get_result()->fetch_assoc()) {
        return;
    }

    $item = $db->query("SELECT item_id FROM items WHERE code = 'carrot_seed' LIMIT 1")->fetch_assoc();
    if ($item) {
        addInventory($db, $userId, (int) $item['item_id'], 6);
    }
}

function ensureStartingTools(mysqli $db, int $userId): void
{
    $codes = ['broken_hoe', 'leaky_watering_can', 'bent_shovel'];

    foreach ($codes as $code) {
        $stmt = $db->prepare("SELECT tool_id FROM tools WHERE code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $tool = $stmt->get_result()->fetch_assoc();

        if (!$tool) {
            continue;
        }

        $toolId = (int) $tool['tool_id'];
        $stmt = $db->prepare("INSERT IGNORE INTO player_tools (user_id, tool_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $userId, $toolId);
        $stmt->execute();
    }
}

function ensureStartingGarden(mysqli $db, int $userId): void
{
    $stmt = $db->prepare("SELECT garden_id FROM gardens WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    if ($stmt->get_result()->fetch_assoc()) {
        return;
    }

    $type = $db->query("SELECT garden_type_id FROM garden_types WHERE code = 'farm' LIMIT 1")->fetch_assoc();

    if (!$type) {
        throw new RuntimeException('Missing farm garden type.');
    }

    $gardenTypeId = (int) $type['garden_type_id'];

    $stmt = $db->prepare("INSERT INTO gardens (user_id, garden_type_id, name) VALUES (?, ?, 'North Field')");
    $stmt->bind_param('ii', $userId, $gardenTypeId);
    $stmt->execute();

    $gardenId = (int) $db->insert_id;

    $stmt = $db->prepare("
        INSERT INTO garden_plots (garden_id, x_pos, y_pos, is_unlocked, till_progress, is_tilled, unlocked_at)
        VALUES (?, ?, ?, ?, 0, 0, ?)
    ");

    for ($y = 1; $y <= 5; $y++) {
        for ($x = 1; $x <= 5; $x++) {
            $unlocked = (($x === 1 || $x === 2) && ($y === 1 || $y === 2)) ? 1 : 0;
            $unlockedAt = $unlocked ? date('Y-m-d H:i:s') : null;
            $stmt->bind_param('iiiis', $gardenId, $x, $y, $unlocked, $unlockedAt);
            $stmt->execute();
        }
    }
}

function addInventory(mysqli $db, int $userId, int $itemId, int $quantity): void
{
    $stmt = $db->prepare("
        INSERT INTO player_inventory (user_id, item_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $stmt->bind_param('iii', $userId, $itemId, $quantity);
    $stmt->execute();
}

function removeInventory(mysqli $db, int $userId, int $itemId, int $quantity): bool
{
    $stmt = $db->prepare("SELECT quantity FROM player_inventory WHERE user_id = ? AND item_id = ? LIMIT 1");
    $stmt->bind_param('ii', $userId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || (int) $row['quantity'] < $quantity) {
        return false;
    }

    $stmt = $db->prepare("UPDATE player_inventory SET quantity = quantity - ? WHERE user_id = ? AND item_id = ?");
    $stmt->bind_param('iii', $quantity, $userId, $itemId);
    $stmt->execute();

    return true;
}

function processGrowth(mysqli $db, int $userId): void
{
    $cyclesSql = plantCyclesSql($db, 'p');
    $clock = getGameClock($db, $userId);
    $elapsedGameHours = (float) $clock['total_game_hours_elapsed'];

    $stmt = $db->prepare("
        SELECT pc.*, {$cyclesSql} AS growth_steps, p.cycle_hour, COALESCE(p.cycle_length_hours, 24) AS cycle_length_hours, p.water_required, p.water_drain_per_game_hour,
               COALESCE(problems.problem_count, 0) AS problem_count
        FROM planted_crops pc
        JOIN plants p ON p.plant_id = pc.plant_id
        LEFT JOIN (
            SELECT planted_crop_id, COUNT(*) AS problem_count
            FROM crop_problems
            WHERE is_resolved = 0
            GROUP BY planted_crop_id
        ) problems ON problems.planted_crop_id = pc.planted_crop_id
        WHERE pc.user_id = ? AND pc.is_harvested = 0
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $crops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($crops as $crop) {
        $elapsedRealSeconds = max(0, time() - strtotime($crop['last_updated_at']));
        $gameHoursSinceUpdate = ($elapsedRealSeconds / $clock['day_length_seconds']) * 24;
        $waterDrain = (int) floor((int) $crop['water_drain_per_game_hour'] * $gameHoursSinceUpdate);
        $water = max(0, (int) $crop['water_current'] - $waterDrain);

        $step = (int) $crop['growth_step_current'];
        $lastCycle = (int) $crop['last_cycle_index'];
        $currentCycle = cycleIndexForElapsedHours($elapsedGameHours, (int) $crop['cycle_hour'], (int) $crop['cycle_length_hours']);

        if ($currentCycle > $lastCycle && $water >= (int) $crop['water_required'] && (int)($crop['problem_count'] ?? 0) === 0 && !(int) $crop['has_weeds'] && !(int) $crop['has_pests']) {
            $advance = min($currentCycle - $lastCycle, (int) $crop['growth_steps'] - $step);
            if ($advance > 0) $step += $advance;
        }

        if ($currentCycle > $lastCycle) $lastCycle = $currentCycle;

        $stmt = $db->prepare("
            UPDATE planted_crops
            SET water_current = ?, growth_step_current = ?, last_cycle_index = ?, last_updated_at = NOW()
            WHERE planted_crop_id = ? AND user_id = ?
        ");
        $cropId = (int) $crop['planted_crop_id'];
        $stmt->bind_param('iiiii', $water, $step, $lastCycle, $cropId, $userId);
        $stmt->execute();
    }
}


function processHelperAutomation(mysqli $db, int $userId): void
{
    $cyclesSql = plantCyclesSql($db, 'p');
    $ready = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_helpers' AND COLUMN_NAME = 'last_action_at'");
    if (!$ready || (int)($ready->fetch_assoc()['c'] ?? 0) === 0) return;

    $stmt = $db->prepare("\n        SELECT ph.*, he.code AS equipment_code, he.task_type\n        FROM player_helpers ph\n        JOIN helper_equipment he ON he.helper_equipment_id = ph.equipped_helper_equipment_id\n        WHERE ph.user_id = ?\n          AND ph.is_enabled = 1\n          AND he.is_active = 1\n          AND COALESCE(ph.active_task, he.task_type, 'idle') <> 'idle'\n    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $helpers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$helpers) return;

    $garden = null;
    $stmt = $db->prepare("SELECT garden_id FROM gardens WHERE user_id = ? ORDER BY garden_id ASC LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $garden = $stmt->get_result()->fetch_assoc();
    if (!$garden) return;
    $gardenId = (int)$garden['garden_id'];

    foreach ($helpers as $helper) {
        $last = !empty($helper['last_action_at']) ? strtotime($helper['last_action_at']) : 0;
        $speed = max(1, (int)($helper['speed_rating'] ?? 10));
        $effectiveness = max(1, (int)($helper['effectiveness_rating'] ?? 10));
        // Helpers should feel useful, but still weaker than hands-on play.
        // Speed shortens the delay between helper actions instead of making them spam instantly.
        $cooldown = max(12, 40 - (int)floor($speed * 1.5));
        if ($last && time() - $last < $cooldown) continue;

        $helperId = (int)$helper['player_helper_id'];
        $task = (string)($helper['task_type'] ?: $helper['active_task']);
        $didWork = false;
        $targetX = null;
        $targetY = null;

        if ($task === 'water') {
            $toolStrength = 15;
            $stmt = $db->prepare("
                SELECT t.strength
                FROM player_tools pt
                JOIN tools t ON t.tool_id = pt.tool_id
                WHERE pt.user_id = ? AND t.tool_type = 'watering_can'
                ORDER BY t.level DESC
                LIMIT 1
            " );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $tool = $stmt->get_result()->fetch_assoc();
            if ($tool) $toolStrength = max(1, (int)$tool['strength']);

            // Water fairy output follows the player's can, but stays a bit weaker than manual watering.
            $amount = max(8, (int)floor(($toolStrength * 0.70) + ($effectiveness * 0.20)));
            $stmt = $db->prepare("
                SELECT pc.planted_crop_id, pc.origin_x, pc.origin_y
                FROM planted_crops pc
                JOIN plants p ON p.plant_id = pc.plant_id
                WHERE pc.user_id = ? AND pc.is_harvested = 0
                  AND pc.water_current < p.water_max
                  AND pc.growth_step_current < {$cyclesSql}
                  AND pc.has_weeds = 0
                  AND pc.has_pests = 0
                  AND NOT EXISTS (SELECT 1 FROM crop_problems cp WHERE cp.planted_crop_id = pc.planted_crop_id AND cp.is_resolved = 0)
                ORDER BY (pc.water_current / NULLIF(p.water_max, 0)) ASC, pc.planted_crop_id ASC
                LIMIT 1
            " );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $crop = $stmt->get_result()->fetch_assoc();
            if ($crop) {
                $cropId = (int)$crop['planted_crop_id'];
                $stmt = $db->prepare("
                    UPDATE planted_crops pc
                    JOIN plants p ON p.plant_id = pc.plant_id
                    SET pc.water_current = LEAST(p.water_max, pc.water_current + ?)
                    WHERE pc.planted_crop_id = ? AND pc.user_id = ?
                      AND pc.is_harvested = 0
                      AND pc.water_current < p.water_max
                " );
                $stmt->bind_param('iii', $amount, $cropId, $userId);
                $stmt->execute();
                $didWork = $stmt->affected_rows > 0;
                $targetX = (int)$crop['origin_x'];
                $targetY = (int)$crop['origin_y'];
            }
        } elseif ($task === 'till') {
            $amount = max(4, (int)floor($effectiveness * 0.6));
            $stmt = $db->prepare("\n                SELECT plot_id, x_pos, y_pos\n                FROM garden_plots\n                WHERE garden_id = ? AND is_unlocked = 1 AND is_tilled = 0\n                ORDER BY y_pos ASC, x_pos ASC\n                LIMIT 1\n            ");
            $stmt->bind_param('i', $gardenId);
            $stmt->execute();
            $plot = $stmt->get_result()->fetch_assoc();
            if ($plot) {
                $plotId = (int)$plot['plot_id'];
                $stmt = $db->prepare("\n                    UPDATE garden_plots\n                    SET till_progress = LEAST(100, till_progress + ?),\n                        is_tilled = CASE WHEN LEAST(100, till_progress + ?) >= 100 THEN 1 ELSE is_tilled END\n                    WHERE plot_id = ?\n                ");
                $stmt->bind_param('iii', $amount, $amount, $plotId);
                $stmt->execute();
                $didWork = $stmt->affected_rows >= 0;
                $targetX = (int)$plot['x_pos'];
                $targetY = (int)$plot['y_pos'];
            }
        } elseif ($task === 'harvest') {
            $stmt = $db->prepare("\n                SELECT pc.planted_crop_id, pc.origin_x, pc.origin_y, p.harvest_item_id, p.harvest_min\n                FROM planted_crops pc\n                JOIN plants p ON p.plant_id = pc.plant_id\n                WHERE pc.user_id = ? AND pc.garden_id = ? AND pc.is_harvested = 0\n                  AND pc.growth_step_current >= {$cyclesSql}\n                ORDER BY pc.planted_crop_id ASC\n                LIMIT 1\n            ");
            $stmt->bind_param('ii', $userId, $gardenId);
            $stmt->execute();
            $crop = $stmt->get_result()->fetch_assoc();
            if ($crop) {
                addInventory($db, $userId, (int)$crop['harvest_item_id'], max(1, (int)$crop['harvest_min']));
                $cropId = (int)$crop['planted_crop_id'];
                $stmt = $db->prepare("UPDATE planted_crops SET is_harvested = 1 WHERE planted_crop_id = ? AND user_id = ?");
                $stmt->bind_param('ii', $cropId, $userId);
                $stmt->execute();
                $didWork = true;
                $targetX = (int)$crop['origin_x'];
                $targetY = (int)$crop['origin_y'];
            }
        } elseif ($task === 'plant') {
            $stmt = $db->prepare("\n                SELECT p.*\n                FROM plants p\n                JOIN player_inventory inv ON inv.item_id = p.seed_item_id AND inv.user_id = ? AND inv.quantity > 0\n                WHERE p.is_active = 1\n                ORDER BY p.base_buy_price ASC, p.plant_id ASC\n                LIMIT 1\n            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $plant = $stmt->get_result()->fetch_assoc();
            if ($plant) {
                $stmt = $db->prepare("\n                    SELECT gp.x_pos, gp.y_pos\n                    FROM garden_plots gp\n                    WHERE gp.garden_id = ? AND gp.is_unlocked = 1 AND gp.is_tilled = 1\n                    ORDER BY gp.y_pos ASC, gp.x_pos ASC\n                ");
                $stmt->bind_param('i', $gardenId);
                $stmt->execute();
                $plots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($plots as $plot) {
                    $place = canPlacePlant($db, $gardenId, (int)$plant['plant_id'], (int)$plot['x_pos'], (int)$plot['y_pos']);
                    if (!$place['ok']) continue;
                    if (!removeInventory($db, $userId, (int)$plant['seed_item_id'], 1)) break;
                    $x = (int)$plot['x_pos'];
                    $y = (int)$plot['y_pos'];
                    $clock = getGameClock($db, $userId);
                    $plantCycleIndex = cycleIndexForElapsedHours((float)$clock['total_game_hours_elapsed'], (int)$plant['cycle_hour']);
                    $stmt = $db->prepare("INSERT INTO planted_crops (user_id, garden_id, plant_id, origin_x, origin_y, water_current, last_cycle_index) VALUES (?, ?, ?, ?, ?, 0, ?)");
                    $plantId = (int)$plant['plant_id'];
                    $stmt->bind_param('iiiiii', $userId, $gardenId, $plantId, $x, $y, $plantCycleIndex);
                    $stmt->execute();
                    $didWork = true;
                    $targetX = $x;
                    $targetY = $y;
                    break;
                }
            }
        }

        if ($didWork) {
            $xRatio = $targetX === null ? random_int(3000, 7000) / 10000 : min(.92, max(.08, .18 + ($targetX * .08)));
            $yRatio = $targetY === null ? random_int(3000, 7000) / 10000 : min(.92, max(.08, .18 + ($targetY * .08)));
            $stmt = $db->prepare("UPDATE player_helpers SET last_action_at = NOW(), x_ratio = ?, y_ratio = ? WHERE player_helper_id = ? AND user_id = ?");
            $stmt->bind_param('ddii', $xRatio, $yRatio, $helperId, $userId);
            $stmt->execute();
        }
    }
}

function occupiedTilesForGarden(mysqli $db, int $gardenId): array
{
    $stmt = $db->prepare("
        SELECT pc.origin_x, pc.origin_y, p.width, p.height, pc.planted_crop_id
        FROM planted_crops pc
        JOIN plants p ON p.plant_id = pc.plant_id
        WHERE pc.garden_id = ? AND pc.is_harvested = 0
    ");
    $stmt->bind_param('i', $gardenId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $occupied = [];

    foreach ($rows as $row) {
        for ($y = (int) $row['origin_y']; $y < (int) $row['origin_y'] + (int) $row['height']; $y++) {
            for ($x = (int) $row['origin_x']; $x < (int) $row['origin_x'] + (int) $row['width']; $x++) {
                $occupied[$x . ',' . $y] = (int) $row['planted_crop_id'];
            }
        }
    }

    return $occupied;
}


function plantAllowedInGarden(array $plant): bool
{
    $gardenCode = (string)($plant['garden_type_code'] ?? 'farm');
    $gardenId = (int)($plant['current_garden_type_id'] ?? $plant['garden_type_id'] ?? 0);
    $raw = $plant['allowed_garden_types_json'] ?? null;
    if ($raw !== null && $raw !== '') {
        $allowed = json_decode((string)$raw, true);
        if (is_array($allowed)) {
            if (in_array(0, $allowed, true) || in_array('0', $allowed, true)) return true;
            if ($gardenId > 0 && (in_array($gardenId, $allowed, true) || in_array((string)$gardenId, $allowed, true))) return true;
            if (in_array($gardenCode, $allowed, true)) return true;
            return false;
        }
    }
    $legacy = (string)($plant['allowed_garden_type_code'] ?? 'farm');
    return $legacy === 'all' || $legacy === '0' || $legacy === $gardenCode;
}

function canPlacePlant(mysqli $db, int $gardenId, int $plantId, int $x, int $y): array
{
    $stmt = $db->prepare("
        SELECT p.*, gt.garden_type_id AS current_garden_type_id, gt.code AS garden_type_code, g.max_width, g.max_height
        FROM plants p
        JOIN gardens g ON g.garden_id = ?
        JOIN garden_types gt ON gt.garden_type_id = g.garden_type_id
        WHERE p.plant_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $gardenId, $plantId);
    $stmt->execute();
    $plant = $stmt->get_result()->fetch_assoc();

    if (!$plant) {
        return ['ok' => false, 'error' => 'Plant not found.'];
    }

    if (!plantAllowedInGarden($plant)) {
        return ['ok' => false, 'error' => 'This crop cannot grow in this garden type.'];
    }

    $width = (int) $plant['width'];
    $height = (int) $plant['height'];

    if ($x < 1 || $y < 1 || ($x + $width - 1) > (int) $plant['max_width'] || ($y + $height - 1) > (int) $plant['max_height']) {
        return ['ok' => false, 'error' => 'Crop does not fit inside the garden.'];
    }

    $occupied = occupiedTilesForGarden($db, $gardenId);

    for ($yy = $y; $yy < $y + $height; $yy++) {
        for ($xx = $x; $xx < $x + $width; $xx++) {
            if (isset($occupied[$xx . ',' . $yy])) {
                return ['ok' => false, 'error' => 'One or more plots are occupied.'];
            }

            $stmt = $db->prepare("SELECT is_unlocked, is_tilled FROM garden_plots WHERE garden_id = ? AND x_pos = ? AND y_pos = ? LIMIT 1");
            $stmt->bind_param('iii', $gardenId, $xx, $yy);
            $stmt->execute();
            $plot = $stmt->get_result()->fetch_assoc();

            if (!$plot || !(int) $plot['is_unlocked']) {
                return ['ok' => false, 'error' => 'One or more plots are locked.'];
            }

            if (!(int) $plot['is_tilled']) {
                return ['ok' => false, 'error' => 'One or more plots are not tilled.'];
            }
        }
    }

    return ['ok' => true, 'plant' => $plant];
}


function playerHasSeeds(mysqli $db, int $userId): bool
{
    $stmt = $db->prepare("
        SELECT inv.inventory_id
        FROM player_inventory inv
        JOIN items i ON i.item_id = inv.item_id
        WHERE inv.user_id = ? AND i.item_type = 'seed' AND inv.quantity > 0
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

function playerCanAffordAnySeed(mysqli $db, int $userId): bool
{
    $stmt = $db->prepare("SELECT coins FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) return false;

    $min = $db->query("SELECT MIN(base_buy_price) AS min_price FROM items WHERE item_type = 'seed' AND is_active = 1")->fetch_assoc();
    return (int) $user['coins'] >= (int) $min['min_price'];
}

function ensureRescuePouch(mysqli $db, int $userId, int $gardenId): void
{
    $stmt = $db->prepare("SELECT pouch_id FROM player_pouches WHERE user_id = ? AND is_claimed = 0 LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) return;

    $stmt = $db->prepare("SELECT last_pouch_at FROM player_state WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();

    $last = $state && $state['last_pouch_at'] ? strtotime($state['last_pouch_at']) : 0;
    $desperate = !playerHasSeeds($db, $userId) && !playerCanAffordAnySeed($db, $userId);
    $interval = $desperate ? 60 : 300;

    if (time() - $last < $interval) return;

    $x = random_int(1200, 8800) / 10000;
    $y = random_int(1200, 8800) / 10000;
    $seedCount = random_int(1, 3);

    $stmt = $db->prepare("
        INSERT INTO player_pouches (user_id, garden_id, x_ratio, y_ratio, seed_count, visual_state, visible_at)
        VALUES (?, ?, ?, ?, ?, 'arriving', NOW())
    ");
    $stmt->bind_param('iiddi', $userId, $gardenId, $x, $y, $seedCount);
    $stmt->execute();
}


function ensureFaeOffering(mysqli $db, int $userId, int $gardenId): void
{
    // Guard: migration may not have been applied yet
    if (!columnExists($db, 'player_pouches', 'pouch_type')) return;

    // Count fully grown plants in this garden
    $cyclesSql = plantCyclesSql($db, 'p');
    $stmt = $db->prepare("
        SELECT COUNT(*) AS c
        FROM planted_crops pc
        JOIN plants p ON p.plant_id = pc.plant_id
        WHERE pc.user_id = ? AND pc.garden_id = ? AND pc.is_harvested = 0
          AND pc.growth_step_current >= {$cyclesSql}
    ");
    $stmt->bind_param('ii', $userId, $gardenId);
    $stmt->execute();
    $matureCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    if ($matureCount === 0) return;

    // Don't stack — one collectible at a time
    $stmt = $db->prepare("SELECT pouch_id FROM player_pouches WHERE user_id = ? AND is_claimed = 0 LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) return;

    // Once per game-day interval (separate from farm seed pouch timer)
    $config = getGameConfig($db);
    $dayLength = max(60, (int)($config['day_length_seconds'] ?? 720));
    $useLastFaeColumn = columnExists($db, 'player_state', 'last_fae_offering_at');
    $lastCol = $useLastFaeColumn ? 'last_fae_offering_at' : 'last_pouch_at';
    $stmt = $db->prepare("SELECT {$lastCol} AS last_at FROM player_state WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $last = ($row && $row['last_at']) ? strtotime($row['last_at']) : 0;
    if (time() - $last < $dayLength) return;

    // 50% chance per mature plant; update timer regardless so we don't re-roll every page load
    $count = 0;
    for ($i = 0; $i < $matureCount; $i++) {
        if (random_int(0, 1) === 1) $count++;
    }
    $updateCol = $useLastFaeColumn ? 'last_fae_offering_at' : 'last_pouch_at';
    $stmt = $db->prepare("UPDATE player_state SET {$updateCol} = NOW() WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    if ($count === 0) return;
    $count = min($count, 5);

    $x = random_int(1200, 8800) / 10000;
    $y = random_int(1200, 8800) / 10000;
    $stmt = $db->prepare("
        INSERT INTO player_pouches (user_id, garden_id, x_ratio, y_ratio, seed_count, pouch_type, visual_state, visible_at)
        VALUES (?, ?, ?, ?, ?, 'fae_offering', 'arriving', NOW())
    ");
    $stmt->bind_param('iiddi', $userId, $gardenId, $x, $y, $count);
    $stmt->execute();
}

function randomOrderCode(): string
{
    $alphabet = ['ᚠ','ᚢ','ᚦ','ᚨ','ᚱ','ᚲ','ᚷ','ᚹ','ᛃ','ᛈ','ᛉ','ᛟ'];
    $out = '';
    for ($i = 0; $i < 5; $i++) $out .= $alphabet[array_rand($alphabet)];
    return $out . '-' . random_int(10, 99);
}

function randomOrderCustomerName(mysqli $db): string
{
    $first = $db->query("SELECT value FROM order_name_parts WHERE part_type='first' AND is_active=1 ORDER BY RAND() LIMIT 1")->fetch_assoc()['value'] ?? 'Moss';
    $last = $db->query("SELECT value FROM order_name_parts WHERE part_type='last' AND is_active=1 ORDER BY RAND() LIMIT 1")->fetch_assoc()['value'] ?? 'Person';
    $business = $db->query("SELECT value FROM order_name_parts WHERE part_type='business' AND is_active=1 ORDER BY RAND() LIMIT 1")->fetch_assoc()['value'] ?? 'Odd Produce Shop';
    $mode = random_int(1, 3);
    if ($mode === 1) return $first . ' ' . $last;
    if ($mode === 2) return $first . "'s " . $business;
    return $business;
}


function getPlayerProgress(mysqli $db, int $userId): array
{
    $stmt = $db->prepare("SELECT reputation, recognition FROM player_state WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return [
        'reputation' => (int)($row['reputation'] ?? 0),
        'recognition' => (int)($row['recognition'] ?? 0)
    ];
}

function ensurePlayerProgressDefaults(mysqli $db, int $userId): void
{
    // Columns are created by schema_v0_4_0.sql / fresh schema.sql.
    $stmt = $db->prepare("UPDATE player_state SET reputation = COALESCE(reputation, 0), recognition = COALESCE(recognition, 0) WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    @$stmt->execute();
}

function hasUnlock(mysqli $db, int $userId, string $unlockKey): bool
{
    $stmt = $db->prepare("SELECT unlock_id FROM player_unlocks WHERE user_id = ? AND unlock_key = ? LIMIT 1");
    $stmt->bind_param('is', $userId, $unlockKey);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function grantUnlock(mysqli $db, int $userId, string $unlockKey, string $source = 'system'): void
{
    $stmt = $db->prepare("INSERT IGNORE INTO player_unlocks (user_id, unlock_key, source) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $userId, $unlockKey, $source);
    $stmt->execute();
}

function ensureStartingUnlocks(mysqli $db, int $userId): void
{
    // location_map is always granted (used internally even though there's no map_location_config row for it)
    grantUnlock($db, $userId, 'location_map', 'starting_state');

    // Grant unlocks for every location flagged as default-unlocked in the database
    if (tableExists($db, 'map_location_config') && columnExists($db, 'map_location_config', 'is_unlocked_by_default')) {
        $res = $db->query("SELECT unlock_key FROM map_location_config WHERE is_unlocked_by_default = 1 AND is_active = 1 AND unlock_key IS NOT NULL AND unlock_key <> ''");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                grantUnlock($db, $userId, (string)$row['unlock_key'], 'starting_state');
            }
        }
    } else {
        // Fallback until migration is applied
        foreach (['location_garden', 'location_shop', 'orders_board', 'location_caravan'] as $key) {
            grantUnlock($db, $userId, $key, 'starting_state');
        }
    }
}

function getOrderSlotLimit(mysqli $db, int $userId): int
{
    $progress = getPlayerProgress($db, $userId);
    if ($progress['reputation'] >= 25) return 4;
    if ($progress['reputation'] >= 10) return 3;
    return 2;
}

function configInt(array $config, string $key, int $default): int
{
    return isset($config[$key]) ? (int)$config[$key] : $default;
}


function plantCyclesSql(mysqli $db, string $alias = 'p'): string
{
    return columnExists($db, 'plants', 'max_cycles') ? "$alias.max_cycles" : "$alias.growth_steps";
}

function getAvailableOrderLimit(mysqli $db): int
{
    $config = getGameConfig($db);
    return max(1, configInt($config, 'max_available_orders', 5));
}

function cleanupDeadOrders(mysqli $db, int $userId): void
{
    if (!tableExists($db, 'player_orders')) return;
    $stmt = $db->prepare("DELETE FROM player_orders WHERE user_id = ? AND (order_status IN ('expired','cancelled') OR is_expired = 1 OR (order_status = 'available' AND expires_at < NOW()))");
    if (!$stmt) return;
    $stmt->bind_param('i', $userId);
    @$stmt->execute();
}

function cleanupClaimedPouches(mysqli $db, int $userId): void
{
    // player_pouches is a queue, not a log. Claimed rows should not accumulate.
    $stmt = $db->prepare("DELETE FROM player_pouches WHERE user_id = ? AND is_claimed = 1");
    if (!$stmt) return;
    $stmt->bind_param('i', $userId);
    @$stmt->execute();
}

function randomMinuteRange(array $config, string $minKey, string $maxKey, int $minDefault, int $maxDefault): int
{
    $min = max(1, configInt($config, $minKey, $minDefault));
    $max = max($min, configInt($config, $maxKey, $maxDefault));
    return random_int($min, $max);
}

function ensureActiveOrders(mysqli $db, int $userId): void
{
    $config = getGameConfig($db);

    // Available orders expire off the board and are deleted permanently. Accepted orders do NOT disappear; they only become late.
    cleanupDeadOrders($db, $userId);

    // Available orders are only confirmed when the player accepts them. Old available rows simply expire off the board.

    $availableLimit = getAvailableOrderLimit($db);
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM player_orders WHERE user_id=? AND order_status='available'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $available = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

    if ($available >= $availableLimit) {
        $stmt = $db->prepare("UPDATE player_state SET next_order_at = NULL WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return;
    }

    $stmt = $db->prepare("SELECT next_order_at FROM player_state WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $nextOrderAt = $row['next_order_at'] ?? null;

    if (!$nextOrderAt) {
        $delay = randomMinuteRange($config, 'order_refill_min_minutes', 'order_refill_max_minutes', 1, 1);
        $stmt = $db->prepare("UPDATE player_state SET next_order_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE user_id = ?");
        $stmt->bind_param('ii', $delay, $userId);
        $stmt->execute();
        return;
    }

    if (strtotime($nextOrderAt) > time()) {
        return;
    }

    createRandomOrder($db, $userId);
    $available++;

    if ($available < $availableLimit) {
        $delay = randomMinuteRange($config, 'order_refill_min_minutes', 'order_refill_max_minutes', 1, 1);
        $stmt = $db->prepare("UPDATE player_state SET next_order_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE user_id = ?");
        $stmt->bind_param('ii', $delay, $userId);
    } else {
        $stmt = $db->prepare("UPDATE player_state SET next_order_at = NULL WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
    }
    $stmt->execute();
}


function ensureActiveOrder(mysqli $db, int $userId): void
{
    ensureActiveOrders($db, $userId);
}

function createRandomOrder(mysqli $db, int $userId): void
{
    $config = getGameConfig($db);
    $progress = getPlayerProgress($db, $userId);
    $rep = (int)$progress['reputation'];
    $tier = 0;
    if ($rep >= 20) $tier = 3;
    elseif ($rep >= 12) $tier = 2;
    elseif ($rep >= 5)  $tier = 1;
    $item = $db->query("SELECT item_id, base_sell_price FROM items WHERE item_type='produce' AND is_active=1 AND COALESCE(order_tier,0) <= {$tier} ORDER BY base_sell_price ASC, RAND() LIMIT 1")->fetch_assoc();
    if (!$item) return;

    $qty = random_int(2, 6);
    $isRush = random_int(1, 100) <= 25;
    if ($isRush) {
        $fulfillmentMinutes = randomMinuteRange($config, 'order_rush_min_minutes', 'order_rush_max_minutes', 15, 30);
        $orderType = 'rush';
        $rushBonusPercent = max(0, min(100, configInt($config, 'order_rush_bonus_percent', 20)));
        $rushMultiplier = 1 + ($rushBonusPercent / 100);
        $reputationReward = 1;
    } else {
        $fulfillmentMinutes = randomMinuteRange($config, 'order_normal_min_minutes', 'order_normal_max_minutes', 30, 120);
        $orderType = $fulfillmentMinutes >= 90 ? 'patient' : 'standard';
        $rushMultiplier = $fulfillmentMinutes <= 45 ? 1.25 : 1.0;
        $reputationReward = 1;
    }

    $boardMinutes = randomMinuteRange($config, 'order_board_min_minutes', 'order_board_max_minutes', 3, 8);
    $lateFeePercent = max(0, min(90, configInt($config, 'order_late_fee_percent', 20)));
    $basePayment = max(8, ((int)$item['base_sell_price'] * $qty) + random_int(4, 12));
    $payment = (int)ceil($basePayment * $rushMultiplier);
    $code = randomOrderCode();
    $customer = randomOrderCustomerName($db);

    $itemId = (int)$item['item_id'];
    $stmt = $db->prepare("INSERT INTO player_orders (user_id, order_code, customer_name, base_payment_coins, payment_coins, reputation_reward, recognition_reward, order_type, order_status, fulfillment_minutes, expires_at, cancel_reputation_penalty, late_fee_percent, item_id, quantity_required) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'available', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 1, ?, ?, ?)");
    $stmt->bind_param('issiiisiiiii', $userId, $code, $customer, $basePayment, $payment, $reputationReward, $orderType, $fulfillmentMinutes, $boardMinutes, $lateFeePercent, $itemId, $qty);
    $stmt->execute();
}


function getMarketStatus(mysqli $db, int $userId): array
{
    $clock = getGameClock($db, $userId);
    $absoluteDay = max(1, (int)($clock['absolute_day'] ?? 1));
    $hour = (int)($clock['hour'] ?? 0);
    $minute = (int)($clock['minute'] ?? 0);
    $dayLength = max(60, (int)($clock['day_length_seconds'] ?? 720));

    // Match the client calendar: 0 = Sunday, 1 = Monday, ... 6 = Saturday.
    // The Fae Market opens Saturday 06:00 and stays open continuously until Sunday 18:00.
    $weekIndex = ($absoluteDay - 1) % 7;
    $openHour = 6;
    $closeHour = 18;

    $openOffset = (int)round(($openHour / 24) * $dayLength);
    $closeOffset = (int)round(($closeHour / 24) * $dayLength);
    $currentOffset = (int)round((($hour * 60 + $minute) / 1440) * $dayLength);

    $isOpen = ($weekIndex === 6 && $currentOffset >= $openOffset) || ($weekIndex === 0 && $currentOffset < $closeOffset);
    $isWeekend = ($weekIndex === 6 || $weekIndex === 0);
    $phase = ($hour >= 6 && $hour < 18) ? 'day' : 'night';

    if ($isOpen) {
        $secondsRemaining = $weekIndex === 6
            ? max(0, $dayLength + $closeOffset - $currentOffset)
            : max(0, $closeOffset - $currentOffset);
        $label = 'Fae Market closes';
    } else {
        if ($weekIndex >= 1 && $weekIndex <= 5) $daysUntilOpen = 6 - $weekIndex;
        elseif ($weekIndex === 6) $daysUntilOpen = ($currentOffset < $openOffset) ? 0 : 7;
        else $daysUntilOpen = 6;

        $secondsRemaining = ($daysUntilOpen * $dayLength) + $openOffset - $currentOffset;
        if ($secondsRemaining < 0) $secondsRemaining += 7 * $dayLength;
        $label = 'Fae Market opens';
    }

    return [
        'is_open' => $isOpen,
        'is_weekend' => $isWeekend,
        'weekday' => $weekIndex,
        'week_index' => $weekIndex,
        'open_hour' => $openHour,
        'close_hour' => $closeHour,
        'label' => $label,
        'phase' => $phase,
        'seconds_remaining' => $secondsRemaining
    ];
}


function getCaravanStatus(mysqli $db, int $userId): array
{
    $clock = getGameClock($db, $userId);
    $config = getGameConfig($db);
    $absoluteDay = max(1, (int)($clock['absolute_day'] ?? 1));
    $hour = (int)($clock['hour'] ?? 0);
    $minute = (int)($clock['minute'] ?? 0);
    $dayLength = max(60, (int)($clock['day_length_seconds'] ?? 720));
    $weekIndex = ($absoluteDay - 1) % 7; // 0 Sunday, 1 Monday, 2 Tuesday, 3 Wednesday, 4 Thursday
    $weekNumber = (int)floor(($absoluteDay - 1) / 7);
    $offset = (int)($config['caravan_biweekly_offset'] ?? 0);
    $activeWeek = (($weekNumber - $offset) % 2) === 0;
    $isActive = $activeWeek && $weekIndex >= 2 && $weekIndex <= 4;
    $currentOffset = (int)round((($hour * 60 + $minute) / 1440) * $dayLength);

    if ($isActive) {
        // End of Thursday, just before Friday begins.
        $secondsRemaining = ((4 - $weekIndex) * $dayLength) + ($dayLength - $currentOffset);
        $label = 'Caravan leaves';
    } else {
        $daysUntil = 0;
        for ($i = 0; $i < 14; $i++) {
            $candidateAbs = $absoluteDay + $i;
            $candidateWeekIndex = ($candidateAbs - 1) % 7;
            $candidateWeekNumber = (int)floor(($candidateAbs - 1) / 7);
            $candidateActiveWeek = (($candidateWeekNumber - $offset) % 2) === 0;
            if ($candidateActiveWeek && $candidateWeekIndex === 2) {
                $daysUntil = $i;
                break;
            }
        }
        $secondsRemaining = ($daysUntil * $dayLength) - $currentOffset;
        if ($secondsRemaining < 0) $secondsRemaining += 14 * $dayLength;
        $label = 'Caravan arrives';
    }

    $boneBrineReady = (hasUnlock($db, $userId, 'second_relic_collected') || hasUnlock($db, $userId, 'strange_relic_2_collected'))
        && !hasUnlock($db, $userId, 'location_bone_brine');

    return [
        'is_active' => $isActive,
        'weekday' => $weekIndex,
        'active_week' => $activeWeek,
        'seconds_remaining' => max(0, $secondsRemaining),
        'label' => $label,
        'bone_brine_ready' => $boneBrineReady,
        'visitor_key' => ($isActive && $boneBrineReady) ? 'bone_brine' : (($isActive) ? 'standard' : '')
    ];
}

function getShopSellLimits(mysqli $db, int $userId): array
{
    if (!tableExists($db, 'shop_buy_limits')) return [];
    $clock = getGameClock($db, $userId);
    $shopDay = (int)($clock['absolute_day'] ?? 1);
    $shopRowIconSelect = columnExists($db, 'items', 'shop_row_icon') ? "i.shop_row_icon" : "NULL AS shop_row_icon";
    $stmt = $db->prepare("\n        SELECT i.item_id, i.code, i.name, i.icon, {$shopRowIconSelect}, i.base_sell_price, sbl.daily_limit,\n               COALESCE(sold.quantity_sold, 0) AS quantity_sold,\n               GREATEST(0, sbl.daily_limit - COALESCE(sold.quantity_sold, 0)) AS remaining_quantity\n        FROM shop_buy_limits sbl\n        JOIN items i ON i.item_id = sbl.item_id\n        LEFT JOIN player_shop_sales sold\n          ON sold.user_id = ? AND sold.item_id = i.item_id AND sold.shop_day = ?\n        WHERE sbl.is_active = 1 AND i.is_active = 1\n        ORDER BY i.base_sell_price ASC, i.name ASC\n    ");
    if (!$stmt) return [];
    $stmt->bind_param('ii', $userId, $shopDay);
    if (!@$stmt->execute()) return [];
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function eventHasFirstStep(mysqli $db, string $eventKey): bool
{
    if (!tableExists($db, 'events') || !tableExists($db, 'event_steps')) return false;
    $stmt = $db->prepare("\n        SELECT e.event_id\n        FROM events e\n        JOIN event_steps es ON es.event_id = e.event_id AND es.step_order = 1\n        WHERE e.event_key = ? AND e.is_active = 1\n        LIMIT 1\n    ");
    if (!$stmt) return false;
    $stmt->bind_param('s', $eventKey);
    if (!@$stmt->execute()) return false;
    return (bool)$stmt->get_result()->fetch_assoc();
}

function getLocationsForPlayer(mysqli $db, int $userId): array
{
    // Guard: migration may not have run yet
    if (!tableExists($db, 'map_location_config') || !columnExists($db, 'map_location_config', 'unlock_key')) {
        return [];
    }

    $rows = $db->query("
        SELECT location_key, display_name, hint, unlock_key, is_unlocked_by_default,
               map_icon, active_map_icon, inactive_map_icon
        FROM map_location_config
        WHERE is_active = 1
        ORDER BY sort_order ASC, map_location_config_id ASC
    ")->fetch_all(MYSQLI_ASSOC);

    $unlocks = [];
    $stmt = $db->prepare("SELECT unlock_key FROM player_unlocks WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $unlocks[$row['unlock_key']] = true;

    $progress   = getPlayerProgress($db, $userId);
    $marketStatus  = getMarketStatus($db, $userId);
    $caravanStatus = getCaravanStatus($db, $userId);

    $locations = [];
    foreach ($rows as $row) {
        $key       = (string)$row['location_key'];
        $unlockKey = $row['unlock_key'] !== null ? (string)$row['unlock_key'] : null;
        $unlocked  = $unlockKey === null || !empty($unlocks[$unlockKey]);

        $def = [
            'key'      => $key,
            'name'     => (string)($row['display_name'] ?: $key),
            'icon'     => (string)($row['map_icon'] ?? ''),
            'hint'     => (string)($row['hint'] ?? ''),
            'unlocked' => $unlocked,
            'disabled' => false,
        ];

        // ---- Per-location dynamic enrichment ----

        if ($key === 'caravan') {
            $def['caravan_status'] = $caravanStatus;
            $inactiveIcon = $row['inactive_map_icon'] ?: ($row['map_icon'] ?? '');
            $activeIcon   = $row['active_map_icon']  ?: $inactiveIcon;
            $def['icon']  = !empty($caravanStatus['is_active']) ? $activeIcon : $inactiveIcon;
            if ($unlocked && empty($caravanStatus['is_active'])) {
                $def['hint'] = 'The caravan camp is empty right now.';
            } elseif ($unlocked && !empty($caravanStatus['bone_brine_ready'])) {
                $def['hint'] = 'A Bone & Brine caravan is waiting at the camp.';
            } elseif ($unlocked) {
                $def['hint'] = 'The caravan has arrived with temporary visitors.';
            }
        }

        if ($key === 'market') {
            $def['is_open']      = (bool)$marketStatus['is_open'];
            $def['market_status'] = $marketStatus;
            if ($unlocked && !$marketStatus['is_open']) {
                $def['disabled'] = true;
                $def['hint'] = 'The Fae Market is not open right now.';
            } elseif ($unlocked) {
                $def['hint'] = 'The Fae Market is open. They buy crops, seeds, weeds, bugs, and other oddities.';
            } elseif ($progress['reputation'] >= 10) {
                $def['hint'] = 'The shopkeeper may have something to say at the General Store.';
            }
        }

        $locations[] = $def;
    }

    return $locations;
}

function maybeGrantMarketInvite(mysqli $db, int $userId): void
{
    // v0.4.16d: reputation no longer silently unlocks the Fae Market.
    // It now creates a location-driven event marker handled by getLocationEvents().
}

function evaluateTriggerConditions(mysqli $db, int $userId, ?string $conditionsJson, array $context = []): bool
{
    if (!$conditionsJson) return true;
    $conds = json_decode($conditionsJson, true);
    if (!is_array($conds)) return true;

    foreach (($conds['flags'] ?? []) as $key => $required) {
        $has = hasUnlock($db, $userId, (string)$key);
        if ((bool)$required !== $has) return false;
    }

    if (isset($conds['min_reputation'])) {
        $rep = $context['reputation'] ?? null;
        if ($rep === null) { $p = getPlayerProgress($db, $userId); $rep = $p['reputation']; }
        if ((int)$rep < (int)$conds['min_reputation']) return false;
    }

    if (isset($conds['min_recognition'])) {
        $rec = $context['recognition'] ?? null;
        if ($rec === null) { $p = getPlayerProgress($db, $userId); $rec = $p['recognition']; }
        if ((int)$rec < (int)$conds['min_recognition']) return false;
    }

    if (!empty($conds['caravan_active']) && empty($context['caravan_active'])) return false;
    if (!empty($conds['caravan_bone_brine_ready']) && empty($context['caravan_bone_brine_ready'])) return false;

    if (!empty($conds['inventory_has'])) {
        foreach ($conds['inventory_has'] as $code => $qty) {
            $stmt = $db->prepare("SELECT COALESCE(inv.quantity, 0) AS q FROM items i LEFT JOIN player_inventory inv ON inv.item_id = i.item_id AND inv.user_id = ? WHERE i.code = ? LIMIT 1");
            $stmt->bind_param('is', $userId, $code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row || (int)$row['q'] < (int)$qty) return false;
        }
    }

    return true;
}

function getLocationEvents(mysqli $db, int $userId): array
{
    $events = [];
    if (!tableExists($db, 'event_triggers') || !columnExists($db, 'event_triggers', 'location_key')) {
        return $events;
    }

    $progress = getPlayerProgress($db, $userId);
    $caravanStatus = getCaravanStatus($db, $userId);
    $context = [
        'reputation'               => (int)$progress['reputation'],
        'recognition'              => (int)$progress['recognition'],
        'caravan_active'           => !empty($caravanStatus['is_active']),
        'caravan_bone_brine_ready' => !empty($caravanStatus['bone_brine_ready']),
    ];

    $stmt = $db->prepare("
        SELECT et.trigger_id, et.event_id, et.location_key, et.ui_tooltip,
               et.trigger_conditions_json, et.is_repeatable,
               e.event_key, e.title AS event_title
        FROM event_triggers et
        JOIN events e ON e.event_id = et.event_id
        WHERE et.trigger_type = 'location'
          AND et.is_active = 1
          AND e.is_active = 1
          AND et.location_key IS NOT NULL
        ORDER BY et.priority ASC, et.trigger_id ASC
    ");
    if (!@$stmt->execute()) return $events;
    $triggers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($triggers as $trigger) {
        $eventKey  = (string)$trigger['event_key'];
        $locationKey = (string)$trigger['location_key'];
        $eventId   = (int)$trigger['event_id'];

        if (!eventHasFirstStep($db, $eventKey)) continue;

        // Skip if this event is already active or complete for this player
        $stmt2 = $db->prepare("SELECT status FROM player_event_state WHERE user_id = ? AND event_id = ? ORDER BY player_event_state_id DESC LIMIT 1");
        $stmt2->bind_param('ii', $userId, $eventId);
        $stmt2->execute();
        $existing = $stmt2->get_result()->fetch_assoc();
        if ($existing && in_array($existing['status'], ['active', 'complete'], true)) continue;

        if (!evaluateTriggerConditions($db, $userId, $trigger['trigger_conditions_json'] ?? null, $context)) continue;

        $events[] = [
            'event_key'   => $eventKey,
            'location_key' => $locationKey,
            'title'       => (string)$trigger['event_title'],
            'tooltip'     => (string)($trigger['ui_tooltip'] ?? 'Something is happening here.'),
            'icon'        => systemIconByCode($db, 'quest_available', '!')
        ];
    }

    return $events;
}

function canStartLocationEvent(mysqli $db, int $userId, string $eventKey, string $locationKey): bool
{
    foreach (getLocationEvents($db, $userId) as $event) {
        if ($event['event_key'] === $eventKey && $event['location_key'] === $locationKey) return true;
    }
    return false;
}

function systemIconByCode(mysqli $db, string $code, string $fallback): string
{
    $stmt = $db->prepare("SELECT icon FROM items WHERE code = ? AND item_type = 'system' AND is_active = 1 LIMIT 1");
    if (!$stmt) return $fallback;
    $stmt->bind_param('s', $code);
    if (!@$stmt->execute()) return $fallback;
    $row = $stmt->get_result()->fetch_assoc();
    return $row['icon'] ?? $fallback;
}

function scheduleMadamRuneVisit(mysqli $db, int $userId): void
{
    $clock = getGameClock($db, $userId);
    $targetDay = (int)$clock['day'] + 1;
    $dayLength = max(60, (int)$clock['day_length_seconds']);

    $stmt = $db->prepare("SELECT started_at FROM player_state WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $startedAt = $row ? strtotime($row['started_at']) : time();

    // Noon on the next in-game day. If the player logs out, this still fires on the next load after that point.
    $targetTimestamp = $startedAt + (int)round((($targetDay - 1) + 0.5) * $dayLength);
    $visitAt = date('Y-m-d H:i:s', $targetTimestamp);

    $stmt = $db->prepare("UPDATE player_state SET madam_rune_visit_at = ? WHERE user_id = ?");
    $stmt->bind_param('si', $visitAt, $userId);
    @$stmt->execute();
}

function maybeFindFirstRelicFromTilling(mysqli $db, int $userId, int $plotId, array $tool): ?string
{
    if (($tool['code'] ?? '') !== 'wooden_hoe') return null;
    if (hasUnlock($db, $userId, 'first_relic_spawned') || hasUnlock($db, $userId, 'first_relic_collected')) return null;

    $stmt = $db->prepare("SELECT gp.is_tilled FROM garden_plots gp JOIN gardens g ON g.garden_id = gp.garden_id WHERE gp.plot_id = ? AND g.user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $plotId, $userId);
    $stmt->execute();
    $plot = $stmt->get_result()->fetch_assoc();
    if (!$plot || !(int)$plot['is_tilled']) return null;

    $displayName = 'Strange Buried Relic';
    $x = random_int(1800, 8200) / 10000;
    $y = random_int(1800, 8200) / 10000;

    $stmt = $db->prepare("INSERT INTO player_relics (user_id, relic_key, display_name, relic_type, source_action, x_ratio, y_ratio, visual_state) VALUES (?, 'first_field_relic', ?, 'rune_vessel', 'till', ?, ?, 'waiting')");
    $stmt->bind_param('isdd', $userId, $displayName, $x, $y);
    $stmt->execute();

    grantUnlock($db, $userId, 'first_relic_spawned', 'wooden_hoe_till');
    return $displayName;
}

function maybeDropCommonRelic(mysqli $db, int $userId): bool
{
    if (random_int(1, 100) > 3) return false;
    $item = $db->query("SELECT item_id FROM items WHERE code = 'relic_fragment' AND is_active = 1 LIMIT 1")->fetch_assoc();
    if (!$item) return false;
    addInventory($db, $userId, (int)$item['item_id'], 1);
    return true;
}

function maybeFindSecondRelicFromTilling(mysqli $db, int $userId, int $plotId): bool
{
    if (!hasUnlock($db, $userId, 'ornamental_garden_unlocked')) return false;
    if (hasUnlock($db, $userId, 'second_relic_spawned') || hasUnlock($db, $userId, 'second_relic_collected')) return false;
    if (random_int(1, 100) > 5) return false;

    $stmt = $db->prepare("SELECT gp.is_tilled FROM garden_plots gp JOIN gardens g ON g.garden_id = gp.garden_id WHERE gp.plot_id = ? AND g.user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $plotId, $userId);
    $stmt->execute();
    $plot = $stmt->get_result()->fetch_assoc();
    if (!$plot || !(int)$plot['is_tilled']) return false;

    $x = random_int(1800, 8200) / 10000;
    $y = random_int(1800, 8200) / 10000;
    $stmt = $db->prepare("INSERT INTO player_relics (user_id, relic_key, display_name, relic_type, source_action, x_ratio, y_ratio, visual_state) VALUES (?, 'second_field_relic', 'Strange Buried Relic', 'rune_vessel', 'till', ?, ?, 'waiting')");
    $stmt->bind_param('idd', $userId, $x, $y);
    $stmt->execute();
    grantUnlock($db, $userId, 'second_relic_spawned', 'till_action');
    return true;
}

function getActiveRelicPickup(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare("SELECT * FROM player_relics WHERE user_id = ? AND relic_key IN ('first_field_relic','second_field_relic') AND collected_at IS NULL ORDER BY discovered_at ASC LIMIT 1");
    $stmt->bind_param('i', $userId);
    @$stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function getPendingStoryEvent(mysqli $db, int $userId): ?array
{
    $active = getPlayerStoryEvent($db, $userId);
    if ($active) return $active;

    if (hasUnlock($db, $userId, 'first_relic_collected') && !hasUnlock($db, $userId, 'madam_rune_intro_seen')) {
        $stmt = $db->prepare("SELECT madam_rune_visit_at FROM player_state WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        @$stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $visitAt = $row['madam_rune_visit_at'] ?? null;
        if ($visitAt && strtotime($visitAt) <= time()) {
            return startPlayerEvent($db, $userId, 'madam_rune_intro');
        }
    }

    return null;
}

function randomRelicName(): string
{
    $a = ['Whispering','Cracked','Moon-Stained','Drowsy','Crooked','Rootbound','Singing','Moss-Eaten'];
    $b = ['Button','Thimble','Spoon','Acorn','Key','Pebble','Bell Shard','Teacup'];
    $c = ['Old Rain','the Rootwife','Forgetful Soil','the Third Pantry','Little Storms','Buried Luck','Garden Secrets','Lost Breakfast'];
    return $a[array_rand($a)] . ' ' . $b[array_rand($b)] . ' of ' . $c[array_rand($c)];
}

function getShopRefresh(mysqli $db, int $userId): array
{
    $config = getGameConfig($db);
    $dayLength = max(60, (int)($config['day_length_seconds'] ?? 720));
    $targetHour = 7;

    $stmt = $db->prepare("SELECT started_at, next_shop_refresh_at FROM player_state WHERE user_id=? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $startedAt = $row ? strtotime($row['started_at']) : time();
    $elapsed = max(0, time() - $startedAt);
    $dayIndex = (int)floor($elapsed / $dayLength);
    $targetOffset = (int)round(($targetHour / 24) * $dayLength);
    $candidate = $startedAt + ($dayIndex * $dayLength) + $targetOffset;
    if ($candidate <= time()) {
        $candidate += $dayLength;
    }

    $next = date('Y-m-d H:i:s', $candidate);
    if (($row['next_shop_refresh_at'] ?? null) !== $next) {
        $stmt = $db->prepare("UPDATE player_state SET next_shop_refresh_at = ? WHERE user_id=?");
        $stmt->bind_param('si', $next, $userId);
        $stmt->execute();
    }

    return [
        'next_refresh_at' => $next,
        'seconds_remaining' => max(0, $candidate - time()),
        'refresh_minutes' => (int)round($dayLength / 60),
        'refresh_game_hour' => $targetHour
    ];
}

function tableExists(mysqli $db, string $tableName): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('s', $tableName);
    if (!$stmt->execute()) return false;
    return (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
}

function getEventByKey(mysqli $db, string $eventKey): ?array
{
    if (!tableExists($db, 'events')) return null;
    $stmt = $db->prepare("SELECT * FROM events WHERE event_key = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $eventKey);
    if (!@$stmt->execute()) return null;
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function startPlayerEvent(mysqli $db, int $userId, string $eventKey): ?array
{
    $event = getEventByKey($db, $eventKey);
    if (!$event) return null;
    $eventId = (int)$event['event_id'];

    $stmt = $db->prepare("SELECT * FROM player_event_state WHERE user_id = ? AND event_id = ? AND status IN ('pending','active') LIMIT 1");
    $stmt->bind_param('ii', $userId, $eventId);
    if (@$stmt->execute()) {
        $existing = $stmt->get_result()->fetch_assoc();
        if ($existing) return getPlayerStoryEvent($db, $userId);
    }

    $stmt = $db->prepare("SELECT player_event_state_id FROM player_event_state WHERE user_id = ? AND event_id = ? AND status = 'complete' LIMIT 1");
    $stmt->bind_param('ii', $userId, $eventId);
    if (@$stmt->execute() && $stmt->get_result()->fetch_assoc()) {
        return null;
    }

    $stmt = $db->prepare("INSERT INTO player_event_state (user_id, event_id, current_step_order, status) VALUES (?, ?, 1, 'active')");
    $stmt->bind_param('ii', $userId, $eventId);
    @$stmt->execute();
    return getPlayerStoryEvent($db, $userId);
}

function getPlayerStoryEvent(mysqli $db, int $userId): ?array
{
    if (!tableExists($db, 'player_event_state') || !tableExists($db, 'event_steps')) return null;
    $stmt = $db->prepare("\n        SELECT pes.player_event_state_id, pes.current_step_order, e.event_id, e.event_key, e.title AS event_title,\n               es.step_id, es.step_order, es.speaker_name, es.title, es.body_html, es.button_text, es.background_image, es.portrait_image\n        FROM player_event_state pes\n        JOIN events e ON e.event_id = pes.event_id\n        JOIN event_steps es ON es.event_id = e.event_id AND es.step_order = pes.current_step_order\n        WHERE pes.user_id = ? AND pes.status = 'active' AND e.is_active = 1\n        ORDER BY pes.started_at ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $userId);
    if (!@$stmt->execute()) return null;
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    return [
        'key' => $row['event_key'],
        'event_key' => $row['event_key'],
        'title' => $row['title'],
        'event_title' => $row['event_title'],
        'step_order' => (int)$row['step_order'],
        'speaker_name' => $row['speaker_name'],
        'body_html' => $row['body_html'],
        'button_text' => $row['button_text'] ?: 'Okay',
        'background_image' => $row['background_image'],
        'portrait_image' => $row['portrait_image']
    ];
}

function applyEventEffects(mysqli $db, int $userId, ?string $effectsJson): array
{
    $effects = $effectsJson ? json_decode($effectsJson, true) : null;
    $result = ['rewards' => [], 'removed' => [], 'flags' => []];
    if (!$effects || !is_array($effects)) return $result;

    foreach (($effects['inventory'] ?? []) as $change) {
        $code = (string)($change['code'] ?? '');
        $qty = (int)($change['qty'] ?? 0);
        if ($code === '' || $qty === 0) continue;
        $stmt = $db->prepare("SELECT item_id, code, name, icon FROM items WHERE code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        if (!$item) continue;
        if ($qty > 0) {
            addInventory($db, $userId, (int)$item['item_id'], $qty);
            $result['rewards'][] = ['code'=>$item['code'], 'name'=>$item['name'], 'icon'=>$item['icon'], 'quantity'=>$qty];
        } else {
            removeInventory($db, $userId, (int)$item['item_id'], abs($qty));
            $result['removed'][] = ['code'=>$item['code'], 'quantity'=>abs($qty)];
        }
    }

    foreach (($effects['flags'] ?? []) as $flag => $value) {
        if ($value) {
            grantUnlock($db, $userId, (string)$flag, 'event_effect');
            $result['flags'][] = (string)$flag;
        }
    }

    $recognition = (int)($effects['recognition'] ?? 0);
    if ($recognition !== 0) {
        $stmt = $db->prepare("UPDATE player_state SET recognition = recognition + ? WHERE user_id = ?");
        $stmt->bind_param('ii', $recognition, $userId);
        $stmt->execute();
        $result['recognition'] = $recognition;
    }

    if (($effects['relic_trade'] ?? '') === 'madam_rune') {
        $stmt = $db->prepare("UPDATE player_relics SET traded_at = NOW(), traded_to = 'madam_rune' WHERE user_id = ? AND relic_key = 'first_field_relic' AND traded_at IS NULL");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
    }

    if (!empty($effects['unlock_location'])) {
        grantUnlock($db, $userId, 'location_' . (string)$effects['unlock_location'], 'event_effect');
    }

    if (!empty($effects['garden_type_unlock'])) {
        $gtCode = (string)$effects['garden_type_unlock'];
        $stmt = $db->prepare("INSERT IGNORE INTO player_garden_type_unlocks (user_id, garden_type_id, source) SELECT ?, garden_type_id, 'event_effect' FROM garden_types WHERE code = ? AND is_active = 1");
        $stmt->bind_param('is', $userId, $gtCode);
        $stmt->execute();
    }

    if (!empty($effects['create_garden']) && is_array($effects['create_garden'])) {
        $cfg = $effects['create_garden'];
        $typeCode = (string)($cfg['garden_type_code'] ?? 'farm');
        $gardenName = (string)($cfg['name'] ?? 'Garden');
        $locked = !empty($cfg['locked']) ? 1 : 0;
        $unlockPlots = max(0, (int)($cfg['unlock_plots'] ?? 4));

        // Only create if user doesn't already have a garden of this type
        $stmt = $db->prepare("SELECT g.garden_id FROM gardens g JOIN garden_types gt ON gt.garden_type_id = g.garden_type_id WHERE g.user_id = ? AND gt.code = ? LIMIT 1");
        $stmt->bind_param('is', $userId, $typeCode);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $stmt = $db->prepare("SELECT garden_type_id, COALESCE(max_size, 5) AS max_size FROM garden_types WHERE code = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('s', $typeCode);
            $stmt->execute();
            $gtRow = $stmt->get_result()->fetch_assoc();
            if ($gtRow) {
                $gardenTypeId = (int)$gtRow['garden_type_id'];
                $maxSize = max(1, (int)$gtRow['max_size']);
                $stmt = $db->prepare("INSERT INTO gardens (user_id, garden_type_id, name, is_type_locked, max_width, max_height) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iisiii', $userId, $gardenTypeId, $gardenName, $locked, $maxSize, $maxSize);
                $stmt->execute();
                $newGardenId = (int)$db->insert_id;

                $stmt = $db->prepare("INSERT INTO garden_plots (garden_id, x_pos, y_pos, is_unlocked, till_progress, is_tilled, unlocked_at) VALUES (?, ?, ?, ?, 0, 0, ?)");
                $plotCount = 0;
                for ($py = 1; $py <= $maxSize; $py++) {
                    for ($px = 1; $px <= $maxSize; $px++) {
                        $unlocked = ($plotCount < $unlockPlots && $px <= 2 && $py <= 2) ? 1 : 0;
                        $unlockedAt = $unlocked ? date('Y-m-d H:i:s') : null;
                        $stmt->bind_param('iiiis', $newGardenId, $px, $py, $unlocked, $unlockedAt);
                        $stmt->execute();
                        if ($unlocked) $plotCount++;
                    }
                }
                $result['new_garden_id'] = $newGardenId;
            }
        }
    }

    if (!empty($effects['summon_helper']) && is_array($effects['summon_helper'])) {
        $helper = $effects['summon_helper'];
        $typeCode = (string)($helper['helper_type'] ?? 'fairy');
        $equipCode = (string)($helper['equipment'] ?? '');
        $task = (string)($helper['task'] ?? 'water');
        $name = (string)($helper['name'] ?? 'Puddlewink');
        $stmt = $db->prepare("SELECT helper_type_id FROM helper_types WHERE code = ? LIMIT 1");
        $stmt->bind_param('s', $typeCode);
        $stmt->execute();
        $type = $stmt->get_result()->fetch_assoc();
        $equipmentId = null;
        if ($equipCode !== '') {
            $stmt = $db->prepare("SELECT helper_equipment_id FROM helper_equipment WHERE code = ? LIMIT 1");
            $stmt->bind_param('s', $equipCode);
            $stmt->execute();
            $equip = $stmt->get_result()->fetch_assoc();
            if ($equip) $equipmentId = (int)$equip['helper_equipment_id'];
        }
        if ($type) {
            $helperTypeId = (int)$type['helper_type_id'];
            $xRatio = random_int(1800, 8200) / 10000;
            $yRatio = random_int(1800, 8200) / 10000;
            if ($equipmentId === null) {
                $task = 'idle';
                $stmt = $db->prepare("INSERT INTO player_helpers (user_id, helper_type_id, helper_name, equipped_helper_equipment_id, active_task, x_ratio, y_ratio) VALUES (?, ?, ?, NULL, ?, ?, ?)");
                $stmt->bind_param('iissdd', $userId, $helperTypeId, $name, $task, $xRatio, $yRatio);
            } else {
                $stmt = $db->prepare("INSERT INTO player_helpers (user_id, helper_type_id, helper_name, equipped_helper_equipment_id, active_task, x_ratio, y_ratio) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iisisdd', $userId, $helperTypeId, $name, $equipmentId, $task, $xRatio, $yRatio);
            }
            $stmt->execute();
            $result['helper'] = ['name'=>$name, 'type'=>$typeCode, 'task'=>$task];
        }
    }

    return $result;
}

function advancePlayerEvent(mysqli $db, int $userId, string $eventKey): array
{
    $stmt = $db->prepare("\n        SELECT pes.player_event_state_id, pes.current_step_order, e.event_id, es.effects_json\n        FROM player_event_state pes\n        JOIN events e ON e.event_id = pes.event_id\n        JOIN event_steps es ON es.event_id = e.event_id AND es.step_order = pes.current_step_order\n        WHERE pes.user_id = ? AND e.event_key = ? AND pes.status = 'active'\n        LIMIT 1\n    ");
    $stmt->bind_param('is', $userId, $eventKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) throw new RuntimeException('No active event found.');

    $effects = applyEventEffects($db, $userId, $row['effects_json'] ?? null);
    $stateId = (int)$row['player_event_state_id'];
    $eventId = (int)$row['event_id'];
    $nextStep = (int)$row['current_step_order'] + 1;

    $stmt = $db->prepare("SELECT step_id FROM event_steps WHERE event_id = ? AND step_order = ? LIMIT 1");
    $stmt->bind_param('ii', $eventId, $nextStep);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt = $db->prepare("UPDATE player_event_state SET current_step_order = ? WHERE player_event_state_id = ? AND user_id = ?");
        $stmt->bind_param('iii', $nextStep, $stateId, $userId);
        $stmt->execute();
        $done = false;
    } else {
        $stmt = $db->prepare("UPDATE player_event_state SET status = 'complete', completed_at = NOW() WHERE player_event_state_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $stateId, $userId);
        $stmt->execute();
        $done = true;
    }

    return ['ok'=>true, 'complete'=>$done, 'effects'=>$effects, 'story_event'=>getPlayerStoryEvent($db, $userId)];
}
