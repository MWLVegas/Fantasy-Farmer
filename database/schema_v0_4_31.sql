-- Fantasy Farmer v0.4.31
-- Fae market wanderer tuning, split background fade timings, and Forest Folk layout polish.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'fae_market_wanderer_size') = 0,
  'ALTER TABLE game_config ADD COLUMN fae_market_wanderer_size DECIMAL(4,2) NOT NULL DEFAULT 1.18',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'fae_market_wanderer_alpha') = 0,
  'ALTER TABLE game_config ADD COLUMN fae_market_wanderer_alpha DECIMAL(4,2) NOT NULL DEFAULT 0.84',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'fae_market_wanderer_hue_shift_enabled') = 0,
  'ALTER TABLE game_config ADD COLUMN fae_market_wanderer_hue_shift_enabled TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.31',
    fae_market_wanderer_size = COALESCE(fae_market_wanderer_size, 1.18),
    fae_market_wanderer_alpha = COALESCE(fae_market_wanderer_alpha, 0.84),
    fae_market_wanderer_hue_shift_enabled = COALESCE(fae_market_wanderer_hue_shift_enabled, 1);
