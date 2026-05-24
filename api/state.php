<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = requireLogin();
ensurePlayerDefaults($db, $userId);
processGrowth($db, $userId);

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

$stmt = $db->prepare("SELECT * FROM garden_plots WHERE garden_id = ? ORDER BY y_pos, x_pos");
$stmt->bind_param('i', $gardenId);
$stmt->execute();
$plots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("
    SELECT pc.*, p.name, p.code, p.width, p.height, p.growth_steps, p.seconds_per_step, p.water_max, p.water_required,
           p.stage_icons_json, p.mature_icon, p.harvest_item_id
    FROM planted_crops pc
    JOIN plants p ON p.plant_id = pc.plant_id
    WHERE pc.garden_id = ? AND pc.is_harvested = 0
");
$stmt->bind_param('i', $gardenId);
$stmt->execute();
$crops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->query("
    SELECT p.plant_id, p.code, p.name, p.width, p.height, p.seed_icon, p.seed_item_id, i.base_buy_price, i.name AS seed_name
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

jsonResponse([
    'ok' => true,
    'version' => GAME_VERSION,
    'user' => $user,
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
    'server_time' => date('Y-m-d H:i:s')
]);
