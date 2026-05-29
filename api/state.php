<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = requireLogin();
ensurePlayerDefaults($db, $userId);
ensureActiveOrder($db, $userId);
processGrowth($db, $userId);
processHelperAutomation($db, $userId);

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
           p.stage_icons_json, p.harvest_min, p.harvest_max, hi.icon AS mature_icon, si.icon AS seed_icon, p.harvest_item_id
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
    SELECT p.plant_id, p.code, p.name, p.width, p.height, p.growth_steps, p.water_max, p.harvest_min, p.harvest_max,
           hi.icon AS mature_icon, i.icon AS seed_icon, p.seed_item_id, i.base_buy_price, i.name AS seed_name
    FROM plants p
    JOIN items i ON i.item_id = p.seed_item_id
    JOIN items hi ON hi.item_id = p.harvest_item_id
    WHERE p.is_active = 1
    ORDER BY i.base_buy_price ASC
");
$plants = $stmt->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("
    SELECT i.item_id, i.code, i.name, i.item_type, i.base_buy_price, i.base_sell_price, i.icon, inv.quantity
    FROM player_inventory inv
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
    JOIN (
        SELECT t2.tool_type, MAX(t2.level) AS best_level
        FROM player_tools pt2
        JOIN tools t2 ON t2.tool_id = pt2.tool_id
        WHERE pt2.user_id = ?
        GROUP BY t2.tool_type
    ) best ON best.tool_type = t.tool_type AND best.best_level = t.level
    WHERE pt.user_id = ?
    ORDER BY FIELD(t.tool_type, 'hoe','watering_can','shovel'), t.level
");
$stmt->bind_param('ii', $userId, $userId);
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

$shedZones = [];
$shedPlaceables = [];
$shedObjects = [];
$shedStations = [];
$shedBackground = '';
$hasShedTables = false;
try {
    $res = $db->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_shed_objects'");
    $hasShedTables = $res && (int)($res->fetch_assoc()['c'] ?? 0) > 0;
} catch (Throwable $e) {
    $hasShedTables = false;
}
if ($hasShedTables) {
    try {
        $cfg = getGameConfig($db);
        $shedBackground = $cfg['shed_background_image'] ?? '';
    } catch (Throwable $e) {
        $shedBackground = '';
    }
    $res = $db->query("SELECT * FROM shed_zones WHERE is_active = 1 ORDER BY sort_order ASC, zone_key ASC");
    if ($res) $shedZones = $res->fetch_all(MYSQLI_ASSOC);
    $res = $db->query("SELECT * FROM placeable_defs WHERE is_active = 1 ORDER BY sort_order ASC, display_name ASC");
    if ($res) $shedPlaceables = $res->fetch_all(MYSQLI_ASSOC);
    $stmt = $db->prepare("
        SELECT pso.*, pd.placeable_key, pd.display_name, pd.icon_path, pd.grid_w, pd.grid_h, pd.can_rotate, pd.category,
               pm.machine_id, m.machine_type, m.name AS machine_name, m.icon AS machine_icon
        FROM player_shed_objects pso
        JOIN placeable_defs pd ON pd.placeable_id = pso.placeable_id
        LEFT JOIN player_machines pm ON pm.player_machine_id = pso.player_machine_id
        LEFT JOIN machines m ON m.machine_id = pm.machine_id
        WHERE pso.user_id = ? AND pso.is_active = 1
        ORDER BY pso.zone_key, pso.z_index, pso.shed_object_id
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $shedObjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    try {
        $res = $db->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shed_station_config'");
        $hasStations = $res && (int)($res->fetch_assoc()['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        $hasStations = false;
    }
    if ($hasStations) {
        $stmt = $db->prepare("
            SELECT s.*, m.machine_id, m.name AS machine_name, m.icon AS machine_icon, m.base_cost,
                   COALESCE(pm.quantity, 0) AS owned_quantity
            FROM shed_station_config s
            LEFT JOIN machines m ON m.machine_type = s.machine_type AND m.is_active = 1
            LEFT JOIN player_machines pm ON pm.machine_id = m.machine_id AND pm.user_id = ?
            WHERE s.is_active = 1
            ORDER BY s.sort_order ASC, s.station_key ASC
        " );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $shedStations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$stmt = $db->query("SELECT * FROM machines WHERE is_active = 1 ORDER BY base_cost ASC");
$allMachines = $stmt->fetch_all(MYSQLI_ASSOC);

$allTools = $db->query("SELECT * FROM tools WHERE is_active = 1 ORDER BY tool_type, level ASC")->fetch_all(MYSQLI_ASSOC);

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
    SELECT j.*, r.machine_type, r.output_item_id, r.output_quantity, oi.name AS output_name, oi.icon AS output_icon
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
$stmt = $db->prepare("SELECT * FROM player_orders WHERE user_id = ? AND order_status IN ('available','accepted') AND is_fulfilled = 0 ORDER BY FIELD(order_status, 'accepted', 'available'), expires_at ASC, player_order_id ASC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$allOpenOrders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$orderItemsByOrder = [];
$orders = [];
$availableOrders = [];
foreach ($allOpenOrders as &$orderRow) {
    $oid = (int)$orderRow['player_order_id'];
    $stmt = $db->prepare("SELECT oi.*, i.name, i.icon, i.code, COALESCE(inv.quantity, 0) AS owned_quantity FROM order_items oi JOIN items i ON i.item_id = oi.item_id LEFT JOIN player_inventory inv ON inv.item_id = oi.item_id AND inv.user_id = ? WHERE oi.player_order_id = ?");
    $stmt->bind_param('ii', $userId, $oid);
    $stmt->execute();
    $orderItemsByOrder[$oid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $remaining = max(0, strtotime($orderRow['expires_at']) - time());
    $orderRow['time_remaining_seconds'] = min($remaining, 7200);
    $orderRow['is_late'] = ($orderRow['order_status'] === 'accepted' && strtotime($orderRow['expires_at']) < time()) ? 1 : 0;
    $lateFee = max(0, min(90, (int)($orderRow['late_fee_percent'] ?? 20)));
    if (($orderRow['order_type'] ?? '') === 'rush') {
        $basePayment = (int)($orderRow['base_payment_coins'] ?? 0);
        if ($basePayment <= 0) {
            $basePayment = (int)floor((int)$orderRow['payment_coins'] / 1.2);
        }
        $orderRow['late_payment_coins'] = (int)floor($basePayment * (100 - $lateFee) / 100);
        $orderRow['late_total_penalty_percent'] = 40;
    } else {
        $orderRow['late_payment_coins'] = (int)floor((int)$orderRow['payment_coins'] * (100 - $lateFee) / 100);
        $orderRow['late_total_penalty_percent'] = $lateFee;
    }
    if ($orderRow['order_status'] === 'accepted') $orders[] = $orderRow;
    else $availableOrders[] = $orderRow;
}
unset($orderRow);
$order = $orders[0] ?? null;
$orderItems = $order ? ($orderItemsByOrder[(int)$order['player_order_id']] ?? []) : [];
$orderSlotLimit = getOrderSlotLimit($db, $userId);
$availableOrderLimit = getAvailableOrderLimit($db);
$shopSellLimits = getShopSellLimits($db, $userId);
$marketStatus = getMarketStatus($db, $userId);
$stmt = $db->prepare("SELECT COUNT(*) AS purchase_count FROM player_unlocks WHERE user_id = ? AND unlock_key LIKE 'shop_land_claim_note_%'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$landClaimShopPurchases = (int)($stmt->get_result()->fetch_assoc()['purchase_count'] ?? 0);

$progress = getPlayerProgress($db, $userId);
maybeGrantMarketInvite($db, $userId);
$locations = getLocationsForPlayer($db, $userId);
$unlocks = [];
$stmt = $db->prepare("SELECT unlock_key, source, unlocked_at FROM player_unlocks WHERE user_id = ? ORDER BY unlocked_at ASC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $unlocks[] = $row;

$relics = [];
$stmt = $db->prepare("SELECT * FROM player_relics WHERE user_id = ? ORDER BY discovered_at DESC LIMIT 20");
$stmt->bind_param('i', $userId);
$stmt->execute();
$relics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$helperEquipment = $db->query("SELECT * FROM helper_equipment WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetch_all(MYSQLI_ASSOC);
$helperAccessories = [];
try {
    $stmt = $db->prepare("
        SELECT he.*, i.item_id, i.name AS item_name, i.icon AS item_icon, COALESCE(inv.quantity,0) AS owned_quantity,
               (SELECT COUNT(*) FROM player_helpers ph2 WHERE ph2.user_id = ? AND ph2.equipped_helper_equipment_id = he.helper_equipment_id) AS equipped_count
        FROM helper_equipment he
        LEFT JOIN items i ON i.code = he.code AND i.item_type = 'helper_equipment'
        LEFT JOIN player_inventory inv ON inv.item_id = i.item_id AND inv.user_id = ?
        WHERE he.is_active = 1
          AND (COALESCE(inv.quantity,0) > 0 OR EXISTS (
              SELECT 1 FROM player_helpers ph3
              WHERE ph3.user_id = ? AND ph3.equipped_helper_equipment_id = he.helper_equipment_id
          ))
        ORDER BY he.sort_order ASC, he.name ASC
    " );
    $stmt->bind_param('iii', $userId, $userId, $userId);
    $stmt->execute();
    $helperAccessories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $helperAccessories = $helperEquipment;
}
$helperTypes = $db->query("SELECT * FROM helper_types WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetch_all(MYSQLI_ASSOC);
$stmt = $db->prepare("
    SELECT ph.*, ht.name, ht.species_key, ht.icon, he.name AS equipment_name, he.icon AS equipment_icon, he.task_type
    FROM player_helpers ph
    JOIN helper_types ht ON ht.helper_type_id = ph.helper_type_id
    LEFT JOIN helper_equipment he ON he.helper_equipment_id = ph.equipped_helper_equipment_id
    WHERE ph.user_id = ?
    ORDER BY ph.player_helper_id ASC
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$helpers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$systemIcons = [];
$sys = $db->query("SELECT code, icon FROM items WHERE item_type = 'system' AND is_active = 1");
while ($row = $sys->fetch_assoc()) {
    $systemIcons[$row['code']] = $row['icon'];
}

$relicPickup = getActiveRelicPickup($db, $userId);
$storyEvent = getPendingStoryEvent($db, $userId);

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
    'version' => getAppVersion($db),
    'clock' => getGameClock($db, $userId),
    'shop_refresh' => getShopRefresh($db, $userId),
    'market_status' => $marketStatus,
    'shop_sell_limits' => $shopSellLimits,
    'land_claim_shop_purchases' => $landClaimShopPurchases,
    'user' => array_merge($user, $progress),
    'progress' => $progress,
    'unlocks' => $unlocks,
    'locations' => $locations,
    'map_config' => getMapConfig($db),
    'relics' => $relics,
    'relic_pickup' => $relicPickup,
    'story_event' => $storyEvent,
    'location_events' => getLocationEvents($db, $userId),
    'helper_types' => $helperTypes,
    'helper_equipment' => $helperEquipment,
    'helper_accessories' => $helperAccessories,
    'is_admin' => isAdminUser($db, $userId),
    'garden' => $garden,
    'plots' => $plots,
    'crops' => $crops,
    'plants' => $plants,
    'inventory' => $inventory,
    'tools' => $tools,
    'machines' => $machines,
    'shed' => [
        'background_image' => $shedBackground,
        'zones' => $shedZones,
        'placeables' => $shedPlaceables,
        'objects' => $shedObjects,
        'stations' => $shedStations
    ],
    'all_machines' => $allMachines,
    'all_tools' => $allTools,
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
    'helpers' => $helpers,
    'plant_order' => $plantOrder,
    'order' => $order,
    'orders' => $orders,
    'available_orders' => $availableOrders,
    'order_slot_limit' => $orderSlotLimit,
    'available_order_limit' => $availableOrderLimit,
    'order_items' => $orderItems,
    'order_items_by_order' => $orderItemsByOrder,
    'server_time' => date('Y-m-d H:i:s')
]);
