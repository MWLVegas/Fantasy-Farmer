-- Fantasy Farmer v0.4.10
-- Map marker configuration rows, map icon images, locked silhouettes, and day-orb sizing.
-- Run after v0.4.9.

-- ------------------------------------------------------------
-- Version bump.
-- ------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'game_config'
     AND COLUMN_NAME = 'app_version') = 0,
  'ALTER TABLE game_config ADD COLUMN app_version VARCHAR(20) NOT NULL DEFAULT ''v0.4.10''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.10';

-- ------------------------------------------------------------
-- Per-location map marker config.
-- This keeps map coordinates/icons readable as real rows instead of one giant JSON blob.
-- map_x/map_y are the top-left pixel coordinates of the 124x124 clickable marker card.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS map_location_config (
  map_location_config_id INT AUTO_INCREMENT PRIMARY KEY,
  location_key VARCHAR(80) NOT NULL UNIQUE,
  map_x INT NOT NULL DEFAULT 0,
  map_y INT NOT NULL DEFAULT 0,
  map_icon VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO map_location_config (location_key, map_x, map_y, map_icon, sort_order, is_active) VALUES
('orders', 298, 105, 'assets/map/orders.png', 10, 1),
('helpers', 475, 125, 'assets/map/fairy_folk.png', 20, 1),
('shop', 110, 290, 'assets/map/store.png', 30, 1),
('garden', 298, 298, 'assets/map/garden.png', 40, 1),
('shed', 485, 290, 'assets/map/shed.png', 50, 1),
('bone_brine', 90, 510, 'assets/map/bone_brine.png', 60, 1),
('market', 298, 500, 'assets/map/market.png', 70, 1),
('caravan', 470, 510, 'assets/map/caravan_empty.png', 80, 1)
ON DUPLICATE KEY UPDATE
  map_x = VALUES(map_x),
  map_y = VALUES(map_y),
  map_icon = VALUES(map_icon),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);
