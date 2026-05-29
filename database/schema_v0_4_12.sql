-- Fantasy Farmer v0.4.12
-- Shed unlock and grid placement scaffold.
-- Run after v0.4.11a.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'app_version') = 0,
  'ALTER TABLE game_config ADD COLUMN app_version VARCHAR(20) NOT NULL DEFAULT ''v0.4.12''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'shed_background_image') = 0,
  'ALTER TABLE game_config ADD COLUMN shed_background_image VARCHAR(255) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.12';

CREATE TABLE IF NOT EXISTS shed_zones (
  shed_zone_id INT AUTO_INCREMENT PRIMARY KEY,
  zone_key VARCHAR(40) NOT NULL UNIQUE,
  display_name VARCHAR(80) NOT NULL,
  origin_x INT NOT NULL DEFAULT 0,
  origin_y INT NOT NULL DEFAULT 0,
  grid_cols INT NOT NULL DEFAULT 1,
  grid_rows INT NOT NULL DEFAULT 1,
  cell_width INT NOT NULL DEFAULT 48,
  cell_height INT NOT NULL DEFAULT 48,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS placeable_defs (
  placeable_id INT AUTO_INCREMENT PRIMARY KEY,
  placeable_key VARCHAR(80) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  category ENUM('machine','tool','decoration') NOT NULL DEFAULT 'decoration',
  zone_key VARCHAR(40) NOT NULL,
  grid_w INT NOT NULL DEFAULT 1,
  grid_h INT NOT NULL DEFAULT 1,
  icon_path VARCHAR(255) DEFAULT NULL,
  can_rotate TINYINT(1) NOT NULL DEFAULT 0,
  is_functional TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_zone_key (zone_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_shed_objects (
  shed_object_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  placeable_id INT NOT NULL,
  object_category ENUM('machine','tool','decoration') NOT NULL DEFAULT 'decoration',
  player_machine_id INT DEFAULT NULL,
  zone_key VARCHAR(40) NOT NULL,
  grid_x INT NOT NULL DEFAULT 0,
  grid_y INT NOT NULL DEFAULT 0,
  rotation INT NOT NULL DEFAULT 0,
  z_index INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_zone (user_id, zone_key, is_active),
  INDEX idx_player_machine (player_machine_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (placeable_id) REFERENCES placeable_defs(placeable_id),
  FOREIGN KEY (player_machine_id) REFERENCES player_machines(player_machine_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO shed_zones (zone_key, display_name, origin_x, origin_y, grid_cols, grid_rows, cell_width, cell_height, sort_order, is_active) VALUES
('wall', 'Wall', 96, 92, 13, 5, 40, 40, 10, 1),
('floor', 'Floor', 112, 326, 9, 4, 64, 64, 20, 1)
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  origin_x = VALUES(origin_x),
  origin_y = VALUES(origin_y),
  grid_cols = VALUES(grid_cols),
  grid_rows = VALUES(grid_rows),
  cell_width = VALUES(cell_width),
  cell_height = VALUES(cell_height),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);

INSERT INTO placeable_defs (placeable_key, display_name, category, zone_key, grid_w, grid_h, icon_path, can_rotate, is_functional, sort_order, is_active) VALUES
('preserve_bin', 'Preserves Bin', 'machine', 'floor', 2, 2, '🍯', 0, 1, 10, 1)
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  category = VALUES(category),
  zone_key = VALUES(zone_key),
  grid_w = VALUES(grid_w),
  grid_h = VALUES(grid_h),
  icon_path = VALUES(icon_path),
  can_rotate = VALUES(can_rotate),
  is_functional = VALUES(is_functional),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);

-- Buying the first preserves bin now reveals the Shed. Backfill for existing owners.
INSERT IGNORE INTO player_unlocks (user_id, unlock_key, source)
SELECT DISTINCT pm.user_id, 'location_shed', 'machine_purchase_backfill'
FROM player_machines pm
JOIN machines m ON m.machine_id = pm.machine_id
WHERE m.machine_type = 'preserve';

-- Backfill a placed shed object for existing preserves bins that do not have one yet.
INSERT INTO player_shed_objects (user_id, placeable_id, object_category, player_machine_id, zone_key, grid_x, grid_y, rotation, z_index, is_active)
SELECT pm.user_id, pd.placeable_id, 'machine', pm.player_machine_id, 'floor', 1, 1, 0, 10, 1
FROM player_machines pm
JOIN machines m ON m.machine_id = pm.machine_id
JOIN placeable_defs pd ON pd.placeable_key = 'preserve_bin'
LEFT JOIN player_shed_objects existing ON existing.player_machine_id = pm.player_machine_id AND existing.is_active = 1
WHERE m.machine_type = 'preserve'
  AND existing.shed_object_id IS NULL;
