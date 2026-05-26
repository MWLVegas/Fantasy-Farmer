<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = requireLogin();
ensurePlayerDefaults($db, $userId);
ensureActiveOrder($db, $userId);
processGrowth($db, $userId);

// v0.3.12 hard safety: clamp bad active order timers from earlier builds before fetching state.
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

$stmt = $db->prepare("
    UPDATE player_orders
    SET is_expired = 1,
        next_available_at = DATE_ADD(NOW(), INTERVAL FLOOR(1 + RAND() * 4) MINUTE)
    WHERE user_id = ?
      AND is_fulfilled = 0
      AND is_expired = 0
      AND expires_at < NOW()
");
$stmt->bind_param('i', $userId);
$stmt->execute();



// v0.3.13 hard clamp: old buggy active orders must never stay above 60 minutes.
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

$stmt = $db->prepare("SELECT user_id, display_name, coins, energy FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$stmt = $db->prepare("
    SELECT g.*, gt.code AS garden_type_code, gt.name AS garden_type_name, gt.icon AS garden_type_icon
    FROM gardens g
    JOIN garden_types gt ON gt.garden_type_id = g.garden_type_id
    WHERE g.user_id = ?
    ORDER BY g.garden_id ASC
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$garden = $stmt->get_result()->fetch_assoc();

$gardenId = (int) $garden['garden_id'];

ensureRescuePouch($db, $userId, $gardenId);

$db->query("UPDATE player_pouches SET visual_state = 'waiting' WHERE is_claimed = 0 AND visual_state = 'arriving' AND TIMESTAMPDIFF(SECOND, visible_at, NOW()) >= 2");

$stmt = $db->prepare("SELECT * FROM garden_plots WHERE garden_id = ? ORDER BY y_pos, x_pos");
$stmt->bind_param('i', $gardenId);
$stmt->execute();
$plots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("
    SELECT pc.*, p.name, p.code, p.width, p.height, p.growth_steps, p.cycle_hour, p.water_max, p.water_required,
           p.stage_icons_json, hi.icon AS mature_icon, si.icon AS seed_icon, p.harvest_item_id
    FROM planted_crops pc
    JOIN plants p ON p.plant_id = pc.plant_id
    JOIN items hi ON hi.item_id = p.harvest_item_id
    JOIN items si ON si.item_id = p.seed_item_id
    WHERE pc.garden_id = ? AND pc.is_harvested = 0
");
$stmt->bind_param('i', $gardenId);
$stmt->execute();
$crops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->query("
    SELECT p.plant_id, p.code, p.name, p.width, p.height,
           i.icon AS seed_icon, p.seed_item_id, i.base_buy_price, i.name AS seed_name
    FROM plants p
    JOIN items i ON i.item_id = p.seed_item_id
    WHERE p.is_active = 1
    ORDER BY i.base_buy_price ASC
");
$plants = $stmt->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("
    SELECT i.item_id, i.code, i.name, i.item_type, i.base_buy_price, i.base_sell_price, i.icon, inv.quantity
    FROM inventory inv
    JOIN items i ON i.item_id = inv.item_id
    WHERE inv.user_id = ? AND inv.quantity > 0
    ORDER BY FIELD(i.item_type, 'seed','produce','processed','fuel','material'), i.name
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$inventory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("
    SELECT pt.player_tool_id, t.*
    FROM player_tools pt
    JOIN tools t ON t.tool_id = pt.tool_id
    WHERE pt.user_id = ?
    ORDER BY FIELD(t.tool_type, 'hoe','watering_can','shovel'), t.level
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$tools = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("
    SELECT pm.player_machine_id, pm.quantity, m.machine_id, m.machine_type, m.name, m.icon, m.queue_size, m.base_cost
    FROM player_machines pm
    JOIN machines m ON m.machine_id = pm.machine_id
    WHERE pm.user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$machines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->query("SELECT * FROM machines WHERE is_active = 1 ORDER BY base_cost ASC");
$allMachines = $stmt->fetch_all(MYSQLI_ASSOC);

$stmt = $db->query("
    SELECT r.*, ii.name AS input_name, ii.icon AS input_icon, oi.name AS output_name, oi.icon AS output_icon
    FROM processing_recipes r
    JOIN items ii ON ii.item_id = r.input_item_id
    JOIN items oi ON oi.item_id = r.output_item_id
    WHERE r.is_active = 1
    ORDER BY r.machine_type, oi.name
");
$recipes = $stmt->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("
    SELECT j.*, r.output_item_id, r.output_quantity, oi.name AS output_name, oi.icon AS output_icon
    FROM processing_jobs j
    JOIN processing_recipes r ON r.recipe_id = j.recipe_id
    JOIN items oi ON oi.item_id = r.output_item_id
    WHERE j.user_id = ? AND j.is_collected = 0
    ORDER BY j.finishes_at ASC
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


$workerTypes = $db->query("SELECT * FROM worker_types WHERE is_active = 1 ORDER BY hire_cost ASC")->fetch_all(MYSQLI_ASSOC);
$stmt = $db->prepare("SELECT pw.*, wt.name, wt.worker_role, wt.icon, wt.cost_per_game_hour, wt.task_seconds_min, wt.task_seconds_max FROM player_workers pw JOIN worker_types wt ON wt.worker_type_id = pw.worker_type_id WHERE pw.user_id = ? ORDER BY pw.player_worker_id ASC");
$stmt->bind_param('i', $userId); $stmt->execute(); $workers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt = $db->prepare("SELECT * FROM player_worker_plant_order WHERE user_id = ? ORDER BY sort_order ASC");
$stmt->bind_param('i', $userId); $stmt->execute(); $plantOrder = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt = $db->prepare("SELECT * FROM player_orders WHERE user_id = ? AND is_fulfilled = 0 AND is_expired = 0 ORDER BY player_order_id DESC LIMIT 1");
$stmt->bind_param('i', $userId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc();
$orderItems = [];
if ($order) {
    $stmt = $db->prepare("SELECT oi.*, i.name, i.icon, i.code, COALESCE(inv.quantity, 0) AS owned_quantity FROM order_items oi JOIN items i ON i.item_id = oi.item_id LEFT JOIN inventory inv ON inv.item_id = oi.item_id AND inv.user_id = ? WHERE oi.player_order_id = ?");
    $oid = (int)$order['player_order_id']; $stmt->bind_param('ii', $userId, $oid); $stmt->execute(); $orderItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $remaining = max(0, strtotime($order['expires_at']) - time());
    $order['time_remaining_seconds'] = min($remaining, 3600);
}

$systemIcons = [];
$sys = $db->query("SELECT code, icon FROM items WHERE item_type = 'system' AND is_active = 1");
while ($row = $sys->fetch_assoc()) {
    $systemIcons[$row['code']] = $row['icon'];
}

$fertilizers = [];
if ($gardenId) {
    $stmt = $db->prepare("
        SELECT cf.*, i.code, i.name, i.icon, fd.effect_type, fd.visual_icon
        FROM crop_fertilizers cf
        JOIN items i ON i.item_id = cf.item_id
        LEFT JOIN fertilizer_definitions fd ON fd.item_id = cf.item_id
        WHERE cf.user_id = ?
          AND cf.garden_id = ?
          AND cf.is_active = 1
          AND cf.consumed_at IS NULL
    ");
    $stmt->bind_param('ii', $userId, $gardenId);
    $stmt->execute();
    $fertilizers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

jsonResponse([
    'ok' => true,
    'version' => GAME_VERSION,
    'clock' => getGameClock($db, $userId),
    'shop_refresh' => getShopRefresh($db, $userId),
    'user' => $user,
    'is_admin' => isAdminUser($db, $userId),
    'garden' => $garden,
    'plots' => $plots,
    'crops' => $crops,
    'plants' => $plants,
    'inventory' => $inventory,
    'tools' => $tools,
    'machines' => $machines,
    'all_machines' => $allMachines,
    'recipes' => $recipes,
    'jobs' => $jobs,
    'system_icons' => $systemIcons,
    'fertilizers' => $fertilizers,
    'pouch' => (function() use ($db, $userId) {
        $stmt = $db->prepare("SELECT * FROM player_pouches WHERE user_id = ? AND is_claimed = 0 ORDER BY pouch_id DESC LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    })(),
    'workers' => $workers,
    'worker_types' => $workerTypes,
    'plant_order' => $plantOrder,
    'order' => $order,
    'order_items' => $orderItems,
    'server_time' => date('Y-m-d H:i:s')
]);
