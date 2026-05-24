<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = requireLogin();
ensurePlayerDefaults($db, $userId);
processGrowth($db, $userId);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

try {
    $db->begin_transaction();

    if ($action === 'till') {
        $plotId = (int) ($input['plot_id'] ?? 0);

        $stmt = $db->prepare("
            UPDATE garden_plots gp
            JOIN gardens g ON g.garden_id = gp.garden_id
            SET gp.is_tilled = 1
            WHERE gp.plot_id = ? AND g.user_id = ? AND gp.is_unlocked = 1
        ");
        $stmt->bind_param('ii', $plotId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'The dirt gives in.']);
    }

    if ($action === 'water') {
        $cropId = (int) ($input['planted_crop_id'] ?? 0);

        $stmt = $db->prepare("
            UPDATE planted_crops pc
            JOIN plants p ON p.plant_id = pc.plant_id
            SET pc.water_current = p.water_max
            WHERE pc.planted_crop_id = ? AND pc.user_id = ? AND pc.is_harvested = 0
        ");
        $stmt->bind_param('ii', $cropId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Watered. Mostly. The can tried.']);
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

    throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    $db->rollback();
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
}
