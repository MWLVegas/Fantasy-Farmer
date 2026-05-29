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
        $res = $db->query("SELECT {$select} FROM map_location_config WHERE is_active = 1 ORDER BY sort_order ASC, location_key ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $markers[$row['location_key']] = [
                    'x' => (int)$row['map_x'],
                    'y' => (int)$row['map_y'],
                    'icon' => $row['map_icon'] ?? '',
                    'size' => (int)($row['icon_size'] ?? 78),
                    'glow_color' => $row['glow_color'] ?? 'rgba(255, 214, 94, .78)',
                    'side_menu_html' => $row['side_menu_html'] ?? null
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
        'moon_icon' => $config['moon_icon'],
        'goblin_icon' => $config['goblin_icon'],
        'pouch_icon' => $config['pouch_icon']
    ];
}

function cycleIndexForElapsedHours(float $elapsedHours, int $cycleHour): int
{
    return (int) floor(($elapsedHours - $cycleHour) / 24);
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
    $clock = getGameClock($db, $userId);
    $elapsedGameHours = (float) $clock['total_game_hours_elapsed'];

    $stmt = $db->prepare("
        SELECT pc.*, p.growth_steps, p.cycle_hour, p.water_required, p.water_drain_per_game_hour
        FROM planted_crops pc
        JOIN plants p ON p.plant_id = pc.plant_id
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
        $currentCycle = cycleIndexForElapsedHours($elapsedGameHours, (int) $crop['cycle_hour']);

        if ($currentCycle > $lastCycle && $water >= (int) $crop['water_required'] && !(int) $crop['has_weeds'] && !(int) $crop['has_pests']) {
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
                WHERE pc.user_id = ? AND pc.garden_id = ? AND pc.is_harvested = 0
                  AND pc.water_current < p.water_max
                ORDER BY (pc.water_current / NULLIF(p.water_max, 0)) ASC, pc.planted_crop_id ASC
                LIMIT 1
            " );
            $stmt->bind_param('ii', $userId, $gardenId);
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
            $stmt = $db->prepare("\n                SELECT pc.planted_crop_id, pc.origin_x, pc.origin_y, p.harvest_item_id, p.harvest_min\n                FROM planted_crops pc\n                JOIN plants p ON p.plant_id = pc.plant_id\n                WHERE pc.user_id = ? AND pc.garden_id = ? AND pc.is_harvested = 0\n                  AND pc.growth_step_current >= p.growth_steps\n                ORDER BY pc.planted_crop_id ASC\n                LIMIT 1\n            ");
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
                    $stmt = $db->prepare("INSERT INTO planted_crops (user_id, garden_id, plant_id, origin_x, origin_y, water_current) VALUES (?, ?, ?, ?, ?, 0)");
                    $plantId = (int)$plant['plant_id'];
                    $stmt->bind_param('iiiii', $userId, $gardenId, $plantId, $x, $y);
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

function canPlacePlant(mysqli $db, int $gardenId, int $plantId, int $x, int $y): array
{
    $stmt = $db->prepare("
        SELECT p.*, gt.code AS garden_type_code, g.max_width, g.max_height
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

    if ($plant['allowed_garden_type_code'] !== $plant['garden_type_code']) {
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
    foreach (['location_map','location_garden','location_shop','orders_board'] as $key) {
        grantUnlock($db, $userId, $key, 'starting_state');
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

function getAvailableOrderLimit(mysqli $db): int
{
    $config = getGameConfig($db);
    return max(1, configInt($config, 'max_available_orders', 5));
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

    // Available orders expire off the board. Accepted orders do NOT disappear; they only become late.
    $stmt = $db->prepare("UPDATE player_orders SET order_status='expired', is_expired=1 WHERE user_id=? AND order_status='available' AND expires_at<NOW()");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

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
    $item = $db->query("SELECT item_id, base_sell_price FROM items WHERE item_type='produce' AND is_active=1 ORDER BY base_sell_price ASC, RAND() LIMIT 1")->fetch_assoc();
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

    $stmt = $db->prepare("INSERT INTO player_orders (user_id, order_code, customer_name, base_payment_coins, payment_coins, reputation_reward, recognition_reward, order_type, order_status, fulfillment_minutes, expires_at, cancel_reputation_penalty, late_fee_percent) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'available', ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 1, ?)");
    $stmt->bind_param('issiiisiii', $userId, $code, $customer, $basePayment, $payment, $reputationReward, $orderType, $fulfillmentMinutes, $boardMinutes, $lateFeePercent);
    $stmt->execute();

    $orderId = (int)$db->insert_id;
    $itemId = (int)$item['item_id'];
    $stmt = $db->prepare("INSERT INTO order_items (player_order_id, item_id, quantity_required) VALUES (?, ?, ?)");
    $stmt->bind_param('iii', $orderId, $itemId, $qty);
    $stmt->execute();
}


function getMarketStatus(mysqli $db, int $userId): array
{
    $clock = getGameClock($db, $userId);
    $absoluteDay = max(1, (int)($clock['absolute_day'] ?? 1));
    $hour = (int)($clock['hour'] ?? 0);
    $minute = (int)($clock['minute'] ?? 0);
    $secondsIntoDay = ($hour * 3600) + ($minute * 60);
    $dayLength = max(60, (int)($clock['day_length_seconds'] ?? 720));
    $weekDay = (($absoluteDay - 1) % 7) + 1; // 6 and 7 are the weekend market days.
    $openHour = 7;
    $closeHour = 18;
    $isWeekend = $weekDay >= 6;
    $isOpenHours = $hour >= $openHour && $hour < $closeHour;
    $isOpen = $isWeekend && $isOpenHours;

    $openOffset = (int)round(($openHour / 24) * $dayLength);
    $closeOffset = (int)round(($closeHour / 24) * $dayLength);
    $currentOffset = (int)round((($hour * 60 + $minute) / 1440) * $dayLength);

    if ($isOpen) {
        $secondsRemaining = max(0, $closeOffset - $currentOffset);
        $label = 'Market closes';
    } else {
        $daysUntilMarket = 0;
        if ($weekDay <= 5) $daysUntilMarket = 6 - $weekDay;
        elseif ($weekDay === 6 && $hour >= $closeHour) $daysUntilMarket = 1;
        elseif ($weekDay === 7 && $hour >= $closeHour) $daysUntilMarket = 6;
        elseif ($weekDay === 7) $daysUntilMarket = 0;

        $targetOffset = $openOffset;
        $secondsRemaining = ($daysUntilMarket * $dayLength) + $targetOffset - $currentOffset;
        if ($secondsRemaining < 0) $secondsRemaining += $dayLength;
        $label = 'Market opens';
    }

    return [
        'is_open' => $isOpen,
        'is_weekend' => $isWeekend,
        'weekday' => $weekDay,
        'open_hour' => $openHour,
        'close_hour' => $closeHour,
        'label' => $label,
        'seconds_remaining' => $secondsRemaining
    ];
}

function getShopSellLimits(mysqli $db, int $userId): array
{
    if (!tableExists($db, 'shop_buy_limits')) return [];
    $clock = getGameClock($db, $userId);
    $shopDay = (int)($clock['absolute_day'] ?? 1);
    $stmt = $db->prepare("\n        SELECT i.item_id, i.code, i.name, i.icon, i.base_sell_price, sbl.daily_limit,\n               COALESCE(sold.quantity_sold, 0) AS quantity_sold,\n               GREATEST(0, sbl.daily_limit - COALESCE(sold.quantity_sold, 0)) AS remaining_quantity\n        FROM shop_buy_limits sbl\n        JOIN items i ON i.item_id = sbl.item_id\n        LEFT JOIN player_shop_sales sold\n          ON sold.user_id = ? AND sold.item_id = i.item_id AND sold.shop_day = ?\n        WHERE sbl.is_active = 1 AND i.is_active = 1\n        ORDER BY i.base_sell_price ASC, i.name ASC\n    ");
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
    $progress = getPlayerProgress($db, $userId);
    $unlocks = [];
    $stmt = $db->prepare("SELECT unlock_key FROM player_unlocks WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $unlocks[$row['unlock_key']] = true;

    $defs = [
        ['key'=>'garden','name'=>'Garden','icon'=>'assets/map/garden.png','unlock'=>'location_garden','hint'=>'Your growing field.'],
        ['key'=>'shop','name'=>'General Store','icon'=>'assets/map/store.png','unlock'=>'location_shop','hint'=>'Basic seeds, tools, and a weekly special.'],
        ['key'=>'orders','name'=>'Orders Board','icon'=>'assets/map/orders.png','unlock'=>'orders_board','hint'=>'Requests from townsfolk.'],
        ['key'=>'shed','name'=>'Workroom / Shed','icon'=>'🛖','unlock'=>'location_shed','hint'=>'Processing and equipment live here.'],
        ['key'=>'market','name'=>'Farmer\'s Market','icon'=>'🎪','unlock'=>'location_market','hint'=>'Unlocks at 10 reputation after the shopkeeper invite.'],
        ['key'=>'caravan','name'=>'Caravan Camp','icon'=>'assets/map/caravan_empty.png','unlock'=>'location_caravan','hint'=>'Unlocked by relic-driven visitor events.'],
        ['key'=>'bone_brine','name'=>'Bone & Brine','icon'=>'☠️','unlock'=>'location_bone_brine','hint'=>'Permanent oddities stall after their relic event.'],
        ['key'=>'helpers','name'=>'Forest Folk','icon'=>'assets/map/fairy_folk.png','unlock'=>'helpers_unlocked','hint'=>'Summoned helpers using bells and equipped amulets.'],
    ];

    $marketStatus = getMarketStatus($db, $userId);

    foreach ($defs as &$def) {
        $def['unlocked'] = !empty($unlocks[$def['unlock']]);
        $def['disabled'] = false;
        if ($def['key'] === 'market') {
            $def['is_open'] = (bool)$marketStatus['is_open'];
            $def['market_status'] = $marketStatus;
            if ($def['unlocked'] && !$marketStatus['is_open']) {
                $def['disabled'] = true;
                $def['hint'] = 'The Fae Market is not open right now.';
            } elseif ($def['unlocked']) {
                $def['hint'] = 'The Fae Market is open. They buy any crop, with no daily limit.';
            } elseif ($progress['reputation'] >= 10) {
                // Reputation creates a shop event marker; accepting that event grants location_market.
                $def['can_unlock'] = false;
                $def['hint'] = 'The shopkeeper may have something to say at the General Store.';
            }
        }
    }
    unset($def);
    return $defs;
}

function maybeGrantMarketInvite(mysqli $db, int $userId): void
{
    // v0.4.16d: reputation no longer silently unlocks the Farmer's Market.
    // It now creates a location-driven event marker handled by getLocationEvents().
}

function getLocationEvents(mysqli $db, int $userId): array
{
    $events = [];
    $progress = getPlayerProgress($db, $userId);

    if ($progress['reputation'] >= 10
        && !hasUnlock($db, $userId, 'location_market')
        && eventHasFirstStep($db, 'market_shopkeeper_invite')) {
        $events[] = [
            'event_key' => 'market_shopkeeper_invite',
            'location_key' => 'shop',
            'title' => 'Fae Market Invitation',
            'tooltip' => 'The shopkeepers have something to tell you.',
            'icon' => systemIconByCode($db, 'quest_available', '!')
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

function getActiveRelicPickup(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare("SELECT * FROM player_relics WHERE user_id = ? AND relic_key = 'first_field_relic' AND collected_at IS NULL ORDER BY relic_id DESC LIMIT 1");
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
