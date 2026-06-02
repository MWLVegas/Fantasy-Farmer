-- Fantasy Farmer v0.4.42 — Ornamental Garden Pot System
-- Replaces plot-based ornamentals with a pot-based system.
-- Run after garden_tabs_html_v0_4_41.sql.

-- -------------------------------------------------------
-- 1. Add ornamental_pot to items item_type enum
-- -------------------------------------------------------
ALTER TABLE `items`
  MODIFY COLUMN `item_type`
    enum('seed','produce','processed','fuel','material','fertilizer','system','helper_equipment','relic','ornamental_pot')
    NOT NULL;

-- -------------------------------------------------------
-- 2. Pot type definitions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ornamental_pot_types` (
  `pot_type_id`    int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code`           varchar(40)  NOT NULL,
  `name`           varchar(100) NOT NULL,
  `item_id`        int NOT NULL,
  `pot_size`       enum('small','medium','large') NOT NULL DEFAULT 'small',
  `back_image`     varchar(255) DEFAULT NULL,
  `front_image`    varchar(255) DEFAULT NULL,
  `plant_offset_y` int NOT NULL DEFAULT 0,
  `sort_order`     int NOT NULL DEFAULT 0,
  `is_active`      tinyint(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -------------------------------------------------------
-- 3. Player pot placements
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `player_pot_placements` (
  `placement_id`    int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id`         int NOT NULL,
  `garden_id`       int NOT NULL,
  `pot_type_id`     int NOT NULL,
  `grid_x`          int NOT NULL DEFAULT 1,
  `grid_y`          int NOT NULL DEFAULT 1,
  `planted_crop_id` int DEFAULT NULL,
  `last_offering_at` datetime DEFAULT NULL,
  `placed_at`       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_garden` (`user_id`, `garden_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -------------------------------------------------------
-- 4. Pot items
-- -------------------------------------------------------
INSERT IGNORE INTO `items`
  (`item_id`, `code`, `name`, `item_type`,
   `base_buy_price`, `base_sell_price`,
   `icon`, `shop_row_icon`, `work_sprite`, `is_active`)
VALUES
  (97, 'small_pot',  'Small Pot',  'ornamental_pot', 40, 0, 'assets/icons/item-pot-small.png',  'assets/icons/item-pot-small.png',  NULL, 1),
  (98, 'medium_pot', 'Medium Pot', 'ornamental_pot', 80, 0, 'assets/icons/item-pot-medium.png', 'assets/icons/item-pot-medium.png', NULL, 1),
  (99, 'large_pot',  'Large Pot',  'ornamental_pot', 140, 0, 'assets/icons/item-pot-large.png', 'assets/icons/item-pot-large.png',  NULL, 1);

-- -------------------------------------------------------
-- 5. Pot type rows
-- -------------------------------------------------------
INSERT IGNORE INTO `ornamental_pot_types`
  (`pot_type_id`, `code`, `name`, `item_id`, `pot_size`,
   `back_image`, `front_image`, `plant_offset_y`, `sort_order`)
VALUES
  (1, 'small_pot',  'Small Pot',  97, 'small',
   'assets/gardens/pot-small-back.png',  'assets/gardens/pot-small-front.png',  -20, 10),
  (2, 'medium_pot', 'Medium Pot', 98, 'medium',
   'assets/gardens/pot-medium-back.png', 'assets/gardens/pot-medium-front.png', -28, 20),
  (3, 'large_pot',  'Large Pot',  99, 'large',
   'assets/gardens/pot-large-back.png',  'assets/gardens/pot-large-front.png',  -36, 30);

-- -------------------------------------------------------
-- 6. Add ornamental_pot_size to plants
-- -------------------------------------------------------
ALTER TABLE `plants`
  ADD COLUMN `ornamental_pot_size` enum('small','medium','large','any') NOT NULL DEFAULT 'small'
  AFTER `allowed_garden_types_json`;

-- Ornamental plants get specific sizes; farm plants get 'any' (irrelevant for them)
UPDATE `plants` SET `ornamental_pot_size` = 'small' WHERE `code` = 'sunflower';
UPDATE `plants` SET `ornamental_pot_size` = 'any'
  WHERE `allowed_garden_type_code` != 'ornamental';

-- -------------------------------------------------------
-- 7. Sell pots in the general store
-- -------------------------------------------------------
INSERT IGNORE INTO `shop_buy_limits`
  (`shop_buy_limit_id`, `item_id`, `daily_limit`, `is_basic`, `is_active`)
VALUES
  (5, 97, 3, 0, 1),
  (6, 98, 2, 0, 1),
  (7, 99, 1, 0, 1);

-- -------------------------------------------------------
-- 8. Give existing ornamental-unlocked players starter pots + seeds
-- -------------------------------------------------------

-- 3 small pots for everyone with the ornamental unlock
INSERT INTO `player_inventory` (`user_id`, `item_id`, `quantity`)
SELECT pu.user_id, 97, 3
FROM player_unlocks pu
WHERE pu.unlock_key = 'ornamental_garden_unlocked'
ON DUPLICATE KEY UPDATE quantity = quantity + 3;

-- 3 sunflower bulbs only for players who currently have none
INSERT INTO `player_inventory` (`user_id`, `item_id`, `quantity`)
SELECT pu.user_id, 91, 3
FROM player_unlocks pu
WHERE pu.unlock_key = 'ornamental_garden_unlocked'
  AND NOT EXISTS (
    SELECT 1 FROM player_inventory pi
    WHERE pi.user_id = pu.user_id
      AND pi.item_id = 91
      AND pi.quantity > 0
  )
ON DUPLICATE KEY UPDATE quantity = quantity + 3;

-- -------------------------------------------------------
-- 9. Update Granny Briar event to include starter pots for new players
-- -------------------------------------------------------
UPDATE `event_steps`
SET `effects_json` = '{"inventory": [{"code": "sunflower_bulb", "qty": 3}, {"code": "small_pot", "qty": 3}], "flags": {"granny_briar_met": true, "ornamental_garden_unlocked": true}, "create_garden": {"garden_type_code": "ornamental", "name": "Ornamental Garden", "locked": true, "unlock_plots": 4}}'
WHERE `step_id` = 15;

-- -------------------------------------------------------
-- Done.
-- -------------------------------------------------------
UPDATE `game_config` SET `app_version` = 'v0.4.42' WHERE `config_id` = 1;
