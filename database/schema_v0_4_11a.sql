-- Fantasy Farmer v0.4.11a
-- Move the map title into game_config.
-- Run after v0.4.11.

SET @sql := IF(
  (SELECT COUNT(*)
   FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'game_config'
     AND COLUMN_NAME = 'map_title') = 0,
  'ALTER TABLE game_config ADD COLUMN map_title VARCHAR(100) NOT NULL DEFAULT ''Town''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE game_config
SET map_title = COALESCE(NULLIF(map_title, ''), 'Town'),
    app_version = 'v0.4.11a';
