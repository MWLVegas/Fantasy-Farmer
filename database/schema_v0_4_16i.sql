-- v0.4.16i
-- Helper visibility/motion polish, Fae Market crop sales, market backgrounds, garden-type scaffolding, and seed garden restrictions.

UPDATE game_config SET app_version = 'v0.4.16i';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME = 'work_sprite') = 0,
  'ALTER TABLE items ADD COLUMN work_sprite VARCHAR(255) DEFAULT NULL AFTER icon',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'helper_equipment' AND COLUMN_NAME = 'work_sprite') = 0,
  'ALTER TABLE helper_equipment ADD COLUMN work_sprite VARCHAR(255) DEFAULT NULL AFTER icon',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_types' AND COLUMN_NAME = 'background_image') = 0,
  'ALTER TABLE garden_types ADD COLUMN background_image VARCHAR(255) DEFAULT NULL AFTER icon',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plants' AND COLUMN_NAME = 'allowed_garden_types_json') = 0,
  'ALTER TABLE plants ADD COLUMN allowed_garden_types_json JSON DEFAULT NULL AFTER allowed_garden_type_code',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS player_garden_type_unlocks (
  user_id INT NOT NULL,
  garden_type_id INT NOT NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'system',
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, garden_type_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_type_id) REFERENCES garden_types(garden_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO garden_types (code, name, description, icon, background_image, is_active) VALUES
('farm', 'Farm Garden', 'Good soil for humble beginnings.', '🌱', 'assets/map/garden.png', 1),
('water', 'Water Garden', 'Flooded beds for water-loving crops.', '💧', NULL, 1),
('bog', 'Bog Garden', 'Soft, strange ground for marshy crops.', '🪷', NULL, 1),
('forest', 'Forest Garden', 'Leafy shade and old-root soil.', '🌲', NULL, 1),
('cloud', 'Cloud Garden', 'High, misty beds for sky-touched crops.', '☁️', NULL, 1),
('embers', 'Ember Garden', 'Warm ash soil for fire-kissed crops.', '🔥', NULL, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  icon = VALUES(icon),
  background_image = COALESCE(VALUES(background_image), background_image),
  is_active = VALUES(is_active);

INSERT IGNORE INTO player_garden_type_unlocks (user_id, garden_type_id, source)
SELECT u.user_id, gt.garden_type_id, 'starter'
FROM users u
JOIN garden_types gt ON gt.code = 'farm';

UPDATE helper_equipment SET work_sprite = '💦' WHERE code = 'aqua_amulet';
UPDATE helper_equipment SET work_sprite = '🌱' WHERE code = 'root_charm';
UPDATE helper_equipment SET work_sprite = '🌰' WHERE code = 'seed_satchel';
UPDATE helper_equipment SET work_sprite = '✨' WHERE code = 'harvest_basket';
UPDATE helper_equipment SET work_sprite = '👝' WHERE code = 'market_pouch';
UPDATE helper_equipment SET work_sprite = '📮' WHERE code = 'order_stamp';

UPDATE items SET work_sprite = '💦' WHERE code = 'aqua_amulet';
UPDATE items SET work_sprite = '🌱' WHERE code = 'root_charm';
UPDATE items SET work_sprite = '🌰' WHERE code = 'seed_satchel';
UPDATE items SET work_sprite = '✨' WHERE code = 'harvest_basket';
UPDATE items SET work_sprite = '👝' WHERE code = 'market_pouch';
UPDATE items SET work_sprite = '📮' WHERE code = 'order_stamp';

UPDATE plants p
JOIN garden_types gt ON gt.code = p.allowed_garden_type_code
SET p.allowed_garden_types_json = JSON_ARRAY(gt.garden_type_id)
WHERE p.allowed_garden_types_json IS NULL;

UPDATE map_location_config
SET day_background_image = COALESCE(day_background_image, map_icon),
    night_background_image = COALESCE(night_background_image, map_icon)
WHERE location_key IN ('shop','shed','garden','caravan');

UPDATE map_location_config
SET day_background_image = COALESCE(day_background_image, 'assets/map/market.png'),
    night_background_image = COALESCE(night_background_image, 'assets/map/market.png')
WHERE location_key = 'market';

INSERT INTO fae_market_inventory (item_id, market_phase, stock_mode, daily_limit, is_active, sort_order)
SELECT item_id, 'both', 'infinite', NULL, 1, 100
FROM items
WHERE item_type = 'produce'
ON DUPLICATE KEY UPDATE
  stock_mode = VALUES(stock_mode),
  daily_limit = VALUES(daily_limit),
  is_active = VALUES(is_active);
