<?php
function ensurePlayerDefaults(mysqli $db, int $userId): void
{
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
        INSERT INTO garden_plots (garden_id, x_pos, y_pos, is_unlocked, is_tilled, unlocked_at)
        VALUES (?, ?, ?, ?, 0, ?)
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
    $stmt = $db->prepare("
        SELECT pc.*, p.growth_steps, p.seconds_per_step, p.water_required, p.water_drain_per_hour
        FROM planted_crops pc
        JOIN plants p ON p.plant_id = pc.plant_id
        WHERE pc.user_id = ? AND pc.is_harvested = 0
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $crops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($crops as $crop) {
        $elapsed = max(0, time() - strtotime($crop['last_updated_at']));

        if ($elapsed <= 0) {
            continue;
        }

        $waterDrain = (int) floor(((int) $crop['water_drain_per_hour'] / 3600) * $elapsed);
        $water = max(0, (int) $crop['water_current'] - $waterDrain);

        $progress = (int) $crop['growth_progress_seconds'];
        $step = (int) $crop['growth_step_current'];

        if ($water >= (int) $crop['water_required'] && !(int) $crop['has_weeds'] && !(int) $crop['has_pests']) {
            $progress += $elapsed;

            while ($step < (int) $crop['growth_steps'] && $progress >= (int) $crop['seconds_per_step']) {
                $progress -= (int) $crop['seconds_per_step'];
                $step++;
            }
        }

        $stmt = $db->prepare("
            UPDATE planted_crops
            SET water_current = ?, growth_progress_seconds = ?, growth_step_current = ?, last_updated_at = NOW()
            WHERE planted_crop_id = ? AND user_id = ?
        ");
        $cropId = (int) $crop['planted_crop_id'];
        $stmt->bind_param('iiiii', $water, $progress, $step, $cropId, $userId);
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
