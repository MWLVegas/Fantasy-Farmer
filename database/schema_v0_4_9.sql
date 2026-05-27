-- Fantasy Farmer v0.4.9
-- Icon rendering fixes, HUD coin pill restoration, map background scaffolding.
-- Run after v0.4.8.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'game_config'
     AND COLUMN_NAME = 'app_version') = 0,
  'ALTER TABLE game_config ADD COLUMN app_version VARCHAR(20) NOT NULL DEFAULT ''v0.4.9''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'game_config'
     AND COLUMN_NAME = 'map_background_image') = 0,
  'ALTER TABLE game_config ADD COLUMN map_background_image VARCHAR(255) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'game_config'
     AND COLUMN_NAME = 'map_button_positions_json') = 0,
  'ALTER TABLE game_config ADD COLUMN map_button_positions_json TEXT DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.9',
    map_button_positions_json = COALESCE(NULLIF(map_button_positions_json, ''), '{"orders":[298,105],"helpers":[475,125],"shop":[110,290],"garden":[298,298],"shed":[485,290],"bone_brine":[90,510],"market":[298,500],"caravan":[470,510]}');
