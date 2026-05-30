-- Fantasy Farmer v0.4.16l
-- Crop stage 0 planted-soil visuals, Fae Market weekend timing fix, pests/weeds scaffolding, and market selling/buying polish.

UPDATE game_config
SET app_version = 'v0.4.16l';

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active) VALUES
('garden_planted_soil', 'Freshly Planted Soil', 'system', 0, 0, 'assets/icons/garden-planted-soil.png', 1),
('weed', 'Weed', 'material', 0, 1, '🌿', 1),
('farm_bug', 'Farm Bug', 'material', 0, 1, '🐛', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  item_type = VALUES(item_type),
  base_sell_price = VALUES(base_sell_price),
  icon = VALUES(icon),
  is_active = VALUES(is_active);

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_types' AND COLUMN_NAME = 'weed_icons_json') = 0,
  'ALTER TABLE garden_types ADD COLUMN weed_icons_json JSON DEFAULT NULL AFTER background_image',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_types' AND COLUMN_NAME = 'pest_icons_json') = 0,
  'ALTER TABLE garden_types ADD COLUMN pest_icons_json JSON DEFAULT NULL AFTER weed_icons_json',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE garden_types
SET weed_icons_json = JSON_ARRAY('🌿','☘️','🍃'),
    pest_icons_json = JSON_ARRAY('🐛','🐞','🪲')
WHERE code = 'farm';

UPDATE garden_types
SET weed_icons_json = COALESCE(weed_icons_json, JSON_ARRAY('🌿')),
    pest_icons_json = COALESCE(pest_icons_json, JSON_ARRAY('🐛'));

CREATE TABLE IF NOT EXISTS crop_problems (
  crop_problem_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  garden_id INT NOT NULL,
  planted_crop_id INT NOT NULL,
  problem_type ENUM('weed','pest') NOT NULL,
  problem_code VARCHAR(80) NOT NULL DEFAULT 'generic',
  name VARCHAR(100) NOT NULL,
  icon VARCHAR(255) DEFAULT NULL,
  reward_item_code VARCHAR(80) NOT NULL,
  is_resolved TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME DEFAULT NULL,
  INDEX idx_crop_active (planted_crop_id, is_resolved),
  INDEX idx_user_garden_active (user_id, garden_id, is_resolved),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_id) REFERENCES gardens(garden_id) ON DELETE CASCADE,
  FOREIGN KEY (planted_crop_id) REFERENCES planted_crops(planted_crop_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fae_market_inventory' AND COLUMN_NAME = 'bundle_quantity') = 0,
  'ALTER TABLE fae_market_inventory ADD COLUMN bundle_quantity INT NOT NULL DEFAULT 1 AFTER item_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fae_market_inventory' AND COLUMN_NAME = 'market_price') = 0,
  'ALTER TABLE fae_market_inventory ADD COLUMN market_price INT DEFAULT NULL AFTER bundle_quantity',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO fae_market_inventory (item_id, bundle_quantity, market_price, market_phase, stock_mode, daily_limit, is_active, sort_order)
SELECT item_id, 2, GREATEST(1, ROUND(base_buy_price * 1.8)), 'both', 'infinite', NULL, 1,
       CASE code WHEN 'carrot_seed' THEN 10 WHEN 'strawberry_seed' THEN 20 WHEN 'blueberry_seed' THEN 30 ELSE 100 END
FROM items
WHERE code IN ('carrot_seed','strawberry_seed','blueberry_seed')
ON DUPLICATE KEY UPDATE
  bundle_quantity = VALUES(bundle_quantity),
  market_price = VALUES(market_price),
  stock_mode = VALUES(stock_mode),
  daily_limit = VALUES(daily_limit),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);

UPDATE plants
SET stage_icons_json = CASE code
  WHEN 'carrot' THEN '["assets/icons/crops/carrot_1.png","assets/icons/crops/carrot_2.png"]'
  WHEN 'strawberry' THEN '["assets/icons/crops/strawberry_1.png","assets/icons/crops/strawberry_2.png","assets/icons/crops/strawberry_3.png"]'
  WHEN 'blueberry_bush' THEN '["assets/icons/crops/blueberry_1.png","assets/icons/crops/blueberry_2.png","assets/icons/crops/blueberry_3.png","assets/icons/crops/blueberry_4.png"]'
  ELSE stage_icons_json
END
WHERE code IN ('carrot','strawberry','blueberry_bush');
