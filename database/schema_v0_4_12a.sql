-- Fantasy Farmer v0.4.12a
-- Shed station pivot, extra machine scaffolding, map marker size/glow controls.
-- Run after v0.4.12.

-- ------------------------------------------------------------
-- Version
-- ------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'app_version') = 0,
  'ALTER TABLE game_config ADD COLUMN app_version VARCHAR(20) NOT NULL DEFAULT ''v0.4.12a''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config SET app_version = 'v0.4.12a';

-- ------------------------------------------------------------
-- Map marker presentation controls
-- ------------------------------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'icon_size') = 0,
  'ALTER TABLE map_location_config ADD COLUMN icon_size INT NOT NULL DEFAULT 78',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'glow_color') = 0,
  'ALTER TABLE map_location_config ADD COLUMN glow_color VARCHAR(80) NOT NULL DEFAULT ''rgba(255, 214, 94, .78)''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE map_location_config
SET icon_size = CASE location_key
    WHEN 'garden' THEN 86
    WHEN 'shop' THEN 88
    WHEN 'orders' THEN 82
    WHEN 'helpers' THEN 86
    WHEN 'caravan' THEN 96
    ELSE COALESCE(NULLIF(icon_size, 0), 78)
  END,
  glow_color = COALESCE(NULLIF(glow_color, ''), 'rgba(255, 214, 94, .78)');

-- ------------------------------------------------------------
-- Shed stations: fixed floor stations that open machine modals.
-- Wall-decoration placement scaffolding remains separate.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shed_station_config (
  station_id INT AUTO_INCREMENT PRIMARY KEY,
  station_key VARCHAR(80) NOT NULL UNIQUE,
  machine_type VARCHAR(50) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  station_icon VARCHAR(255) DEFAULT NULL,
  station_x INT NOT NULL DEFAULT 0,
  station_y INT NOT NULL DEFAULT 0,
  station_width INT NOT NULL DEFAULT 96,
  station_height INT NOT NULL DEFAULT 96,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_machine_type (machine_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extra machine definitions. Use INSERT ... SELECT so this is safe without a UNIQUE key on machine_type.
INSERT INTO machines (machine_type, name, icon, base_cost, queue_size)
SELECT 'preserve', 'Preserves Bin', '🍯', 75, 1
WHERE NOT EXISTS (SELECT 1 FROM machines WHERE machine_type = 'preserve' LIMIT 1);

INSERT INTO machines (machine_type, name, icon, base_cost, queue_size)
SELECT 'drying', 'Drying Rack', '🧺', 120, 1
WHERE NOT EXISTS (SELECT 1 FROM machines WHERE machine_type = 'drying' LIMIT 1);

INSERT INTO machines (machine_type, name, icon, base_cost, queue_size)
SELECT 'compost', 'Compost Bin', '♻️', 100, 1
WHERE NOT EXISTS (SELECT 1 FROM machines WHERE machine_type = 'compost' LIMIT 1);

INSERT INTO machines (machine_type, name, icon, base_cost, queue_size)
SELECT 'seed_bin', 'Seed Bin', '🌰', 150, 1
WHERE NOT EXISTS (SELECT 1 FROM machines WHERE machine_type = 'seed_bin' LIMIT 1);

INSERT INTO machines (machine_type, name, icon, base_cost, queue_size)
SELECT 'workbench', 'Workbench', '🛠️', 200, 1
WHERE NOT EXISTS (SELECT 1 FROM machines WHERE machine_type = 'workbench' LIMIT 1);

INSERT INTO shed_station_config (station_key, machine_type, display_name, station_icon, station_x, station_y, station_width, station_height, sort_order, is_active) VALUES
('preserves_bin_station', 'preserve', 'Preserves Bin', '🍯', 350, 610, 94, 94, 10, 1),
('drying_rack_station', 'drying', 'Drying Rack', '🧺', 470, 600, 104, 90, 20, 1),
('compost_bin_station', 'compost', 'Compost Bin', '♻️', 590, 625, 92, 92, 30, 1),
('seed_bin_station', 'seed_bin', 'Seed Bin', '🌰', 250, 625, 86, 86, 40, 1),
('workbench_station', 'workbench', 'Workbench', '🛠️', 145, 575, 108, 92, 50, 1)
ON DUPLICATE KEY UPDATE
  machine_type = VALUES(machine_type),
  display_name = VALUES(display_name),
  station_icon = VALUES(station_icon),
  station_x = VALUES(station_x),
  station_y = VALUES(station_y),
  station_width = VALUES(station_width),
  station_height = VALUES(station_height),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);

-- Functional machine stations replace the old floor-grid machine placement.
-- Keep wall decoration scaffolding, but deactivate floor machine objects created by v0.4.12.
UPDATE player_shed_objects
SET is_active = 0
WHERE object_category = 'machine'
  AND zone_key = 'floor';

-- Buying/owning any machine unlocks the shed.
INSERT IGNORE INTO player_unlocks (user_id, unlock_key, source)
SELECT DISTINCT user_id, 'location_shed', 'machine_purchase_backfill'
FROM player_machines;
