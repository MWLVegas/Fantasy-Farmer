<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$userId = requireLogin();
ensurePlayerDefaults($db, $userId);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$plantCyclesSelect = columnExists($db, 'plants', 'max_cycles') ? 'p.max_cycles' : 'p.growth_steps';
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

function playerHasMinimumReputation(mysqli $db, int $userId, int $minimum): bool
{
    $stmt = $db->prepare("
        SELECT COALESCE(reputation, 0) AS reputation
        FROM player_state
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    return $row && (int)$row['reputation'] >= $minimum;
}

function playerHasRelicKey(mysqli $db, int $userId, string $relicKey): bool
{
    $stmt = $db->prepare("
        SELECT 1
        FROM player_relics
        WHERE user_id = ?
          AND relic_key = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $userId, $relicKey);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function boneBrineHasArrived(mysqli $db, int $userId): bool
{
    return hasUnlock($db, $userId, 'location_bone_brine')
        || hasUnlock($db, $userId, 'bone_brine_unlocked');
}

function maybeDropBoneBrineRelicFragment(mysqli $db, int $userId, int $chancePercent): bool
{
    if (!boneBrineHasArrived($db, $userId)) {
        return false;
    }

    $chancePercent = max(0, min(100, $chancePercent));

    if ($chancePercent <= 0) {
        return false;
    }

    if (random_int(1, 100) > $chancePercent) {
        return false;
    }

    return maybeDropCommonRelic($db, $userId);
}

function maybeFindBoneBrineRelicFromTilling(mysqli $db, int $userId, int $plotId): bool
{
    if (!playerHasMinimumReputation($db, $userId, 25)) {
        return false;
    }

    // Relic #2 should only happen after Relic #1 has actually been collected.
    if (!hasUnlock($db, $userId, 'first_relic_collected')) {
        return false;
    }

    if (hasUnlock($db, $userId, 'second_relic_collected')) {
        return false;
    }

    if (hasUnlock($db, $userId, 'bone_brine_pending')) {
        return false;
    }

    if (playerHasRelicKey($db, $userId, 'second_field_relic')) {
        return false;
    }

    // Reasonable chance while tilling after reputation 25.
    if (random_int(1, 100) > 15) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT gp.x_pos, gp.y_pos, gp.garden_id
        FROM garden_plots gp
        JOIN gardens g ON g.garden_id = gp.garden_id
        WHERE gp.plot_id = ?
          AND g.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $plotId, $userId);
    $stmt->execute();

    $plot = $stmt->get_result()->fetch_assoc();

    if (!$plot) {
        return false;
    }

    $gardenId = (int)$plot['garden_id'];

    $stmt = $db->prepare("
        SELECT
            MIN(x_pos) AS min_x,
            MAX(x_pos) AS max_x,
            MIN(y_pos) AS min_y,
            MAX(y_pos) AS max_y
        FROM garden_plots
        WHERE garden_id = ?
    ");
    $stmt->bind_param('i', $gardenId);
    $stmt->execute();

    $bounds = $stmt->get_result()->fetch_assoc();

    $minX = (int)($bounds['min_x'] ?? 0);
    $maxX = (int)($bounds['max_x'] ?? $minX);
    $minY = (int)($bounds['min_y'] ?? 0);
    $maxY = (int)($bounds['max_y'] ?? $minY);

    $cols = max(1, ($maxX - $minX) + 1);
    $rows = max(1, ($maxY - $minY) + 1);

    // Center of the actual tilled plot, converted into the existing x_ratio/y_ratio system.
    $xRatio = (((int)$plot['x_pos'] - $minX) + 0.5) / $cols;
    $yRatio = (((int)$plot['y_pos'] - $minY) + 0.5) / $rows;

    // Keep it safely inside the canvas padding.
    $xRatio = max(0.06, min(0.94, $xRatio));
    $yRatio = max(0.06, min(0.94, $yRatio));

    $stmt = $db->prepare("
        INSERT INTO player_relics
            (
                user_id,
                relic_key,
                display_name,
                relic_type,
                source_action,
                x_ratio,
                y_ratio,
                visual_state,
                discovered_at
            )
        VALUES
            (
                ?,
                'second_field_relic',
                'Cursed Found Relic',
                'oddity',
                'till',
                ?,
                ?,
                'waiting',
                NOW()
            )
    ");

    $stmt->bind_param('idd', $userId, $xRatio, $yRatio);
    $stmt->execute();

    return true;
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
    SET gp.till_progress = GREATEST(gp.till_progress, ?),
        gp.is_tilled = GREATEST(gp.is_tilled, ?)
    WHERE gp.plot_id = ?
      AND g.user_id = ?
      AND gp.is_unlocked = 1
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
            gp.is_tilled = CASE
                WHEN LEAST(100, gp.till_progress + ?) >= 100 THEN 1
                ELSE gp.is_tilled
            END
        WHERE gp.plot_id = ?
          AND g.user_id = ?
          AND gp.is_unlocked = 1
    ");
    $stmt->bind_param('iiii', $strength, $strength, $plotId, $userId);
    $stmt->execute();

    $firstRelicName = null;
$secondRelic = false;
$fragmentDropped = false;

$canFindFirstRelic =
    !hasUnlock($db, $userId, 'first_relic_collected') &&
    !playerHasRelicKey($db, $userId, 'first_field_relic');

if ($canFindFirstRelic) {
    $firstRelicName = maybeFindFirstRelicFromTilling($db, $userId, $plotId, $tool);
}

if (!$firstRelicName) {
    $secondRelic = maybeFindBoneBrineRelicFromTilling($db, $userId, $plotId);

    if (!$secondRelic) {
        // After Bone & Brine have actually arrived, hoeing has a flat 10% fragment chance.
        $fragmentDropped = maybeDropBoneBrineRelicFragment($db, $userId, 10);
    }
}

    $message = toolMessage($tool, 'Tilled.');

    if ($firstRelicName || $secondRelic) {
        $message = 'Something strange surfaced in the soil.';
    } elseif ($fragmentDropped) {
        $message = 'You found a rune fragment in the soil.';
    }

    $db->commit();

    jsonResponse([
        'ok' => true,
        'message' => $message,
        'relic_spawned' => (bool)($firstRelicName || $secondRelic),
        'relic_found' => $firstRelicName ?: ($secondRelic ? 'Bone & Brine Relic' : null)
    ]);
}

    if ($action === 'water') {
        $cropId = (int) ($input['planted_crop_id'] ?? 0);

        if ($isTrustedGardenAction) {
            $water = max(0, (int)($input['water_current'] ?? 0));
            if ($cropId > 0) {
                $stmt = $db->prepare("
                    UPDATE planted_crops pc
                    JOIN plants p ON p.plant_id = pc.plant_id
                    SET pc.water_current = GREATEST(pc.water_current, LEAST(p.water_max, ?))
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

    $stmt = $db->prepare("
        SELECT
            pc.planted_crop_id,
            pc.garden_id,
            pc.origin_x,
            pc.origin_y,
            pc.growth_step_current,
            p.width,
            p.height
        FROM planted_crops pc
        JOIN plants p ON p.plant_id = pc.plant_id
        WHERE pc.planted_crop_id = ?
          AND pc.user_id = ?
          AND pc.is_harvested = 0
        LIMIT 1
    ");
    $stmt->bind_param('ii', $cropId, $userId);
    $stmt->execute();

    $crop = $stmt->get_result()->fetch_assoc();

    if (!$crop) {
        throw new RuntimeException('Crop not found.');
    }

    $currentStage = max(0, (int)$crop['growth_step_current']);

    // After Bone & Brine have arrived:
    // stage 0 = 0%
    // stage 1 = 20%
    // stage 2 = 40%
    // stage 3 = 60%
    // stage 4 = 80%
    // stage 5+ = 100%
    $fragmentChance = min(100, $currentStage * 20);
    $fragmentDropped = maybeDropBoneBrineRelicFragment($db, $userId, $fragmentChance);

    $stmt = $db->prepare("
        UPDATE planted_crops
        SET is_harvested = 1
        WHERE planted_crop_id = ?
          AND user_id = ?
    ");
    $stmt->bind_param('ii', $cropId, $userId);
    $stmt->execute();

    $gardenId = (int)$crop['garden_id'];
    $originX  = (int)$crop['origin_x'];
    $originY  = (int)$crop['origin_y'];
    $width    = max(1, (int)$crop['width']);
    $height   = max(1, (int)$crop['height']);

    $x2 = $originX + $width;
    $y2 = $originY + $height;

    $stmt = $db->prepare("
        UPDATE garden_plots
        SET is_tilled = 0,
            till_progress = 0
        WHERE garden_id = ?
          AND x_pos >= ?
          AND x_pos < ?
          AND y_pos >= ?
          AND y_pos < ?
    ");
    $stmt->bind_param('iiiii', $gardenId, $originX, $x2, $originY, $y2);
    $stmt->execute();

    $message = 'Crop dug up.';

    if ($fragmentDropped) {
        $message .= ' You found a rune fragment in the roots.';
    }

    $db->commit();

    jsonResponse([
        'ok' => true,
        'message' => $message,
        'fragment_dropped' => $fragmentDropped
    ]);
} 
 
    if ($action === 'place_pot') {
        $gardenId  = (int)($input['garden_id']  ?? 0);
        $potTypeId = (int)($input['pot_type_id'] ?? 0);
        $gx        = max(1, (int)($input['grid_x'] ?? 1));
        $gy        = max(1, (int)($input['grid_y'] ?? 1));

        $stmt = $db->prepare("SELECT g.garden_id, gt.code AS garden_type_code FROM gardens g JOIN garden_types gt ON gt.garden_type_id = g.garden_type_id WHERE g.garden_id = ? AND g.user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $gardenId, $userId);
        $stmt->execute();
        $garden = $stmt->get_result()->fetch_assoc();
        if (!$garden || $garden['garden_type_code'] !== 'ornamental') throw new RuntimeException('Not an ornamental garden.');

        $stmt = $db->prepare("SELECT pt.*, i.item_id FROM ornamental_pot_types pt JOIN items i ON i.item_id = pt.item_id WHERE pt.pot_type_id = ? AND pt.is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $potTypeId);
        $stmt->execute();
        $potType = $stmt->get_result()->fetch_assoc();
        if (!$potType) throw new RuntimeException('Pot type not found.');

        $stmt = $db->prepare("SELECT placement_id FROM player_pot_placements WHERE user_id = ? AND garden_id = ? AND grid_x = ? AND grid_y = ? LIMIT 1");
        $stmt->bind_param('iiii', $userId, $gardenId, $gx, $gy);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) throw new RuntimeException('A pot is already there.');

        if (!removeInventory($db, $userId, (int)$potType['item_id'], 1)) throw new RuntimeException('You do not have that pot.');

        $stmt = $db->prepare("INSERT INTO player_pot_placements (user_id, garden_id, pot_type_id, grid_x, grid_y) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iiiii', $userId, $gardenId, $potTypeId, $gx, $gy);
        $stmt->execute();
        $db->commit();
        jsonResponse(['ok' => true, 'message' => $potType['name'] . ' placed.']);
    }

    if ($action === 'dig_pot_plant') {
        $placementId  = (int)($input['placement_id']  ?? 0);
        $plantedCropId = (int)($input['planted_crop_id'] ?? 0);

        $stmt = $db->prepare("SELECT pp.placement_id FROM player_pot_placements pp WHERE pp.placement_id = ? AND pp.user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $placementId, $userId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) throw new RuntimeException('Pot not found.');

        // Mark the planted crop as harvested (removed)
        if ($plantedCropId > 0) {
            $stmt = $db->prepare("UPDATE planted_crops SET is_harvested = 1 WHERE planted_crop_id = ? AND garden_id IN (SELECT garden_id FROM player_pot_placements WHERE placement_id = ?)");
            $stmt->bind_param('ii', $plantedCropId, $placementId);
            $stmt->execute();
        }

        // Detach the crop from the pot
        $stmt = $db->prepare("UPDATE player_pot_placements SET planted_crop_id = NULL, last_offering_at = NULL WHERE placement_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $placementId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Plant removed.']);
    }

    if ($action === 'remove_pot') {
        $placementId = (int)($input['placement_id'] ?? 0);
        $stmt = $db->prepare("SELECT pp.*, pt.item_id FROM player_pot_placements pp JOIN ornamental_pot_types pt ON pt.pot_type_id = pp.pot_type_id WHERE pp.placement_id = ? AND pp.user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $placementId, $userId);
        $stmt->execute();
        $placement = $stmt->get_result()->fetch_assoc();
        if (!$placement) throw new RuntimeException('Pot not found.');
        if (!empty($placement['planted_crop_id'])) throw new RuntimeException('Remove the plant first.');

        addInventory($db, $userId, (int)$placement['item_id'], 1);
        $stmt = $db->prepare("DELETE FROM player_pot_placements WHERE placement_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $placementId, $userId);
        $stmt->execute();
        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Pot returned to backpack.']);
    }

    if ($action === 'collect_pot_offering') {
        $placementId = (int)($input['placement_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT pp.*, pc.growth_step_current, p.max_cycles AS growth_steps
            FROM player_pot_placements pp
            LEFT JOIN planted_crops pc ON pc.planted_crop_id = pp.planted_crop_id AND pc.is_harvested = 0
            LEFT JOIN plants p ON p.plant_id = pc.plant_id
            WHERE pp.placement_id = ? AND pp.user_id = ? LIMIT 1
        ");
        $stmt->bind_param('ii', $placementId, $userId);
        $stmt->execute();
        $placement = $stmt->get_result()->fetch_assoc();
        if (!$placement || empty($placement['planted_crop_id'])) throw new RuntimeException('Nothing growing here.');

        if ((int)$placement['growth_step_current'] < (int)$placement['growth_steps']) {
            throw new RuntimeException('Not fully grown yet.');
        }

        $config   = getGameConfig($db);
        $dayLen   = max(60, (int)($config['day_length_seconds'] ?? 720));
        $lastAt   = $placement['last_offering_at'] ? strtotime($placement['last_offering_at']) : 0;
        if ($lastAt && time() - $lastAt < $dayLen) throw new RuntimeException('The fae have not visited yet today.');

        $faeItem = $db->query("SELECT item_id, icon, name FROM items WHERE code = 'fae_tip' AND is_active = 1 LIMIT 1")->fetch_assoc();
        if (!$faeItem) throw new RuntimeException('Missing fae offering item.');

        $qty = random_int(1, 3);
        addInventory($db, $userId, (int)$faeItem['item_id'], $qty);

        $stmt = $db->prepare("UPDATE player_pot_placements SET last_offering_at = NOW() WHERE placement_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $placementId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'The fae left ' . $faeItem['icon'] . ' ×' . $qty . '.', 'qty' => $qty]);
    }

    if ($action === 'plant') {
        $gardenId = (int) ($input['garden_id'] ?? 0);
        $plantId = (int) ($input['plant_id'] ?? 0);
        $x = (int) ($input['x'] ?? 0);
        $y = (int) ($input['y'] ?? 0);

        $stmt = $db->prepare("SELECT g.garden_id, gt.code AS garden_type_code FROM gardens g JOIN garden_types gt ON gt.garden_type_id = g.garden_type_id WHERE g.garden_id = ? AND g.user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $gardenId, $userId);
        $stmt->execute();
        $gardenRow = $stmt->get_result()->fetch_assoc();

        if (!$gardenRow) {
            throw new RuntimeException('Garden not found.');
        }

        // Ornamental pot planting — bypass normal plot checks
        if ($gardenRow['garden_type_code'] === 'ornamental') {
            $stmt = $db->prepare("SELECT pp.*, pt.pot_size FROM player_pot_placements pp JOIN ornamental_pot_types pt ON pt.pot_type_id = pp.pot_type_id WHERE pp.user_id = ? AND pp.garden_id = ? AND pp.grid_x = ? AND pp.grid_y = ? LIMIT 1");
            $stmt->bind_param('iiii', $userId, $gardenId, $x, $y);
            $stmt->execute();
            $placement = $stmt->get_result()->fetch_assoc();
            if (!$placement) throw new RuntimeException('No pot at that position.');
            if (!empty($placement['planted_crop_id'])) throw new RuntimeException('This pot already has a plant.');

            $stmt = $db->prepare("SELECT *, COALESCE(cycle_length_hours, 24) AS cycle_length_hours FROM plants WHERE plant_id = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('i', $plantId);
            $stmt->execute();
            $plant = $stmt->get_result()->fetch_assoc();
            if (!$plant) throw new RuntimeException('Plant not found.');

            $potSize   = (string)($placement['pot_size'] ?? 'small');
            $plantSize = (string)($plant['ornamental_pot_size'] ?? 'small');
            if ($plantSize !== 'any' && $plantSize !== $potSize) {
                throw new RuntimeException("This plant needs a {$plantSize} pot.");
            }

            if (!removeInventory($db, $userId, (int)$plant['seed_item_id'], 1)) throw new RuntimeException('You need seeds for that plant.');

            $clock = getGameClock($db, $userId);
            $cycleIndex = cycleIndexForElapsedHours((float)$clock['total_game_hours_elapsed'], (int)$plant['cycle_hour'], (int)$plant['cycle_length_hours']);

            $stmt = $db->prepare("INSERT INTO planted_crops (user_id, garden_id, plant_id, origin_x, origin_y, water_current, last_cycle_index) VALUES (?, ?, ?, ?, ?, 0, ?)");
            $stmt->bind_param('iiiiii', $userId, $gardenId, $plantId, $x, $y, $cycleIndex);
            $stmt->execute();
            $plantedCropId = (int)$db->insert_id;

            $ornamentalPlacementId = (int)$placement['placement_id'];
            $stmt = $db->prepare("UPDATE player_pot_placements SET planted_crop_id = ? WHERE placement_id = ? AND user_id = ?");
            $stmt->bind_param('iii', $plantedCropId, $ornamentalPlacementId, $userId);
            $stmt->execute();

            if (($plant['code'] ?? '') === 'hauntling_pepper') grantUnlock($db, $userId, 'hauntling_pepper_planted', 'plant_action');

            $db->commit();
            jsonResponse(['ok' => true, 'message' => $plant['name'] . ' planted.', 'planted_crop_id' => $plantedCropId]);
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
        $plantCycleIndex = cycleIndexForElapsedHours((float)$clock['total_game_hours_elapsed'], (int)$plant['cycle_hour'], (int)($plant['cycle_length_hours'] ?? 24));

        $stmt = $db->prepare("
            INSERT INTO planted_crops (user_id, garden_id, plant_id, origin_x, origin_y, water_current, last_cycle_index)
            VALUES (?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->bind_param('iiiiii', $userId, $gardenId, $plantId, $x, $y, $plantCycleIndex);
        $stmt->execute();
        $plantedCropId = (int)$db->insert_id;

        // Grant a flag on first plant of special crops so event triggers can detect it
        if (($plant['code'] ?? '') === 'hauntling_pepper') {
            grantUnlock($db, $userId, 'hauntling_pepper_planted', 'plant_action');
        }

        $db->commit();
        jsonResponse(['ok' => true, 'message' => $plant['name'] . ' planted.', 'planted_crop_id' => $plantedCropId]);
    }


    if ($action === 'harvest_crop_problem') {
        $problemId = (int)($input['crop_problem_id'] ?? 0);
        if ($problemId <= 0) throw new RuntimeException('Missing problem.');

        $stmt = $db->prepare("
            SELECT cp.*, i.item_id
            FROM crop_problems cp
            LEFT JOIN items i ON i.code = cp.reward_item_code
            WHERE cp.crop_problem_id = ? AND cp.user_id = ? AND cp.is_resolved = 0
            LIMIT 1
        ");
        $stmt->bind_param('ii', $problemId, $userId);
        $stmt->execute();
        $problem = $stmt->get_result()->fetch_assoc();
        if (!$problem) throw new RuntimeException('That problem is already cleared.');

        if (!empty($problem['item_id'])) addInventory($db, $userId, (int)$problem['item_id'], 1);

        $stmt = $db->prepare("UPDATE crop_problems SET is_resolved = 1, resolved_at = NOW() WHERE crop_problem_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $problemId, $userId);
        $stmt->execute();

        $cropId = (int)$problem['planted_crop_id'];
        $stmt = $db->prepare("
            UPDATE planted_crops pc
            SET has_weeds = EXISTS (SELECT 1 FROM crop_problems cp WHERE cp.planted_crop_id = pc.planted_crop_id AND cp.problem_type = 'weed' AND cp.is_resolved = 0),
                has_pests = EXISTS (SELECT 1 FROM crop_problems cp WHERE cp.planted_crop_id = pc.planted_crop_id AND cp.problem_type = 'pest' AND cp.is_resolved = 0)
            WHERE pc.planted_crop_id = ? AND pc.user_id = ?
        ");
        $stmt->bind_param('ii', $cropId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Cleared ' . $problem['name'] . '.']);
    }

    if ($action === 'harvest') {
        $cropId = (int) ($input['planted_crop_id'] ?? 0);

        $stmt = $db->prepare("
            SELECT pc.*, {$plantCyclesSelect} AS growth_steps, p.harvest_item_id, p.harvest_min, p.harvest_max, p.name,
                   p.allowed_garden_type_code,
                   COALESCE(problem_counts.weed_count, 0) AS weed_count
            FROM planted_crops pc
            JOIN plants p ON p.plant_id = pc.plant_id
            LEFT JOIN (
                SELECT planted_crop_id, COUNT(*) AS weed_count
                FROM crop_problems
                WHERE problem_type = 'weed' AND is_resolved = 0
                GROUP BY planted_crop_id
            ) problem_counts ON problem_counts.planted_crop_id = pc.planted_crop_id
            WHERE pc.planted_crop_id = ? AND pc.user_id = ? AND pc.is_harvested = 0
            LIMIT 1
        ");
        $stmt->bind_param('ii', $cropId, $userId);
        $stmt->execute();
        $crop = $stmt->get_result()->fetch_assoc();

        if (!$crop) {
            throw new RuntimeException('Crop not found.');
        }

        if (($crop['allowed_garden_type_code'] ?? '') === 'ornamental') {
            throw new RuntimeException('Ornamental plants are not harvested — the fae will leave offerings near them once they bloom.');
        }

        if ((int) $crop['growth_step_current'] < (int) $crop['growth_steps']) {
            throw new RuntimeException('Not ready yet.');
        }

        $qty = random_int((int) $crop['harvest_min'], (int) $crop['harvest_max']);
        if ((int)($crop['has_weeds'] ?? 0) || (int)($crop['weed_count'] ?? 0) > 0) {
            $qty = max(1, (int)floor($qty * 0.75));
        }
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


    if ($action === 'change_garden_type') {
        $gardenId = (int)($input['garden_id'] ?? 0);
        $gardenTypeId = (int)($input['garden_type_id'] ?? 0);
        if ($gardenId <= 0 || $gardenTypeId <= 0) throw new RuntimeException('Missing garden or garden type.');

        $stmt = $db->prepare("SELECT garden_id, is_type_locked FROM gardens WHERE garden_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $gardenId, $userId);
        $stmt->execute();
        $gardenRow = $stmt->get_result()->fetch_assoc();
        if (!$gardenRow) throw new RuntimeException('Garden not found.');
        if ((int)($gardenRow['is_type_locked'] ?? 0)) throw new RuntimeException('This garden type is fixed and cannot be changed.');

        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM planted_crops WHERE garden_id = ? AND is_harvested = 0");
        $stmt->bind_param('i', $gardenId);
        $stmt->execute();
        if ((int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0) {
            throw new RuntimeException('The garden must be completely empty before changing type.');
        }

        if (tableExists($db, 'player_garden_type_unlocks')) {
            $stmt = $db->prepare("SELECT 1 FROM player_garden_type_unlocks WHERE user_id = ? AND garden_type_id = ? LIMIT 1");
            $stmt->bind_param('ii', $userId, $gardenTypeId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) throw new RuntimeException('That garden type is not unlocked yet.');
        } else {
            $stmt = $db->prepare("SELECT 1 FROM garden_types WHERE garden_type_id = ? AND code = 'farm' LIMIT 1");
            $stmt->bind_param('i', $gardenTypeId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) throw new RuntimeException('That garden type is not unlocked yet.');
        }

        $stmt = $db->prepare("UPDATE gardens SET garden_type_id = ? WHERE garden_id = ? AND user_id = ?");
        $stmt->bind_param('iii', $gardenTypeId, $gardenId, $userId);
        $stmt->execute();
        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Garden type changed.']);
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


    if ($action === 'market_buy_item') {
        $marketStatus = getMarketStatus($db, $userId);
        if (empty($marketStatus['is_open'])) throw new RuntimeException('The Fae Market is not open right now.');
        $marketInventoryId = (int)($input['market_inventory_id'] ?? 0);
        if ($marketInventoryId <= 0) throw new RuntimeException('Missing market item.');

        $phase = $marketStatus['phase'] ?? 'day';
        $stmt = $db->prepare("
            SELECT fmi.*, i.item_id, i.name, i.base_buy_price
            FROM fae_market_inventory fmi
            JOIN items i ON i.item_id = fmi.item_id
            WHERE fmi.fae_market_inventory_id = ?
              AND fmi.is_active = 1
              AND i.is_active = 1
              AND (fmi.market_phase = 'both' OR fmi.market_phase = ?)
            LIMIT 1
        ");
        $stmt->bind_param('is', $marketInventoryId, $phase);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        if (!$item) throw new RuntimeException('That market item is not available right now.');

        $qty = max(1, (int)($item['bundle_quantity'] ?? 1));
        $price = max(0, (int)($item['market_price'] ?? $item['base_buy_price'] ?? 0));
        $stmt = $db->prepare("SELECT coins FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user || (int)$user['coins'] < $price) throw new RuntimeException('Not enough coins.');

        $stmt = $db->prepare("UPDATE users SET coins = coins - ? WHERE user_id = ?");
        $stmt->bind_param('ii', $price, $userId);
        $stmt->execute();
        addInventory($db, $userId, (int)$item['item_id'], $qty);

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Bought ' . $item['name'] . ' ×' . $qty . '.']);
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
            if (in_array(($item['item_type'] ?? ''), ['system','helper_equipment','relic'], true)) throw new RuntimeException('The market is not buying that.');
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

    $stmt = $db->prepare("
        SELECT *
        FROM player_relics
        WHERE relic_id = ?
          AND user_id = ?
          AND collected_at IS NULL
        LIMIT 1
    ");
    $stmt->bind_param('ii', $relicId, $userId);
    $stmt->execute();

    $relic = $stmt->get_result()->fetch_assoc();

    if (!$relic) {
        throw new RuntimeException('Relic not found.');
    }

    $relicKey = (string)($relic['relic_key'] ?? 'first_field_relic');

    $itemCode = $relicKey === 'second_field_relic'
        ? 'relic_second_oddity'
        : 'relic_first_oddity';

    $flagKey = $relicKey === 'second_field_relic'
        ? 'second_relic_collected'
        : 'first_relic_collected';

    $stmt = $db->prepare("
        SELECT item_id, code, name, icon
        FROM items
        WHERE code = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $itemCode);
    $stmt->execute();

    $item = $stmt->get_result()->fetch_assoc();

    if (!$item) {
        throw new RuntimeException('Missing relic item.');
    }

    addInventory($db, $userId, (int)$item['item_id'], 1);

    $stmt = $db->prepare("
        UPDATE player_relics
        SET collected_at = NOW(),
            visual_state = 'collected'
        WHERE relic_id = ?
          AND user_id = ?
    ");
    $stmt->bind_param('ii', $relicId, $userId);
    $stmt->execute();

    grantUnlock($db, $userId, $flagKey, 'relic_pickup');

    if ($relicKey === 'second_field_relic') {
        // They do not unlock instantly. They are queued to arrive with the next caravan.
        grantUnlock($db, $userId, 'bone_brine_pending', 'second_relic_pickup');
    } else {
        scheduleMadamRuneVisit($db, $userId);
    }

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

        $stmt = $db->prepare("SELECT * FROM machine_recipes WHERE recipe_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $recipe = $stmt->get_result()->fetch_assoc();

        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        $stmt = $db->prepare("
            SELECT pm.player_machine_id, pm.quantity,
                   m.queue_size,
                   COUNT(j.job_id) AS active_jobs
            FROM player_machines pm
            JOIN machines m ON m.machine_id = pm.machine_id
            LEFT JOIN processing_jobs j ON j.player_machine_id = pm.player_machine_id AND j.is_collected = 0
            WHERE pm.user_id = ? AND m.machine_type = ?
            GROUP BY pm.player_machine_id, pm.quantity, m.queue_size
            LIMIT 1
        ");
        $stmt->bind_param('is', $userId, $recipe['machine_type']);
        $stmt->execute();
        $machine = $stmt->get_result()->fetch_assoc();

        if (!$machine) {
            throw new RuntimeException('You need the right equipment.');
        }
        $maxJobs = max(1, (int)($machine['quantity'] ?? 1)) * max(1, (int)($machine['queue_size'] ?? 1));
        if ((int)($machine['active_jobs'] ?? 0) >= $maxJobs) {
            throw new RuntimeException('Queue is full (' . $maxJobs . ' slots).');
        }

        $needed = (int) $recipe['input_quantity'] * $quantity;

        if (!removeInventory($db, $userId, (int) $recipe['input_item_id'], $needed)) {
            throw new RuntimeException('Not enough ingredients.');
        }

        $config = getGameConfig($db);
        $dayLength = max(60, (int)($config['day_length_seconds'] ?? 720));
        $cycleCount = max(1, (int)($recipe['cycle_count'] ?? 1));
        $cycleHours = max(1, (int)($recipe['cycle_hours'] ?? 12));
        $secondsPerJob = (int) round($cycleCount * $cycleHours * ($dayLength / 24));
        $seconds = $secondsPerJob * $quantity;
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
            SELECT j.*,
                   r.output_item_id,
                   COALESCE(r.output_min, r.output_quantity, 1) AS output_min,
                   COALESCE(r.output_max, r.output_quantity, 1) AS output_max
            FROM processing_jobs j
            JOIN machine_recipes r ON r.recipe_id = j.recipe_id
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

        $outMin = (int)($job['output_min'] ?? $job['output_quantity'] ?? 1);
        $outMax = (int)($job['output_max'] ?? $job['output_quantity'] ?? 1);
        $qty = random_int(max(1, $outMin), max($outMin, $outMax)) * (int) $job['quantity'];
        addInventory($db, $userId, (int) $job['output_item_id'], $qty);

        $stmt = $db->prepare("UPDATE processing_jobs SET is_collected = 1 WHERE job_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $jobId, $userId);
        $stmt->execute();

        $db->commit();
        jsonResponse(['ok' => true, 'message' => 'Collected.']);
    }


if ($action === 'collect_pouch') {
    $pouchId = (int) ($input['pouch_id'] ?? 0);

    $stmt = $db->prepare("
        SELECT *
        FROM player_pouches
        WHERE pouch_id = ?
          AND user_id = ?
          AND is_claimed = 0
        LIMIT 1
    ");
    $stmt->bind_param('ii', $pouchId, $userId);
    $stmt->execute();

    $pouch = $stmt->get_result()->fetch_assoc();

    if (!$pouch) {
        throw new RuntimeException('Pouch not found.');
    }

    $count = max(1, (int)($pouch['seed_count'] ?? 1));
    $pouchType = (string)($pouch['pouch_type'] ?? 'seed');

    if ($pouchType === 'fae_offering') {
        $faeItem = $db->query("
            SELECT item_id, name
            FROM items
            WHERE code = 'fae_tip'
              AND is_active = 1
            LIMIT 1
        ")->fetch_assoc();

        if (!$faeItem) {
            throw new RuntimeException('Fae offering item not found.');
        }

        addInventory($db, $userId, (int)$faeItem['item_id'], $count);

        $message = 'The fae left ' . ($faeItem['name'] ?? 'Fae Offering') . ' ×' . $count . ' near the blooms.';
    } else {
        $seedResult = $db->query("
            SELECT item_id, name
            FROM items
            WHERE item_type = 'seed'
              AND is_active = 1
              AND base_buy_price > 0
            ORDER BY RAND()
            LIMIT " . $count
        );

        if (!$seedResult) {
            throw new RuntimeException('Could not find seeds for pouch.');
        }

        $seeds = $seedResult->fetch_all(MYSQLI_ASSOC);
        $found = [];

        foreach ($seeds as $seed) {
            addInventory($db, $userId, (int)$seed['item_id'], 1);

            $key = $seed['name'] ?: 'Seed';
            if (!isset($found[$key])) {
                $found[$key] = 0;
            }

            $found[$key]++;
        }

        $parts = [];
        foreach ($found as $name => $qty) {
            $parts[] = $name . ' ×' . $qty;
        }

        $message = $parts
            ? 'You open the pouch and find ' . implode(', ', $parts) . '.'
            : 'You open the pouch, but it is strangely empty.';
    }

    $stmt = $db->prepare("
        DELETE FROM player_pouches
        WHERE pouch_id = ?
          AND user_id = ?
    ");
    $stmt->bind_param('ii', $pouchId, $userId);
    $stmt->execute();

    $stmt = $db->prepare("
        UPDATE player_state
        SET last_pouch_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $db->commit();

    jsonResponse([
        'ok' => true,
        'message' => $message
    ]);
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
        $stmt = $db->prepare("DELETE FROM player_orders WHERE player_order_id=? AND user_id=?");
        $stmt->bind_param('ii', $orderId, $userId); $stmt->execute();
        $db->commit();
        jsonResponse(['ok'=>true,'message'=>'Order cancelled. -'.$penalty.' reputation.', 'reputation_delta'=>-$penalty]);
    }

    if ($action === 'fulfill_order') {
        $orderId = (int)($input['player_order_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM player_orders WHERE player_order_id=? AND user_id=? AND order_status='accepted' AND is_fulfilled=0 LIMIT 1");
        $stmt->bind_param('ii', $orderId, $userId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc();
        if (!$order) throw new RuntimeException('Order is not active.');
        $itemId = (int)($order['item_id'] ?? 0);
        $need = (int)($order['quantity_required'] ?? 1);
        if ($itemId > 0) {
            $stmt = $db->prepare("SELECT quantity FROM player_inventory WHERE user_id=? AND item_id=? LIMIT 1");
            $stmt->bind_param('ii', $userId, $itemId);
            $stmt->execute();
            $inv = $stmt->get_result()->fetch_assoc();
            if (!$inv || (int)$inv['quantity'] < $need) throw new RuntimeException('You do not have everything for this order.');
            removeInventory($db, $userId, $itemId, $need);
        }
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
