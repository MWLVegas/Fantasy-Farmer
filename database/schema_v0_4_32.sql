-- Fantasy Farmer v0.4.32
-- Caravan active/inactive visuals, overhead map day/night backgrounds, and configurable locked plot icon.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'map_day_background_image') = 0,
  'ALTER TABLE game_config ADD COLUMN map_day_background_image VARCHAR(255) NULL AFTER map_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'map_night_background_image') = 0,
  'ALTER TABLE game_config ADD COLUMN map_night_background_image VARCHAR(255) NULL AFTER map_day_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'locked_plot_icon') = 0,
  'ALTER TABLE game_config ADD COLUMN locked_plot_icon VARCHAR(255) NOT NULL DEFAULT ''🔒'' AFTER fae_market_wanderer_hue_shift_enabled',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'active_map_icon') = 0,
  'ALTER TABLE map_location_config ADD COLUMN active_map_icon VARCHAR(255) NULL AFTER map_icon',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'inactive_map_icon') = 0,
  'ALTER TABLE map_location_config ADD COLUMN inactive_map_icon VARCHAR(255) NULL AFTER active_map_icon',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'active_day_background_image') = 0,
  'ALTER TABLE map_location_config ADD COLUMN active_day_background_image VARCHAR(255) NULL AFTER night_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'active_night_background_image') = 0,
  'ALTER TABLE map_location_config ADD COLUMN active_night_background_image VARCHAR(255) NULL AFTER active_day_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'inactive_day_background_image') = 0,
  'ALTER TABLE map_location_config ADD COLUMN inactive_day_background_image VARCHAR(255) NULL AFTER active_night_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'inactive_night_background_image') = 0,
  'ALTER TABLE map_location_config ADD COLUMN inactive_night_background_image VARCHAR(255) NULL AFTER inactive_day_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.32',
    map_background_image = COALESCE(NULLIF(map_background_image, ''), 'assets/map/background.png'),
    map_day_background_image = COALESCE(NULLIF(map_day_background_image, ''), 'assets/map/map_day.png'),
    map_night_background_image = COALESCE(NULLIF(map_night_background_image, ''), 'assets/map/map_night.png'),
    locked_plot_icon = COALESCE(NULLIF(locked_plot_icon, ''), '🔒');

UPDATE map_location_config
SET active_map_icon = COALESCE(NULLIF(active_map_icon, ''), 'assets/map/caravan_full.png'),
    inactive_map_icon = COALESCE(NULLIF(inactive_map_icon, ''), 'assets/map/caravan_empty.png'),
    map_icon = COALESCE(NULLIF(map_icon, ''), 'assets/map/caravan_empty.png'),
    day_background_image = COALESCE(NULLIF(day_background_image, ''), 'assets/map/caravan_empty_day.png'),
    night_background_image = COALESCE(NULLIF(night_background_image, ''), 'assets/map/caravan_empty_night.png'),
    active_day_background_image = COALESCE(NULLIF(active_day_background_image, ''), 'assets/map/caravan_full_day.png'),
    active_night_background_image = COALESCE(NULLIF(active_night_background_image, ''), 'assets/map/caravan_full_night.png'),
    inactive_day_background_image = COALESCE(NULLIF(inactive_day_background_image, ''), 'assets/map/caravan_empty_day.png'),
    inactive_night_background_image = COALESCE(NULLIF(inactive_night_background_image, ''), 'assets/map/caravan_empty_night.png')
WHERE location_key IN ('caravan', 'caravan_camp');
