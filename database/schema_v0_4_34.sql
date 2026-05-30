-- Fantasy Farmer v0.4.34
-- Hotfix: remove stale garden_types.background_image references and stop using goblin_icon.
-- Surgical patch only: no image assets are included or overwritten by this patch.

-- Ensure the renamed garden background columns exist, even if v0.4.33 only partially applied.
SET @has_day_bg := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_types' AND COLUMN_NAME = 'day_background_image'
);
SET @has_old_bg := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_types' AND COLUMN_NAME = 'background_image'
);
SET @sql := IF(@has_day_bg = 0 AND @has_old_bg > 0,
  'ALTER TABLE garden_types CHANGE COLUMN background_image day_background_image VARCHAR(255) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_day_bg := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_types' AND COLUMN_NAME = 'day_background_image'
);
SET @sql := IF(@has_day_bg = 0,
  'ALTER TABLE garden_types ADD COLUMN day_background_image VARCHAR(255) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_night_bg := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'garden_types' AND COLUMN_NAME = 'night_background_image'
);
SET @sql := IF(@has_night_bg = 0,
  'ALTER TABLE garden_types ADD COLUMN night_background_image VARCHAR(255) DEFAULT NULL AFTER day_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE garden_types
SET day_background_image = COALESCE(NULLIF(day_background_image, ''), CONCAT('assets/gardens/garden_day_', code, '.png')),
    night_background_image = COALESCE(NULLIF(night_background_image, ''), CONCAT('assets/gardens/garden_night_', code, '.png'));

UPDATE garden_types
SET day_background_image = CONCAT('assets/gardens/garden_day_', code, '.png')
WHERE day_background_image = 'assets/map/garden.png';

-- The code no longer reads goblin_icon. Drop the obsolete config column when present.
SET @has_goblin_icon := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'goblin_icon'
);
SET @sql := IF(@has_goblin_icon > 0,
  'ALTER TABLE game_config DROP COLUMN goblin_icon',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.34';
