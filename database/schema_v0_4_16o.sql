-- Fantasy Farmer v0.4.16o
-- Market canvas buyables, absolute CSS background paths, Fae market wanderer config, and darker Forest Folk cards.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'fae_market_wanderer_count') = 0,
  'ALTER TABLE game_config ADD COLUMN fae_market_wanderer_count INT NOT NULL DEFAULT 5',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'fae_market_wanderer_image_count') = 0,
  'ALTER TABLE game_config ADD COLUMN fae_market_wanderer_image_count INT NOT NULL DEFAULT 6',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.16o',
    fae_market_wanderer_count = COALESCE(NULLIF(fae_market_wanderer_count, 0), 5),
    fae_market_wanderer_image_count = COALESCE(NULLIF(fae_market_wanderer_image_count, 0), 6);

UPDATE map_location_config
SET side_menu_html = '<p class="hint">The Fae Market is open from the Day of Leaves at 06:00 through Sunday at 18:00.</p><p class="hint">Use the market stalls in the scene to buy special finds. Sell crops, seeds, weeds, and bugs from your backpack here while the gates are open.</p>'
WHERE location_key = 'market';
