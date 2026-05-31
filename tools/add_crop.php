<?php
/**
 * Fantasy Farmer crop inserter
 *
 * Usage:
 * 1) Put this file somewhere temporary/admin-only.
 * 2) Update the require_once path below so $db is your existing mysqli connection.
 * 3) Edit $crops if needed.
 * 4) Load from browser or run with: php add_fantasy_farmer_crops.php
 * 5) Delete this file after running.
 */

// TODO: point this at your real DB bootstrap. It must create a mysqli connection named $db.
// require_once __DIR__ . '/includes/db.php';

if (!isset($db) || !($db instanceof mysqli)) {
    http_response_code(500);
    exit("Database connection \$db was not found. Update the require_once path in this file first.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db->set_charset('utf8mb4');

/**
 * Turn a crop display name into a stable code/path slug.
 * Examples: Bell Pepper => bell_pepper, Chili Pepper => chili_pepper
 */
function crop_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_');
}

function item_exists(mysqli $db, string $code): bool
{
    $stmt = $db->prepare('SELECT item_id FROM items WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function plant_exists(mysqli $db, string $code): bool
{
    $stmt = $db->prepare('SELECT plant_id FROM plants WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

/**
 * Add one crop, including:
 * - seed item in items
 * - harvested produce item in items
 * - plant row in plants, wired to the two item IDs
 */
function add_crop(mysqli $db, string $name, int $growthStages, array $options = []): array
{
    $code = $options['code'] ?? crop_slug($name);
    $seedCode = $options['seed_code'] ?? ($code . '_seed');

    if ($growthStages < 1) {
        throw new InvalidArgumentException("{$name} must have at least 1 growth stage.");
    }

    if (item_exists($db, $seedCode) || item_exists($db, $code) || plant_exists($db, $code)) {
        throw new RuntimeException("Skipping {$name}: item or plant code already exists ({$seedCode} / {$code}).");
    }

    $allowedGardenTypeCode = $options['allowed_garden_type_code'] ?? 'farm';
    $allowedGardenTypesJson = json_encode($options['allowed_garden_types'] ?? [1], JSON_UNESCAPED_SLASHES);

    $seedName = $options['seed_name'] ?? ($name . ' Seeds');
    $seedIcon = $options['seed_icon'] ?? "assets/icons/seed-{$code}.png";
    $itemIcon = $options['item_icon'] ?? "assets/icons/item-{$code}.png";

    $seedBuy = (int)($options['seed_buy'] ?? 5);
    $seedSell = (int)($options['seed_sell'] ?? 0);
    $produceBuy = (int)($options['produce_buy'] ?? 6);
    $produceSell = (int)($options['produce_sell'] ?? 4);
    $isActive = (int)($options['is_active'] ?? 1);

    $width = (int)($options['width'] ?? 1);
    $height = (int)($options['height'] ?? 1);
    $cycleHours = (int)($options['cycle_hour'] ?? 6);
    $waterMax = (int)($options['water_max'] ?? 100);
    $waterRequired = (int)($options['water_required'] ?? 30);
    $waterDrainPerGameHour = (int)($options['water_drain_per_game_hour'] ?? 4);
    $harvestMin = (int)($options['harvest_min'] ?? 1);
    $harvestMax = (int)($options['harvest_max'] ?? 3);
    $unlockCost = (int)($options['unlock_cost'] ?? 0);

    $stageIcons = [];
    for ($i = 1; $i <= $growthStages; $i++) {
        $stageIcons[] = "assets/icons/crops/{$code}_{$i}.png";
    }
    $stageIconsJson = json_encode($stageIcons, JSON_UNESCAPED_SLASHES);

    $db->begin_transaction();

    try {
        $itemType = 'seed';
        $workSprite = null;
        $stmt = $db->prepare('
            INSERT INTO items
                (code, name, item_type, base_buy_price, base_sell_price, icon, shop_row_icon, work_sprite, is_active)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param(
            'sssiiissi',
            $seedCode,
            $seedName,
            $itemType,
            $seedBuy,
            $seedSell,
            $seedIcon,
            $seedIcon,
            $workSprite,
            $isActive
        );
        $stmt->execute();
        $seedItemId = $db->insert_id;

        $itemType = 'produce';
        $stmt = $db->prepare('
            INSERT INTO items
                (code, name, item_type, base_buy_price, base_sell_price, icon, shop_row_icon, work_sprite, is_active)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param(
            'sssiiissi',
            $code,
            $name,
            $itemType,
            $produceBuy,
            $produceSell,
            $itemIcon,
            $itemIcon,
            $workSprite,
            $isActive
        );
        $stmt->execute();
        $harvestItemId = $db->insert_id;

        $stmt = $db->prepare('
            INSERT INTO plants
                (code, name, allowed_garden_type_code, allowed_garden_types_json,
                 seed_item_id, harvest_item_id, width, height, growth_steps, cycle_hour,
                 water_max, water_required, water_drain_per_game_hour, harvest_min, harvest_max,
                 stage_icons_json, unlock_cost, is_active)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param(
            'ssssiiiiiiiiiiisii',
            $code,
            $name,
            $allowedGardenTypeCode,
            $allowedGardenTypesJson,
            $seedItemId,
            $harvestItemId,
            $width,
            $height,
            $growthStages,
            $cycleHours,
            $waterMax,
            $waterRequired,
            $waterDrainPerGameHour,
            $harvestMin,
            $harvestMax,
            $stageIconsJson,
            $unlockCost,
            $isActive
        );
        $stmt->execute();
        $plantId = $db->insert_id;

        $db->commit();

        return [
            'name' => $name,
            'code' => $code,
            'seed_item_id' => $seedItemId,
            'harvest_item_id' => $harvestItemId,
            'plant_id' => $plantId,
            'stage_icons_json' => $stageIconsJson,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

$crops = [
    ['name' => 'Onion',        'stages' => 2],
    ['name' => 'Bell Pepper',  'stages' => 5],
    ['name' => 'Squash',       'stages' => 4],
    ['name' => 'Pumpkin',      'stages' => 5],
    ['name' => 'Corn',         'stages' => 4],
    ['name' => 'Tomato',       'stages' => 4],
    ['name' => 'Potato',       'stages' => 3],
    ['name' => 'Chili Pepper', 'stages' => 3],
];

header('Content-Type: text/plain; charset=utf-8');

echo "Fantasy Farmer crop insert starting...\n\n";

foreach ($crops as $crop) {
    try {
        $result = add_crop($db, $crop['name'], (int)$crop['stages']);
        echo "Added {$result['name']} | plant_id={$result['plant_id']} | seed_item_id={$result['seed_item_id']} | harvest_item_id={$result['harvest_item_id']}\n";
    } catch (Throwable $e) {
        echo "ERROR: {$crop['name']} - {$e->getMessage()}\n";
    }
}

echo "\nDone. Delete this file after use.\n";
