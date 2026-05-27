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
    return [
        'background_image' => $config['map_background_image'] ?? '',
        'button_positions_json' => $config['map_button_positions_json'] ?? ''
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
        $delay = randomMinuteRange($config, 'order_refill_min_minutes', 'order_refill_max_minutes', 2, 5);
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
        $delay = randomMinuteRange($config, 'order_refill_min_minutes', 'order_refill_max_minutes', 2, 5);
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
        ['key'=>'garden','name'=>'Garden','icon'=>'🌱','unlock'=>'location_garden','hint'=>'Your growing field.'],
        ['key'=>'shop','name'=>'General Store','icon'=>'🏪','unlock'=>'location_shop','hint'=>'Basic seeds, tools, and a weekly special.'],
        ['key'=>'orders','name'=>'Orders Board','icon'=>'📜','unlock'=>'orders_board','hint'=>'Requests from townsfolk.'],
        ['key'=>'shed','name'=>'Workroom / Shed','icon'=>'🛖','unlock'=>'location_shed','hint'=>'Processing and equipment live here.'],
        ['key'=>'market','name'=>'Farmer\'s Market','icon'=>'🎪','unlock'=>'location_market','hint'=>'Unlocks at 10 reputation after the shopkeeper invite.'],
        ['key'=>'caravan','name'=>'Caravan Camp','icon'=>'🔮','unlock'=>'location_caravan','hint'=>'Unlocked by relic-driven visitor events.'],
        ['key'=>'bone_brine','name'=>'Bone & Brine','icon'=>'☠️','unlock'=>'location_bone_brine','hint'=>'Permanent oddities stall after their relic event.'],
        ['key'=>'helpers','name'=>'Forest Folk','icon'=>'🧚','unlock'=>'helpers_unlocked','hint'=>'Summoned helpers using bells and equipped amulets.'],
    ];

    foreach ($defs as &$def) {
        $def['unlocked'] = !empty($unlocks[$def['unlock']]);
        if ($def['key'] === 'market' && $progress['reputation'] >= 10) {
            $def['can_unlock'] = true;
            $def['hint'] = 'The shopkeeper is ready to invite you to the weekend market.';
        }
    }
    return $defs;
}

function maybeGrantMarketInvite(mysqli $db, int $userId): void
{
    $progress = getPlayerProgress($db, $userId);
    if ($progress['reputation'] >= 10 && !hasUnlock($db, $userId, 'location_market')) {
        grantUnlock($db, $userId, 'location_market', 'reputation_10');
    }
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
            $stmt = $db->prepare("INSERT INTO player_helpers (user_id, helper_type_id, helper_name, equipped_helper_equipment_id, active_task) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iisis', $userId, $helperTypeId, $name, $equipmentId, $task);
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
