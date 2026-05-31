-- Fantasy Farmer v0.4.37
-- Changed files only patch schema.
-- - Store crop stage count as plants.max_cycles, derive crop stage images from code/max_cycles.
-- - Purge expired/cancelled orders permanently and add hot-path indexes.
-- - Add configurable locked plot opacity.
-- - Tomato has THREE cycles.

START TRANSACTION;

-- Add max_cycles before removing the old growth_steps/stage_icons_json fields.
SET @has_max_cycles := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plants' AND COLUMN_NAME = 'max_cycles'
);
SET @sql := IF(@has_max_cycles = 0,
  'ALTER TABLE plants ADD COLUMN max_cycles INT NOT NULL DEFAULT 1 AFTER height',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_growth_steps := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plants' AND COLUMN_NAME = 'growth_steps'
);
SET @sql := IF(@has_growth_steps = 1,
  'UPDATE plants SET max_cycles = growth_steps WHERE max_cycles IS NULL OR max_cycles = 1',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE plants SET max_cycles = 3 WHERE code = 'tomato';

SET @sql := IF(@has_growth_steps = 1,
  'ALTER TABLE plants DROP COLUMN growth_steps',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_stage_icons := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plants' AND COLUMN_NAME = 'stage_icons_json'
);
SET @sql := IF(@has_stage_icons = 1,
  'ALTER TABLE plants DROP COLUMN stage_icons_json',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Locked plot image opacity is now database-configurable.
SET @has_locked_opacity := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'locked_plot_opacity'
);
SET @sql := IF(@has_locked_opacity = 0,
  'ALTER TABLE game_config ADD COLUMN locked_plot_opacity DECIMAL(4,2) NOT NULL DEFAULT 0.58 AFTER locked_plot_icon',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE game_config SET locked_plot_opacity = 0.58 WHERE locked_plot_opacity IS NULL OR locked_plot_opacity < 0.15;

-- Expired/cancelled orders are disposable board state, not history.
DELETE FROM player_orders
WHERE order_status IN ('expired','cancelled') OR is_expired = 1;

-- Add performance indexes if missing.
SET @has_idx_user_status := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND INDEX_NAME = 'idx_user_status'
);
SET @sql := IF(@has_idx_user_status = 0,
  'ALTER TABLE player_orders ADD INDEX idx_user_status (user_id, order_status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_player_order := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND INDEX_NAME = 'idx_player_order'
);
SET @sql := IF(@has_idx_player_order = 0,
  'ALTER TABLE order_items ADD INDEX idx_player_order (player_order_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_garden_active := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'planted_crops' AND INDEX_NAME = 'idx_garden_active'
);
SET @sql := IF(@has_idx_garden_active = 0,
  'ALTER TABLE planted_crops ADD INDEX idx_garden_active (garden_id, is_harvested)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config SET app_version = 'v0.4.37';

COMMIT;
