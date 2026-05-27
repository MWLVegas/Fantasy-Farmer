-- Fantasy Farmer v0.4.0
-- World/location, reputation/recognition, multi-order board, relic, caravan, and forest folk scaffolding.
-- This migration is safe to run over v0.3.15. A fresh rebuild may use schema.sql directly.

-- MySQL does not support ALTER TABLE ... ADD COLUMN IF NOT EXISTS.
-- These guarded statements keep the migration safe to re-run without requiring stored procedure privileges.
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_state' AND COLUMN_NAME = 'next_order_at'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE player_state ADD COLUMN next_order_at DATETIME DEFAULT NULL AFTER next_shop_refresh_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE player_state
SET next_order_at = DATE_ADD(NOW(), INTERVAL FLOOR(3+RAND()*3) MINUTE)
WHERE next_order_at IS NULL;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_state' AND COLUMN_NAME = 'reputation'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE player_state ADD COLUMN reputation INT NOT NULL DEFAULT 0 AFTER next_shop_refresh_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_state' AND COLUMN_NAME = 'recognition'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE player_state ADD COLUMN recognition INT NOT NULL DEFAULT 0 AFTER reputation',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE items
  MODIFY item_type ENUM('seed','produce','processed','fuel','material','fertilizer','system','helper_equipment','relic') NOT NULL;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'reputation_reward'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE player_orders ADD COLUMN reputation_reward INT NOT NULL DEFAULT 1 AFTER payment_coins',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'recognition_reward'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE player_orders ADD COLUMN recognition_reward INT NOT NULL DEFAULT 0 AFTER reputation_reward',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'order_type'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE player_orders ADD COLUMN order_type ENUM(''rush'',''standard'',''patient'') NOT NULL DEFAULT ''standard'' AFTER recognition_reward',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS player_unlocks (
  unlock_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  unlock_key VARCHAR(100) NOT NULL,
  source VARCHAR(100) DEFAULT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_unlock (user_id, unlock_key),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_relics (
  relic_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  relic_key VARCHAR(100) NOT NULL,
  display_name VARCHAR(180) NOT NULL,
  relic_type VARCHAR(60) NOT NULL DEFAULT 'oddity',
  source_action VARCHAR(60) DEFAULT NULL,
  discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  traded_at DATETIME DEFAULT NULL,
  traded_to VARCHAR(100) DEFAULT NULL,
  INDEX idx_user_relics (user_id, discovered_at),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS helper_types (
  helper_type_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  species_key VARCHAR(80) NOT NULL,
  name VARCHAR(100) NOT NULL,
  icon VARCHAR(255) NOT NULL DEFAULT '🧚',
  description TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS helper_equipment (
  helper_equipment_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  task_type VARCHAR(80) NOT NULL,
  icon VARCHAR(255) NOT NULL DEFAULT '✨',
  description TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_helpers (
  player_helper_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  helper_type_id INT NOT NULL,
  helper_name VARCHAR(100) DEFAULT NULL,
  equipped_helper_equipment_id INT DEFAULT NULL,
  active_task VARCHAR(80) DEFAULT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  summoned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (helper_type_id) REFERENCES helper_types(helper_type_id),
  FOREIGN KEY (equipped_helper_equipment_id) REFERENCES helper_equipment(helper_equipment_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fertilizer_definitions (
  fertilizer_definition_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL UNIQUE,
  effect_type VARCHAR(80) NOT NULL,
  effect_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  visual_icon VARCHAR(255) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crop_fertilizers (
  crop_fertilizer_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  garden_id INT NOT NULL,
  planted_crop_id INT NOT NULL,
  item_id INT NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumed_at DATETIME DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_id) REFERENCES gardens(garden_id) ON DELETE CASCADE,
  FOREIGN KEY (planted_crop_id) REFERENCES planted_crops(planted_crop_id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active) VALUES
('garden_planted_soil', 'Freshly Planted Soil', 'system', 0, 0, 'assets/icons/garden-planted-soil.png', 1),
('fairy_bell', 'Fairy Bell', 'material', 0, 0, '🔔', 1),
('aqua_amulet', 'Aqua Amulet', 'helper_equipment', 0, 0, '💧', 1),
('speed_fertilizer', 'Speed Fertilizer', 'fertilizer', 35, 0, '⚡', 1),
('hearty_fertilizer', 'Hearty Fertilizer', 'fertilizer', 40, 0, '🌿', 1),
('weedward_fertilizer', 'Weedward Fertilizer', 'fertilizer', 35, 0, '🛡️', 1),
('bugbane_fertilizer', 'Bugbane Fertilizer', 'fertilizer', 35, 0, '🐞', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), item_type=VALUES(item_type), icon=VALUES(icon), is_active=VALUES(is_active);

INSERT INTO tools (code, tool_type, name, level, icon, strength, radius, action_speed_modifier, action_message, complete_message, upgrade_cost, is_active) VALUES
('wooden_hoe', 'hoe', 'Wooden Hoe', 2, 'assets/icons/tool-hoe-wood.png', 50, 0, 1.000, 'The wooden hoe cuts cleanly through the soil.', 'The plot is fully tilled.', 75, 1),
('iron_hoe', 'hoe', 'Iron Hoe', 3, 'assets/icons/tool-hoe-iron.png', 100, 0, 0.900, 'The iron hoe means business.', 'The plot is fully tilled.', 250, 1),
('wooden_watering_can', 'watering_can', 'Wooden Can', 2, 'assets/icons/tool-can-wood.png', 30, 0, 1.000, 'A proper splash of water.', 'The crop is fully watered.', 75, 1),
('iron_watering_can', 'watering_can', 'Iron Can', 3, 'assets/icons/tool-can-iron.png', 60, 0, 0.900, 'The iron can rains with authority.', 'The crop is fully watered.', 250, 1),
('wooden_shovel', 'shovel', 'Wooden Shovel', 2, 'assets/icons/tool-shovel-wood.png', 2, 0, 1.000, 'The wooden shovel works the roots loose.', 'The crop has been dug up.', 75, 1),
('iron_shovel', 'shovel', 'Iron Shovel', 3, 'assets/icons/tool-shovel-iron.png', 4, 0, 0.900, 'The iron shovel ends negotiations.', 'The crop has been dug up.', 250, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), icon=VALUES(icon), strength=VALUES(strength), upgrade_cost=VALUES(upgrade_cost), is_active=VALUES(is_active);

INSERT INTO helper_types (code, species_key, name, icon, description, sort_order, is_active) VALUES
('fairy', 'fairy', 'Fairy', '🧚', 'Curious forest folk who learn human gardening through equipped magic.', 10, 1),
('brownie', 'brownie', 'Brownie', '🍞', 'Future sturdy house-and-garden helper scaffold.', 20, 1),
('mushling', 'mushling', 'Mushling', '🍄', 'Future soil, fertilizer, and compost specialist scaffold.', 30, 1),
('spriggan', 'spriggan', 'Spriggan', '🌿', 'Future weeds and pests specialist scaffold.', 40, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), icon=VALUES(icon), description=VALUES(description), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO helper_equipment (code, name, task_type, icon, description, sort_order, is_active) VALUES
('aqua_amulet', 'Aqua Amulet', 'water', '💧', 'Gives a helper water magic for crop watering.', 10, 1),
('root_charm', 'Root Charm', 'till', '🌱', 'Future tilling and soil preparation charm.', 20, 1),
('harvest_charm', 'Harvest Charm', 'harvest', '🧺', 'Future harvesting charm.', 30, 1),
('weedward_charm', 'Weedward Charm', 'weed', '🛡️', 'Future weed-control charm.', 40, 1),
('bugbane_charm', 'Bugbane Charm', 'pest', '🐞', 'Future pest-control charm.', 50, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), task_type=VALUES(task_type), icon=VALUES(icon), description=VALUES(description), sort_order=VALUES(sort_order), is_active=VALUES(is_active);

INSERT INTO fertilizer_definitions (item_id, effect_type, effect_value, visual_icon, description, is_active) VALUES
((SELECT item_id FROM items WHERE code='speed_fertilizer'), 'speed_days', 1, '⚡', 'Future effect: shorten eligible crop cycles.', 1),
((SELECT item_id FROM items WHERE code='hearty_fertilizer'), 'yield_bonus', 1, '🌿', 'Future effect: increase harvest yield.', 1),
((SELECT item_id FROM items WHERE code='weedward_fertilizer'), 'prevent_weeds', 1, '🛡️', 'Future effect: prevent weeds.', 1),
((SELECT item_id FROM items WHERE code='bugbane_fertilizer'), 'prevent_pests', 1, '🐞', 'Future effect: prevent pests.', 1)
ON DUPLICATE KEY UPDATE effect_type=VALUES(effect_type), effect_value=VALUES(effect_value), visual_icon=VALUES(visual_icon), description=VALUES(description), is_active=VALUES(is_active);
