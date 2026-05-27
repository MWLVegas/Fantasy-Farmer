<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = requireLogin();
ensurePlayerDefaults($db, $userId);
processGrowth($db, $userId);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

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

try {
    $db->begin_transaction();

    if ($action === 'till') {
        $plotId = (int) ($input['plot_id'] ?? 0);
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

        $stmt = $db->prepare("
            INSERT INTO planted_crops (user_id, garden_id, plant_id, origin_x, origin_y, water_current)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->bind_param('iiiii', $userId, $gardenId, $plantId, $x, $y);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $plant['name'] . ' planted.']);
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

        $stmt = $db->prepare("SELECT base_sell_price, name FROM items WHERE item_id = ? LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();

        if (!$item || (int) $item['base_sell_price'] <= 0) {
            throw new RuntimeException('That cannot be sold.');
        }

        if (!removeInventory($db, $userId, $itemId, $qty)) {
            throw new RuntimeException('Not enough items.');
        }

        $coins = (int) $item['base_sell_price'] * $qty;

        $stmt = $db->prepare("UPDATE users SET coins = coins + ? WHERE user_id = ?");
        $stmt->bind_param('ii', $coins, $userId);
        $stmt->execute();

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

    if ($action === 'buy_machine') {
        $machineId = (int) ($input['machine_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM machines WHERE machine_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $machineId);
        $stmt->execute();
        $machine = $stmt->get_result()->fetch_assoc();

        if (!$machine) {
            throw new RuntimeException('Machine not found.');
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

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $machine['name'] . ' bought.']);
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
            SELECT pm.player_machine_id
            FROM player_machines pm
            JOIN machines m ON m.machine_id = pm.machine_id
            WHERE pm.user_id = ? AND m.machine_type = ?
            LIMIT 1
        ");
        $stmt->bind_param('is', $userId, $recipe['machine_type']);
        $stmt->execute();
        $machine = $stmt->get_result()->fetch_assoc();

        if (!$machine) {
            throw new RuntimeException('You need the right equipment.');
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
