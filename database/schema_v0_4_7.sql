-- Fantasy Farmer v0.4.7
-- Order reward tooltip/display cleanup and database-backed version bump.
-- Run after v0.4.6.

SET @sql := IF(
  (SELECT COUNT(*)
   FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'game_config'
     AND COLUMN_NAME = 'app_version') = 0,
  'ALTER TABLE game_config ADD COLUMN app_version VARCHAR(20) NOT NULL DEFAULT ''v0.4.7''',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.7';
