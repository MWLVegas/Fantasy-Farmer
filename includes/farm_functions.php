<?php

function getGameConfig(mysqli $db): array
{
    $config = $db->query("SELECT * FROM game_config WHERE config_id = 1 LIMIT 1")->fetch_assoc();
    if (!$config) {
        throw new RuntimeException('Missing game config.');
    }
    return $config;
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
    $day = (int) floor($dayFloat) + 1;
    $dayProgress = $dayFloat - floor($dayFloat);
    $mins = (int) floor($dayProgress * 1440);

    return [
        'day_length_seconds' => $dayLength,
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
    $stmt = $db->prepare("INSERT IGNORE INTO player_state (user_id, last_pouch_at, next_shop_refresh_at) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 60 MINUTE))");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    ensureStartingGarden($db, $userId);
    ensureStartingTools($db, $userId);
    ensureStartingInventory($db, $userId);
}

function ensureStartingInventory(mysqli $db, int $userId): void
{
    $stmt = $db->prepare("
        SELECT inventory_id
        FROM inventory inv
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
        INSERT INTO inventory (user_id, item_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $stmt->bind_param('iii', $userId, $itemId, $quantity);
    $stmt->execute();
}

function removeInventory(mysqli $db, int $userId, int $itemId, int $quantity): bool
{
    $stmt = $db->prepare("SELECT quantity FROM inventory WHERE user_id = ? AND item_id = ? LIMIT 1");
    $stmt->bind_param('ii', $userId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || (int) $row['quantity'] < $quantity) {
        return false;
    }

    $stmt = $db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE user_id = ? AND item_id = ?");
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
        FROM inventory inv
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


function ensureActiveOrder(mysqli $db, int $userId): void
{
    // v0.3.9: clamp existing active orders from earlier buggy builds to 30–60 minutes.
    $stmt = $db->prepare("
        UPDATE player_orders
        SET expires_at = DATE_ADD(NOW(), INTERVAL FLOOR(30 + RAND() * 31) MINUTE)
        WHERE user_id = ?
          AND is_fulfilled = 0
          AND is_expired = 0
          AND expires_at > DATE_ADD(NOW(), INTERVAL 60 MINUTE)
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $stmt = $db->prepare("UPDATE player_orders SET is_expired=1, next_available_at=DATE_ADD(NOW(), INTERVAL FLOOR(1+RAND()*4) MINUTE) WHERE user_id=? AND is_fulfilled=0 AND is_expired=0 AND expires_at<NOW()");
    $stmt->bind_param('i', $userId); $stmt->execute();

    // Clamp any old/dev overlong active orders down to 30–60 minutes.
    $stmt = $db->prepare("UPDATE player_orders SET expires_at = DATE_ADD(NOW(), INTERVAL FLOOR(30+RAND()*31) MINUTE) WHERE user_id=? AND is_fulfilled=0 AND is_expired=0 AND expires_at > DATE_ADD(NOW(), INTERVAL 60 MINUTE)");
    $stmt->bind_param('i', $userId); $stmt->execute();

    $stmt = $db->prepare("SELECT player_order_id FROM player_orders WHERE user_id=? AND is_fulfilled=0 AND is_expired=0 LIMIT 1");
    $stmt->bind_param('i', $userId); $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) return;

    $stmt = $db->prepare("SELECT next_available_at FROM player_orders WHERE user_id=? ORDER BY player_order_id DESC LIMIT 1");
    $stmt->bind_param('i', $userId); $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    if ($last && $last['next_available_at'] && strtotime($last['next_available_at']) > time()) return;

    $item = $db->query("SELECT item_id, base_sell_price FROM items WHERE item_type='produce' AND is_active=1 ORDER BY base_sell_price ASC, RAND() LIMIT 1")->fetch_assoc();
    if (!$item) return;

    $qty = random_int(2, 6);
    $payment = max(8, ((int)$item['base_sell_price'] * $qty) + random_int(4, 12));
    $expiresMinutes = random_int(30, 60);
    $code = randomOrderCode();
    $customer = randomOrderCustomerName($db);

    $stmt = $db->prepare("INSERT INTO player_orders (user_id, order_code, customer_name, payment_coins, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))");
    $stmt->bind_param('issii', $userId, $code, $customer, $payment, $expiresMinutes); $stmt->execute();
    $orderId = (int)$db->insert_id; $itemId = (int)$item['item_id'];
    $stmt = $db->prepare("INSERT INTO order_items (player_order_id, item_id, quantity_required) VALUES (?, ?, ?)");
    $stmt->bind_param('iii', $orderId, $itemId, $qty); $stmt->execute();
}

function getShopRefresh(mysqli $db, int $userId): array
{
    $config = getGameConfig($db);
    $minutes = max(5, (int)($config['shop_refresh_minutes'] ?? 60));
    $stmt = $db->prepare("SELECT next_shop_refresh_at FROM player_state WHERE user_id=? LIMIT 1");
    $stmt->bind_param('i', $userId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $next = $row['next_shop_refresh_at'] ?? null;
    if (!$next || strtotime($next) <= time()) {
        $stmt = $db->prepare("UPDATE player_state SET next_shop_refresh_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE user_id=?");
        $stmt->bind_param('ii', $minutes, $userId); $stmt->execute();
        $next = date('Y-m-d H:i:s', time() + $minutes * 60);
    }
    return [
        'next_refresh_at' => $next,
        'seconds_remaining' => max(0, strtotime($next) - time()),
        'refresh_minutes' => $minutes
    ];
}
