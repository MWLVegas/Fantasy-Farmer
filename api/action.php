<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = requireLogin();
ensurePlayerDefaults($db, $userId);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';
$trustedGardenActions = ['till', 'water', 'plant', 'harvest', 'dig'];
$isTrustedGardenAction = !empty($input['trusted_client']) && in_array($action, $trustedGardenActions, true);

if (!$isTrustedGardenAction) {
    processGrowth($db, $userId);
    processHelperAutomation($db, $userId);
}

function getPlayerTool(mysqli $db, int $userId, string $toolType): array
{
    $stmt = $db->prepare("
        SELECT t.*
        FROM player_tools pt
        JOIN tools t ON t.tool_id = pt.tool_id
        WHERE pt.user_id = ? AND t.tool_type = ?
        ORDER BY t.level DESC
        LIMIT 1
    ");
    $stmt->bind_param('is', $userId, $toolType);
    $stmt->execute();

    $tool = $stmt->get_result()->fetch_assoc();

    if (!$tool) {
        throw new RuntimeException('Missing tool.');
    }

    return $tool;
}

function toolMessage(array $tool, string $fallback): string
{
    return $tool['action_message'] ?: $fallback;
}

function shedTablesReady(mysqli $db): bool
{
    $res = $db->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_shed_objects'");
    return $res && (int)($res->fetch_assoc()['c'] ?? 0) > 0;
}

function ensureMachineShedObject(mysqli $db, int $userId, int $playerMachineId, array $machine): void
{
    if (!shedTablesReady($db)) return;

    $machineType = (string)($machine['machine_type'] ?? 'machine');
    $placeableKey = $machineType === 'preserve' ? 'preserve_bin' : $machineType;

    $stmt = $db->prepare("SELECT placeable_id FROM placeable_defs WHERE placeable_key = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('s', $placeableKey);
    $stmt->execute();
    $def = $stmt->get_result()->fetch_assoc();
    if (!$def) return;

    $placeableId = (int)$def['placeable_id'];
    $stmt = $db->prepare("SELECT shed_object_id FROM player_shed_objects WHERE user_id = ? AND player_machine_id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('ii', $userId, $playerMachineId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) return;

    $stmt = $db->prepare("INSERT INTO player_shed_objects (user_id, placeable_id, object_category, player_machine_id, zone_key, grid_x, grid_y, rotation, z_index, is_active) VALUES (?, ?, 'machine', ?, 'floor', 1, 1, 0, 10, 1)");
    $stmt->bind_param('iii', $userId, $placeableId, $playerMachineId);
    $stmt->execute();
}

function shedPlacementOverlap(mysqli $db, int $userId, int $objectId, string $zoneKey, int $gridX, int $gridY, int $gridW, int $gridH): bool
{
    $stmt = $db->prepare("\n        SELECT pso.shed_object_id, pso.grid_x, pso.grid_y, pd.grid_w, pd.grid_h, pso.rotation\n        FROM player_shed_objects pso\n        JOIN placeable_defs pd ON pd.placeable_id = pso.placeable_id\n        WHERE pso.user_id = ?\n          AND pso.zone_key = ?\n          AND pso.is_active = 1\n          AND pso.shed_object_id <> ?\n    ");
    $stmt->bind_param('isi', $userId, $zoneKey, $objectId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ow = (int)$row['grid_w'];
        $oh = (int)$row['grid_h'];
        $rot = (int)$row['rotation'];
        if ($rot === 90 || $rot === 270) { $tmp = $ow; $ow = $oh; $oh = $tmp; }
        $ox = (int)$row['grid_x'];
        $oy = (int)$row['grid_y'];
        if ($gridX < $ox + $ow && $gridX + $gridW > $ox && $gridY < $oy + $oh && $gridY + $gridH > $oy) {
            return true;
        }
    }
    return false;
}

try {
    $db->begin_transaction();

    if ($action === 'till') {
        $plotId = (int) ($input['plot_id'] ?? 0);

        if ($isTrustedGardenAction) {
            $progress = max(0, min(100, (int)($input['till_progress'] ?? 0)));
            $isTilled = !empty($input['is_tilled']) || $progress >= 100 ? 1 : 0;
            $stmt = $db->prepare("
                UPDATE garden_plots gp
                JOIN gardens g ON g.garden_id = gp.garden_id
                SET gp.till_progress = ?, gp.is_tilled = ?
                WHERE gp.plot_id = ? AND g.user_id = ? AND gp.is_unlocked = 1
            ");
            $stmt->bind_param('iiii', $progress, $isTilled, $plotId, $userId);
            $stmt->execute();
            $db->commit();
            jsonResponse(['ok' => true, 'message' => 'Tilled.']);
        }

        $tool = getPlayerTool($db, $userId, 'hoe');
        $strength = (int) $tool['strength'];

        $stmt = $db->prepare("
            UPDATE garden_plots gp
            JOIN gardens g ON g.garden_id = gp.garden_id
            SET gp.till_progress = LEAST(100, gp.till_progress + ?),
                gp.is_tilled = CASE WHEN LEAST(100, gp.till_progress + ?) >= 100 THEN 1 ELSE gp.is_tilled END
            WHERE gp.plot_id = ? AND g.user_id = ? AND gp.is_unlocked = 1
        ");
        $stmt->bind_param('iiii', $strength, $strength, $plotId, $userId);
        $stmt->execute();

        $relicName = maybeFindFirstRelicFromTilling($db, $userId, $plotId, $tool);
        $message = toolMessage($tool, 'Tilled.');
        if ($relicName) {
            $message = 'Something strange surfaced in the soil.';
        }

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $message, 'relic_spawned' => (bool)$relicName, 'relic_found' => $relicName]);
    }

    if ($action === 'water') {
        $cropId = (int) ($input['planted_crop_id'] ?? 0);

        if ($isTrustedGardenAction) {
            $water = max(0, (int)($input['water_current'] ?? 0));
            if ($cropId > 0) {
                $stmt = $db->prepare("
                    UPDATE planted_crops pc
                    JOIN plants p ON p.plant_id = pc.plant_id
                    SET pc.water_current = LEAST(p.water_max, ?)
                    WHERE pc.planted_crop_id = ? AND pc.user_id = ? AND pc.is_harvested = 0
                ");
                $stmt->bind_param('iii', $water, $cropId, $userId);
                $stmt->execute();
            } else {
                $gardenId = (int)($input['garden_id'] ?? 0);
                $originX = (int)($input['origin_x'] ?? 0);
                $originY = (int)($input['origin_y'] ?? 0);
                $stmt = $db->prepare("
                    UPDATE planted_crops pc
                    JOIN plants p ON p.plant_id = pc.plant_id
                    SET pc.water_current = LEAST(p.water_max, ?)
                    WHERE pc.user_id = ? AND pc.garden_id = ?
                      AND pc.origin_x = ? AND pc.origin_y = ?
                      AND pc.is_harvested = 0
                ");
                $stmt->bind_param('iiiii', $water, $userId, $gardenId, $originX, $originY);
                $stmt->execute();
            }
            $db->commit();
            jsonResponse(['ok' => true, 'message' => 'Watered.']);
        }

        $tool = getPlayerTool($db, $userId, 'watering_can');
        $strength = (int) $tool['strength'];

        $stmt = $db->prepare("
            UPDATE planted_crops pc
            JOIN plants p ON p.plant_id = pc.plant_id
            SET pc.water_current = LEAST(p.water_max, pc.water_current + ?)
            WHERE pc.planted_crop_id = ? AND pc.user_id = ? AND pc.is_harvested = 0
        ");
        $stmt->bind_param('iii', $strength, $cropId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => toolMessage($tool, 'Watered.')]);
    }

    if ($action === 'dig') {
        $cropId = (int) ($input['planted_crop_id'] ?? 0);
        $tool = getPlayerTool($db, $userId, 'shovel');
        $strength = (int) $tool['strength'];

        $stmt = $db->prepare("
            SELECT growth_step_current
            FROM planted_crops
            WHERE planted_crop_id = ? AND user_id = ? AND is_harvested = 0
            LIMIT 1
        ");
        $stmt->bind_param('ii', $cropId, $userId);
        $stmt->execute();
        $crop = $stmt->get_result()->fetch_assoc();

        if (!$crop) {
            throw new RuntimeException('Crop not found.');
        }

        $newStage = max(0, (int) $crop['growth_step_current'] - $strength);

        if ($newStage <= 0) {
            $stmt = $db->prepare("UPDATE planted_crops SET is_harvested = 1 WHERE planted_crop_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $cropId, $userId);
            $stmt->execute();
            $message = $tool['complete_message'] ?: toolMessage($tool, 'Dug up.');
        } else {
            $stmt = $db->prepare("
                UPDATE planted_crops
                SET growth_step_current = ?, growth_progress_seconds = 0
                WHERE planted_crop_id = ? AND user_id = ?
            ");
            $stmt->bind_param('iii', $newStage, $cropId, $userId);
            $stmt->execute();
            $message = toolMessage($tool, 'Dug.');
        }

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $message]);
    }

    if ($action === 'plant') {
        $gardenId = (int) ($input['garden_id'] ?? 0);
        $plantId = (int) ($input['plant_id'] ?? 0);
        $x = (int) ($input['x'] ?? 0);
        $y = (int) ($input['y'] ?? 0);

        $stmt = $db->prepare("SELECT garden_id FROM gardens WHERE garden_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $gardenId, $userId);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            throw new RuntimeException('Garden not found.');
        }

        $place = canPlacePlant($db, $gardenId, $plantId, $x, $y);

        if (!$place['ok']) {
            throw new RuntimeException($place['error']);
        }

        $plant = $place['plant'];
        $seedItemId = (int) $plant['seed_item_id'];

        if (!removeInventory($db, $userId, $seedItemId, 1)) {
            throw new RuntimeException('You need seeds for that crop.');
        }

        $clock = getGameClock($db, $userId);
        $plantCycleIndex = cycleIndexForElapsedHours((float)$clock['total_game_hours_elapsed'], (int)$plant['cycle_hour']);

        $stmt = $db->prepare("
            INSERT INTO planted_crops (user_id, garden_id, plant_id, origin_x, origin_y, water_current, last_cycle_index)
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->bind_param('iiiiii', $userId, $gardenId, $plantId, $x, $y, $plantCycleIndex);
        $stmt->execute();
        $plantedCropId = (int)$db->insert_id;

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $plant['name'] . ' planted.', 'planted_crop_id' => $plantedCropId]);
    }

    if ($action === 'harvest') {
        $cropId = (int) ($input['planted_crop_id'] ?? 0);

        $stmt = $db->prepare("
            SELECT pc.*, p.growth_steps, p.harvest_item_id, p.harvest_min, p.harvest_max, p.name
            FROM planted_crops pc
            JOIN plants p ON p.plant_id = pc.plant_id
            WHERE pc.planted_crop_id = ? AND pc.user_id = ? AND pc.is_harvested = 0
            LIMIT 1
        ");
        $stmt->bind_param('ii', $cropId, $userId);
        $stmt->execute();
        $crop = $stmt->get_result()->fetch_assoc();

        if (!$crop) {
            throw new RuntimeException('Crop not found.');
        }

        if ((int) $crop['growth_step_current'] < (int) $crop['growth_steps']) {
            throw new RuntimeException('Not ready yet.');
        }

        $qty = random_int((int) $crop['harvest_min'], (int) $crop['harvest_max']);
        addInventory($db, $userId, (int) $crop['harvest_item_id'], $qty);

        $stmt = $db->prepare("UPDATE planted_crops SET is_harvested = 1 WHERE planted_crop_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $cropId, $userId);
        $stmt->execute();

        $stmt = $db->prepare("
            SELECT p.width, p.height
            FROM planted_crops pc
            JOIN plants p ON p.plant_id = pc.plant_id
            WHERE pc.planted_crop_id = ? AND pc.user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $cropId, $userId);
        $stmt->execute();
        $size = $stmt->get_result()->fetch_assoc();

        if ($size) {
            $gardenId = (int) $crop['garden_id'];
            $originX = (int) $crop['origin_x'];
            $originY = (int) $crop['origin_y'];
            $x2 = $originX + (int) $size['width'];
            $y2 = $originY + (int) $size['height'];

            $stmt = $db->prepare("
                UPDATE garden_plots
                SET is_tilled = 0, till_progress = 0
                WHERE garden_id = ?
                  AND x_pos >= ? AND x_pos < ?
                  AND y_pos >= ? AND y_pos < ?
            ");
            $stmt->bind_param('iiiii', $gardenId, $originX, $x2, $originY, $y2);
            $stmt->execute();
        }

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Harvested ×' . $qty . '.']);
    }

    if ($action === 'buy_seed') {
        $itemId = (int) ($input['item_id'] ?? 0);
        $qty = max(1, (int) ($input['quantity'] ?? 1));

        $stmt = $db->prepare("SELECT * FROM items WHERE item_id = ? AND item_type = 'seed' LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();

        if (!$item) {
            throw new RuntimeException('Seed not found.');
        }

        $cost = (int) $item['base_buy_price'] * $qty;

        $stmt = $db->prepare("SELECT coins FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || (int) $user['coins'] < $cost) {
            throw new RuntimeException('Not enough coins.');
        }

        $stmt = $db->prepare("UPDATE users SET coins = coins - ? WHERE user_id = ?");
        $stmt->bind_param('ii', $cost, $userId);
        $stmt->execute();

        addInventory($db, $userId, $itemId, $qty);

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Seeds bought.']);
    }

    if ($action === 'sell_item') {
        $itemId = (int) ($input['item_id'] ?? 0);
        $qty = max(1, (int) ($input['quantity'] ?? 1));
        $saleContext = trim((string)($input['sale_context'] ?? 'shop'));
        if (!in_array($saleContext, ['shop', 'market'], true)) $saleContext = 'shop';

        $stmt = $db->prepare("SELECT item_id, code, name, item_type, base_sell_price FROM items WHERE item_id = ? LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();

        if (!$item || (int) $item['base_sell_price'] <= 0) {
            throw new RuntimeException('That cannot be sold.');
        }

        if ($saleContext === 'market') {
            $marketStatus = getMarketStatus($db, $userId);
            if (empty($marketStatus['is_open'])) throw new RuntimeException('The Fae Market is not open right now.');
            if (($item['item_type'] ?? '') !== 'produce') throw new RuntimeException('The market is only buying crops right now.');
        } else {
            if (!tableExists($db, 'shop_buy_limits')) throw new RuntimeException('The shop is not buying produce right now.');
            $clock = getGameClock($db, $userId);
            $shopDay = (int)($clock['absolute_day'] ?? 1);
            $stmt = $db->prepare("
                SELECT sbl.daily_limit, COALESCE(sold.quantity_sold, 0) AS quantity_sold
                FROM shop_buy_limits sbl
                LEFT JOIN player_shop_sales sold
                  ON sold.user_id = ? AND sold.item_id = sbl.item_id AND sold.shop_day = ?
                WHERE sbl.item_id = ? AND sbl.is_active = 1
                LIMIT 1
            ");
            $stmt->bind_param('iii', $userId, $shopDay, $itemId);
            $stmt->execute();
            $limit = $stmt->get_result()->fetch_assoc();
            if (!$limit) throw new RuntimeException('The shop is not buying that item today.');
            $remaining = max(0, (int)$limit['daily_limit'] - (int)$limit['quantity_sold']);
            if ($qty > $remaining) throw new RuntimeException('The shop has already bought enough of that item today.');
        }

        if (!removeInventory($db, $userId, $itemId, $qty)) {
            throw new RuntimeException('Not enough items.');
        }

        $coins = (int) $item['base_sell_price'] * $qty;

        $stmt = $db->prepare("UPDATE users SET coins = coins + ? WHERE user_id = ?");
        $stmt->bind_param('ii', $coins, $userId);
        $stmt->execute();

        if ($saleContext === 'shop' && tableExists($db, 'player_shop_sales')) {
            $clock = getGameClock($db, $userId);
            $shopDay = (int)($clock['absolute_day'] ?? 1);
            $stmt = $db->prepare("
                INSERT INTO player_shop_sales (user_id, item_id, shop_day, quantity_sold)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity_sold = quantity_sold + VALUES(quantity_sold)
            ");
            $stmt->bind_param('iiii', $userId, $itemId, $shopDay, $qty);
            $stmt->execute();
        }

        $db->commit();
        jsonResponse(['ok' => true, 'message' => '+' . $coins . ' coins.']);
    }

    if ($action === 'buy_tool') {
        $toolId = (int) ($input['tool_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM tools WHERE tool_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $toolId);
        $stmt->execute();
        $tool = $stmt->get_result()->fetch_assoc();
        if (!$tool) throw new RuntimeException('Tool not found.');

        $stmt = $db->prepare("
            SELECT pt.player_tool_id, t.level
            FROM player_tools pt
            JOIN tools t ON t.tool_id = pt.tool_id
            WHERE pt.user_id = ? AND t.tool_type = ? AND t.level >= ?
            LIMIT 1
        ");
        $toolType = $tool['tool_type'];
        $toolLevel = (int)$tool['level'];
        $stmt->bind_param('isi', $userId, $toolType, $toolLevel);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) throw new RuntimeException('You already own that tool or better.');

        $cost = (int)$tool['upgrade_cost'];
        $stmt = $db->prepare("SELECT coins FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user || (int)$user['coins'] < $cost) throw new RuntimeException('Not enough coins.');

        $stmt = $db->prepare("UPDATE users SET coins = coins - ? WHERE user_id = ?");
        $stmt->bind_param('ii', $cost, $userId);
        $stmt->execute();
        $stmt = $db->prepare("
            DELETE pt
            FROM player_tools pt
            JOIN tools old_tool ON old_tool.tool_id = pt.tool_id
            WHERE pt.user_id = ?
              AND old_tool.tool_type = ?
              AND old_tool.level < ?
        ");
        $stmt->bind_param('isi', $userId, $toolType, $toolLevel);
        $stmt->execute();

        $stmt = $db->prepare("INSERT INTO player_tools (user_id, tool_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $userId, $toolId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $tool['name'] . ' bought.']);
    }

    if ($action === 'collect_relic') {
        $relicId = (int)($input['relic_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM player_relics WHERE relic_id = ? AND user_id = ? AND collected_at IS NULL LIMIT 1");
        $stmt->bind_param('ii', $relicId, $userId);
        $stmt->execute();
        $relic = $stmt->get_result()->fetch_assoc();
        if (!$relic) throw new RuntimeException('Relic not found.');

        $item = $db->query("SELECT item_id, code, name, icon FROM items WHERE code = 'relic_first_oddity' LIMIT 1")->fetch_assoc();
        if (!$item) throw new RuntimeException('Missing relic item.');

        addInventory($db, $userId, (int)$item['item_id'], 1);

        $stmt = $db->prepare("UPDATE player_relics SET collected_at = NOW(), visual_state = 'collected' WHERE relic_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $relicId, $userId);
        $stmt->execute();

        grantUnlock($db, $userId, 'first_relic_collected', 'first_relic_pickup');
        scheduleMadamRuneVisit($db, $userId);

        $db->commit();
        jsonResponse([
            'ok' => true,
            'message' => 'Relic taken.',
            'rewards' => [[
                'code' => $item['code'],
                'name' => $item['name'],
                'icon' => $item['icon'],
                'quantity' => 1
            ]]
        ]);
    }

    if ($action === 'advance_story_event') {
        $eventKey = trim((string)($input['event_key'] ?? ''));
        if ($eventKey === '') {
            throw new RuntimeException('Missing event key.');
        }
        $result = advancePlayerEvent($db, $userId, $eventKey);
        $db->commit();
        jsonResponse($result);
    }


    if ($action === 'start_location_event') {
        $eventKey = trim((string)($input['event_key'] ?? ''));
        $locationKey = trim((string)($input['location_key'] ?? ''));
        if ($eventKey === '' || $locationKey === '') {
            throw new RuntimeException('Missing location event.');
        }
        if (!canStartLocationEvent($db, $userId, $eventKey, $locationKey)) {
            throw new RuntimeException('That event is not available right now.');
        }
        $storyEvent = startPlayerEvent($db, $userId, $eventKey);
        if (!$storyEvent) {
            throw new RuntimeException('Could not start that event.');
        }
        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Event started.', 'story_event' => $storyEvent]);
    }

    if ($action === 'start_fairy_bell_event') {
        if (!hasUnlock($db, $userId, 'madam_rune_intro_seen')) {
            throw new RuntimeException('The bell has not found its way to you yet.');
        }
        if (hasUnlock($db, $userId, 'helpers_unlocked')) {
            throw new RuntimeException('A helper has already answered the first bell.');
        }
        $bellItem = $db->query("SELECT i.item_id, COALESCE(inv.quantity,0) AS qty FROM items i LEFT JOIN player_inventory inv ON inv.item_id = i.item_id AND inv.user_id = " . (int)$userId . " WHERE i.code = 'fairy_bell' LIMIT 1")->fetch_assoc();
        if (!$bellItem || (int)$bellItem['qty'] < 1) {
            throw new RuntimeException('You need the Fairy Bell to summon a helper.');
        }
        $event = startPlayerEvent($db, $userId, 'fairy_bell_summon');
        $db->commit();
        jsonResponse(['ok' => true, 'story_event' => $event]);
    }

    // Legacy compatibility routes now run through the database-backed event system.
    if ($action === 'complete_madam_rune_intro') {
        $event = startPlayerEvent($db, $userId, 'madam_rune_intro');
        while ($event) {
            $advanced = advancePlayerEvent($db, $userId, 'madam_rune_intro');
            if (!empty($advanced['complete'])) {
                $db->commit();
                jsonResponse(['ok' => true, 'message' => 'Madam Rune trades you the Fairy Bell and Aqua Amulet.', 'rewards' => $advanced['effects']['rewards'] ?? []]);
            }
            $event = $advanced['story_event'] ?? null;
        }
        throw new RuntimeException('Madam Rune event could not complete.');
    }

    if ($action === 'summon_first_fairy') {
        startPlayerEvent($db, $userId, 'fairy_bell_summon');
        while (true) {
            $advanced = advancePlayerEvent($db, $userId, 'fairy_bell_summon');
            if (!empty($advanced['complete'])) {
                $db->commit();
                jsonResponse(['ok' => true, 'message' => 'The bell rings once, vanishes, and a very excited water fairy arrives.']);
            }
        }
    }


    if ($action === 'unlock_plot') {
        $plotId = (int)($input['plot_id'] ?? 0);
        $item = $db->query("SELECT item_id, name FROM items WHERE code = 'land_claim_note' LIMIT 1")->fetch_assoc();
        if (!$item) throw new RuntimeException('Missing Land Claim Note item.');
        $itemId = (int)$item['item_id'];

        $stmt = $db->prepare("
            SELECT gp.*
            FROM garden_plots gp
            JOIN gardens g ON g.garden_id = gp.garden_id
            WHERE gp.plot_id = ? AND g.user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $plotId, $userId);
        $stmt->execute();
        $plot = $stmt->get_result()->fetch_assoc();
        if (!$plot) throw new RuntimeException('Plot not found.');
        if ((int)$plot['is_unlocked']) throw new RuntimeException('That plot is already unlocked.');
        if (!removeInventory($db, $userId, $itemId, 1)) throw new RuntimeException('You need a Land Claim Note to unlock that plot.');

        $stmt = $db->prepare("UPDATE garden_plots SET is_unlocked = 1, unlocked_at = NOW() WHERE plot_id = ?");
        $stmt->bind_param('i', $plotId);
        $stmt->execute();
        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Plot unlocked.']);
    }

    if ($action === 'equip_helper_accessory') {
        $helperId = (int)($input['player_helper_id'] ?? 0);
        $equipmentId = isset($input['helper_equipment_id']) && $input['helper_equipment_id'] !== null ? (int)$input['helper_equipment_id'] : 0;
        $stmt = $db->prepare("SELECT player_helper_id FROM player_helpers WHERE player_helper_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $helperId, $userId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) throw new RuntimeException('Helper not found.');

        if ($equipmentId <= 0) {
            $stmt = $db->prepare("UPDATE player_helpers SET equipped_helper_equipment_id = NULL, active_task = 'idle' WHERE player_helper_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $helperId, $userId);
            $stmt->execute();
            $db->commit();
            jsonResponse(['ok' => true, 'message' => 'Accessory removed.']);
        }

        $stmt = $db->prepare("SELECT he.*, i.item_id, COALESCE(inv.quantity,0) AS owned_quantity FROM helper_equipment he LEFT JOIN items i ON i.code = he.code AND i.item_type = 'helper_equipment' LEFT JOIN player_inventory inv ON inv.item_id = i.item_id AND inv.user_id = ? WHERE he.helper_equipment_id = ? AND he.is_active = 1 LIMIT 1");
        $stmt->bind_param('ii', $userId, $equipmentId);
        $stmt->execute();
        $equip = $stmt->get_result()->fetch_assoc();
        if (!$equip) throw new RuntimeException('Accessory not found.');
        if ((int)($equip['owned_quantity'] ?? 0) < 1) throw new RuntimeException('You do not own that accessory.');

        $stmt = $db->prepare("UPDATE player_helpers SET equipped_helper_equipment_id = ?, active_task = ? WHERE player_helper_id = ? AND user_id = ?");
        $task = (string)$equip['task_type'];
        $stmt->bind_param('isii', $equipmentId, $task, $helperId, $userId);
        $stmt->execute();
        $db->commit();
        jsonResponse(['ok' => true, 'message' => $equip['name'] . ' equipped.']);
    }

    if ($action === 'buy_special_item') {
        $code = trim((string)($input['code'] ?? ''));
        if ($code !== 'land_claim_note') throw new RuntimeException('Special item not available.');
        $stmt = $db->prepare("SELECT * FROM items WHERE code = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        if (!$item) throw new RuntimeException('Special item not found.');

        $landItemId = (int)$item['item_id'];
        $stmt = $db->prepare("SELECT COUNT(*) AS purchase_count FROM player_unlocks WHERE user_id = ? AND unlock_key LIKE 'shop_land_claim_note_%'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $purchaseCount = (int)($stmt->get_result()->fetch_assoc()['purchase_count'] ?? 0);
        if ($purchaseCount >= 3) throw new RuntimeException('The shop is out of Land Claim Notes for you.');

        $cost = (int)$item['base_buy_price'] + ($purchaseCount * 75);
        $stmt = $db->prepare("SELECT coins FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user || (int)$user['coins'] < $cost) throw new RuntimeException('Not enough coins.');
        $stmt = $db->prepare("UPDATE users SET coins = coins - ? WHERE user_id = ?");
        $stmt->bind_param('ii', $cost, $userId);
        $stmt->execute();
        addInventory($db, $userId, $landItemId, 1);
        grantUnlock($db, $userId, 'shop_land_claim_note_' . ($purchaseCount + 1), 'shop_purchase');
        $db->commit();
        jsonResponse(['ok'=>true, 'message'=>$item['name'].' bought.']);
    }

    if ($action === 'buy_machine') {
        $machineId = (int) ($input['machine_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM machines WHERE machine_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $machineId);
        $stmt->execute();
        $machine = $stmt->get_result()->fetch_assoc();

        if (!$machine) {
            throw new RuntimeException('Machine not found.');
        }
        if (($machine['machine_type'] ?? '') !== 'preserve') {
            throw new RuntimeException('That machine is not sold in the General Store yet.');
        }

        $cost = (int) $machine['base_cost'];

        $stmt = $db->prepare("SELECT coins FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || (int) $user['coins'] < $cost) {
            throw new RuntimeException('Not enough coins.');
        }

        $stmt = $db->prepare("UPDATE users SET coins = coins - ? WHERE user_id = ?");
        $stmt->bind_param('ii', $cost, $userId);
        $stmt->execute();

        $stmt = $db->prepare("
            INSERT INTO player_machines (user_id, machine_id, quantity)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE quantity = quantity + 1
        ");
        $stmt->bind_param('ii', $userId, $machineId);
        $stmt->execute();

        $stmt = $db->prepare("SELECT player_machine_id FROM player_machines WHERE user_id = ? AND machine_id = ? LIMIT 1");
        $stmt->bind_param('ii', $userId, $machineId);
        $stmt->execute();
        $ownedMachine = $stmt->get_result()->fetch_assoc();
        if ($ownedMachine) {
            grantUnlock($db, $userId, 'location_shed', 'machine_purchase');
        }

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $machine['name'] . ' bought.']);
    }


    if ($action === 'move_shed_object') {
        if (!shedTablesReady($db)) {
            throw new RuntimeException('Shed placement tables are missing.');
        }

        $objectId = (int)($input['shed_object_id'] ?? 0);
        $gridX = max(0, (int)($input['grid_x'] ?? 0));
        $gridY = max(0, (int)($input['grid_y'] ?? 0));
        $rotation = (int)($input['rotation'] ?? 0);
        if (!in_array($rotation, [0, 90, 180, 270], true)) $rotation = 0;

        $stmt = $db->prepare("\n            SELECT pso.*, pd.grid_w, pd.grid_h\n            FROM player_shed_objects pso\n            JOIN placeable_defs pd ON pd.placeable_id = pso.placeable_id\n            WHERE pso.shed_object_id = ? AND pso.user_id = ? AND pso.is_active = 1\n            LIMIT 1\n        ");
        $stmt->bind_param('ii', $objectId, $userId);
        $stmt->execute();
        $object = $stmt->get_result()->fetch_assoc();
        if (!$object) {
            throw new RuntimeException('Shed object not found.');
        }

        $zoneKey = (string)$object['zone_key'];
        $gridW = (int)$object['grid_w'];
        $gridH = (int)$object['grid_h'];
        if ($rotation === 90 || $rotation === 270) { $tmp = $gridW; $gridW = $gridH; $gridH = $tmp; }

        $stmt = $db->prepare("SELECT * FROM shed_zones WHERE zone_key = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('s', $zoneKey);
        $stmt->execute();
        $zone = $stmt->get_result()->fetch_assoc();
        if (!$zone) {
            throw new RuntimeException('Shed zone not found.');
        }

        if ($gridX + $gridW > (int)$zone['grid_cols'] || $gridY + $gridH > (int)$zone['grid_rows']) {
            throw new RuntimeException('That will not fit there.');
        }

        if (shedPlacementOverlap($db, $userId, $objectId, $zoneKey, $gridX, $gridY, $gridW, $gridH)) {
            throw new RuntimeException('Something is already there.');
        }

        $stmt = $db->prepare("UPDATE player_shed_objects SET grid_x = ?, grid_y = ?, rotation = ? WHERE shed_object_id = ? AND user_id = ?");
        $stmt->bind_param('iiiii', $gridX, $gridY, $rotation, $objectId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Shed layout saved.']);
    }

    if ($action === 'start_processing') {
        $recipeId = (int) ($input['recipe_id'] ?? 0);
        $quantity = max(1, (int) ($input['quantity'] ?? 1));

        $stmt = $db->prepare("SELECT * FROM processing_recipes WHERE recipe_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $recipe = $stmt->get_result()->fetch_assoc();

        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        $stmt = $db->prepare("
            SELECT pm.player_machine_id, pm.quantity,
                   COUNT(j.job_id) AS active_jobs
            FROM player_machines pm
            JOIN machines m ON m.machine_id = pm.machine_id
            LEFT JOIN processing_jobs j ON j.player_machine_id = pm.player_machine_id AND j.is_collected = 0
            WHERE pm.user_id = ? AND m.machine_type = ?
            GROUP BY pm.player_machine_id, pm.quantity
            LIMIT 1
        ");
        $stmt->bind_param('is', $userId, $recipe['machine_type']);
        $stmt->execute();
        $machine = $stmt->get_result()->fetch_assoc();

        if (!$machine) {
            throw new RuntimeException('You need the right equipment.');
        }
        if ((int)($machine['active_jobs'] ?? 0) >= (int)($machine['quantity'] ?? 1)) {
            throw new RuntimeException('All of those machines are already busy.');
        }

        $needed = (int) $recipe['input_quantity'] * $quantity;

        if (!removeInventory($db, $userId, (int) $recipe['input_item_id'], $needed)) {
            throw new RuntimeException('Not enough ingredients.');
        }

        $seconds = (int) $recipe['processing_time_seconds'] * $quantity;
        $finishesAt = date('Y-m-d H:i:s', time() + $seconds);
        $playerMachineId = (int) $machine['player_machine_id'];

        $stmt = $db->prepare("
            INSERT INTO processing_jobs (user_id, player_machine_id, recipe_id, quantity, finishes_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiiis', $userId, $playerMachineId, $recipeId, $quantity, $finishesAt);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Processing started.']);
    }

    if ($action === 'collect_processing') {
        $jobId = (int) ($input['job_id'] ?? 0);

        $stmt = $db->prepare("
            SELECT j.*, r.output_item_id, r.output_quantity
            FROM processing_jobs j
            JOIN processing_recipes r ON r.recipe_id = j.recipe_id
            WHERE j.job_id = ? AND j.user_id = ? AND j.is_collected = 0
            LIMIT 1
        ");
        $stmt->bind_param('ii', $jobId, $userId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();

        if (!$job) {
            throw new RuntimeException('Job not found.');
        }

        if (strtotime($job['finishes_at']) > time()) {
            throw new RuntimeException('Not finished yet.');
        }

        $qty = (int) $job['output_quantity'] * (int) $job['quantity'];
        addInventory($db, $userId, (int) $job['output_item_id'], $qty);

        $stmt = $db->prepare("UPDATE processing_jobs SET is_collected = 1 WHERE job_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $jobId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Collected.']);
    }


    if ($action === 'collect_pouch') {
        $pouchId = (int) ($input['pouch_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM player_pouches WHERE pouch_id = ? AND user_id = ? AND is_claimed = 0 LIMIT 1");
        $stmt->bind_param('ii', $pouchId, $userId);
        $stmt->execute();
        $pouch = $stmt->get_result()->fetch_assoc();

        if (!$pouch) {
            throw new RuntimeException('Pouch not found.');
        }

        $count = max(1, (int) $pouch['seed_count']);
        $seeds = $db->query("SELECT item_id, icon, name FROM items WHERE item_type = 'seed' AND is_active = 1 ORDER BY RAND() LIMIT " . $count)->fetch_all(MYSQLI_ASSOC);

        $found = [];
        foreach ($seeds as $seed) {
            addInventory($db, $userId, (int) $seed['item_id'], 1);
            $key = $seed['icon'] ?: $seed['name'];
            if (!isset($found[$key])) $found[$key] = 0;
            $found[$key]++;
        }
        $parts = [];
        foreach ($found as $icon => $qty) $parts[] = $icon . ' ×' . $qty;

        $stmt = $db->prepare("UPDATE player_pouches SET is_claimed = 1, visual_state = 'leaving', claimed_at = NOW() WHERE pouch_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $pouchId, $userId);
        $stmt->execute();

        $stmt = $db->prepare("UPDATE player_state SET last_pouch_at = NOW() WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'You open the pouch and find ' . implode(', ', $parts) . '.']);
    }


    if ($action === 'hire_worker') {
        $workerTypeId = (int)($input['worker_type_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM worker_types WHERE worker_type_id=? AND is_active=1 LIMIT 1");
        $stmt->bind_param('i', $workerTypeId); $stmt->execute(); $type = $stmt->get_result()->fetch_assoc();
        if (!$type) throw new RuntimeException('Worker type not found.');
        $cost = (int)$type['hire_cost'];
        $stmt = $db->prepare("SELECT coins FROM users WHERE user_id=? LIMIT 1");
        $stmt->bind_param('i', $userId); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc();
        if (!$user || (int)$user['coins'] < $cost) throw new RuntimeException('Not enough coins.');
        $stmt = $db->prepare("UPDATE users SET coins=coins-? WHERE user_id=?");
        $stmt->bind_param('ii', $cost, $userId); $stmt->execute();
        $x=random_int(300,1200)/10000; $y=random_int(1200,8800)/10000;
        $stmt = $db->prepare("INSERT INTO player_workers (user_id, worker_type_id, x_ratio, y_ratio) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iidd', $userId, $workerTypeId, $x, $y); $stmt->execute();
        $db->commit(); jsonResponse(['ok'=>true,'message'=>$type['name'].' hired.']);
    }

    if ($action === 'toggle_worker') {
        $workerId = (int)($input['player_worker_id'] ?? 0);
        $stmt = $db->prepare("UPDATE player_workers SET is_enabled=CASE WHEN is_enabled=1 THEN 0 ELSE 1 END WHERE player_worker_id=? AND user_id=?");
        $stmt->bind_param('ii', $workerId, $userId); $stmt->execute();
        $db->commit(); jsonResponse(['ok'=>true,'message'=>'Worker toggled.']);
    }

    if ($action === 'accept_order') {
        $orderId = (int)($input['player_order_id'] ?? 0);
        $slotLimit = getOrderSlotLimit($db, $userId);
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM player_orders WHERE user_id=? AND order_status='accepted' AND is_fulfilled=0");
        $stmt->bind_param('i', $userId); $stmt->execute();
        $accepted = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        if ($accepted >= $slotLimit) throw new RuntimeException('Your confirmed order slots are full.');

        $stmt = $db->prepare("SELECT * FROM player_orders WHERE player_order_id=? AND user_id=? AND order_status='available' AND expires_at>NOW() LIMIT 1");
        $stmt->bind_param('ii', $orderId, $userId); $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) throw new RuntimeException('That order is no longer available.');

        $minutes = max(1, (int)($order['fulfillment_minutes'] ?? 60));
        $stmt = $db->prepare("UPDATE player_orders SET order_status='accepted', accepted_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE player_order_id=? AND user_id=?");
        $stmt->bind_param('iii', $minutes, $orderId, $userId);
        $stmt->execute();
        $db->commit();
        jsonResponse(['ok'=>true,'message'=>'Order confirmed.']);
    }

    if ($action === 'cancel_order') {
        $orderId = (int)($input['player_order_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM player_orders WHERE player_order_id=? AND user_id=? AND order_status='accepted' AND is_fulfilled=0 LIMIT 1");
        $stmt->bind_param('ii', $orderId, $userId); $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) throw new RuntimeException('Order is not active.');
        $penalty = max(0, (int)($order['cancel_reputation_penalty'] ?? 1));
        if ($penalty > 0) {
            $stmt = $db->prepare("UPDATE player_state SET reputation = GREATEST(0, reputation - ?) WHERE user_id=?");
            $stmt->bind_param('ii', $penalty, $userId); $stmt->execute();
        }
        $stmt = $db->prepare("UPDATE player_orders SET order_status='cancelled', is_expired=1 WHERE player_order_id=? AND user_id=?");
        $stmt->bind_param('ii', $orderId, $userId); $stmt->execute();
        $db->commit();
        jsonResponse(['ok'=>true,'message'=>'Order cancelled. -'.$penalty.' reputation.', 'reputation_delta'=>-$penalty]);
    }

    if ($action === 'fulfill_order') {
        $orderId = (int)($input['player_order_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM player_orders WHERE player_order_id=? AND user_id=? AND order_status='accepted' AND is_fulfilled=0 LIMIT 1");
        $stmt->bind_param('ii', $orderId, $userId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc();
        if (!$order) throw new RuntimeException('Order is not active.');
        $stmt = $db->prepare("SELECT * FROM order_items WHERE player_order_id=?");
        $stmt->bind_param('i', $orderId); $stmt->execute(); $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($items as $item) {
            $itemId=(int)$item['item_id']; $need=(int)$item['quantity_required'];
            $stmt=$db->prepare("SELECT quantity FROM player_inventory WHERE user_id=? AND item_id=? LIMIT 1");
            $stmt->bind_param('ii',$userId,$itemId); $stmt->execute(); $inv=$stmt->get_result()->fetch_assoc();
            if (!$inv || (int)$inv['quantity'] < $need) throw new RuntimeException('You do not have everything for this order.');
        }
        foreach ($items as $item) removeInventory($db,$userId,(int)$item['item_id'],(int)$item['quantity_required']);
        $late = strtotime($order['expires_at']) < time();
        $lateFee = max(0, min(90, (int)($order['late_fee_percent'] ?? 20)));
        $payment = (int)$order['payment_coins'];
        if ($late) {
            if (($order['order_type'] ?? '') === 'rush') {
                $basePayment = (int)($order['base_payment_coins'] ?? 0);
                if ($basePayment <= 0) {
                    $basePayment = (int)floor($payment / 1.2);
                }
                $payment = (int)floor($basePayment * (100 - $lateFee) / 100);
            } else {
                $payment = (int)floor($payment * (100 - $lateFee) / 100);
            }
        }
        $stmt=$db->prepare("UPDATE users SET coins=coins+? WHERE user_id=?");
        $stmt->bind_param('ii',$payment,$userId); $stmt->execute();
        $repReward = $late ? 0 : (int)($order['reputation_reward'] ?? 1);
        $recReward = $late ? 0 : (int)($order['recognition_reward'] ?? 0);
        if ($repReward || $recReward) {
            $stmt=$db->prepare("UPDATE player_state SET reputation = reputation + ?, recognition = recognition + ? WHERE user_id=?");
            $stmt->bind_param('iii',$repReward,$recReward,$userId); $stmt->execute();
        }
        $stmt=$db->prepare("UPDATE player_orders SET order_status='fulfilled', is_fulfilled=1, fulfilled_at=NOW(), completed_late=? WHERE player_order_id=? AND user_id=?");
        $lateInt = $late ? 1 : 0;
        $stmt->bind_param('iii',$lateInt,$orderId,$userId); $stmt->execute();
        maybeGrantMarketInvite($db, $userId);
        $db->commit();
        $msg = $late ? ('Order completed late. +'.$payment.' coins.') : ('Order completed. +'.$payment.' coins. +'.$repReward.' reputation.');
        jsonResponse(['ok'=>true,'message'=>$msg, 'payment'=>$payment, 'reputation_delta'=>$repReward, 'late'=>$late]);
    }


    if ($action === 'admin_add_coins') {
        if (!isAdminUser($db, $userId)) {
            throw new RuntimeException('Admin only.');
        }

        $amount = 1000;
        $stmt = $db->prepare("UPDATE users SET coins = coins + ? WHERE user_id = ?");
        $stmt->bind_param('ii', $amount, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse([
            'ok' => true,
            'message' => '+' . $amount . ' coins.',
            'delta' => [
                'coins' => $amount
            ]
        ]);
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    $db->rollback();
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 200);
}
