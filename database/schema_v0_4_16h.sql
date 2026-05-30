-- Fantasy Farmer v0.4.16h
-- Persistent update warning, more client-trusted garden sync, Fae Market timing/rename, and market background/inventory scaffolding.
-- Run after v0.4.16g.

UPDATE game_config
SET app_version = 'v0.4.16h';

UPDATE items
SET name = 'Fae Market Icon'
WHERE code = 'quest_available'
  AND item_type = 'system'
  AND name = 'Quest Available Icon';

UPDATE map_location_config
SET map_icon = 'assets/map/market.png'
WHERE location_key = 'market';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'day_background_image') = 0,
  'ALTER TABLE map_location_config ADD COLUMN day_background_image VARCHAR(255) DEFAULT NULL AFTER side_menu_html',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'night_background_image') = 0,
  'ALTER TABLE map_location_config ADD COLUMN night_background_image VARCHAR(255) DEFAULT NULL AFTER day_background_image',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS fae_market_inventory (
  fae_market_inventory_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  market_phase ENUM('day','night','both') NOT NULL DEFAULT 'both',
  stock_mode ENUM('infinite','limited') NOT NULL DEFAULT 'infinite',
  daily_limit INT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_item_phase (item_id, market_phase),
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO fae_market_inventory (item_id, market_phase, stock_mode, daily_limit, is_active, sort_order)
SELECT item_id, 'both', 'infinite', NULL, 1, 100
FROM items
WHERE item_type = 'produce'
ON DUPLICATE KEY UPDATE
  stock_mode = VALUES(stock_mode),
  daily_limit = VALUES(daily_limit),
  is_active = VALUES(is_active);

UPDATE events
SET title = 'Fae Market Invitation',
    description = 'The General Store shopkeepers formally invite the player to the weekend Fae Market.'
WHERE event_key = 'market_shopkeeper_invite';

UPDATE event_steps es
JOIN events e ON e.event_id = es.event_id
SET es.body_html = REPLACE(es.body_html, '7:00 to 18:00', 'Saturday 6:00 to Sunday 18:00')
WHERE e.event_key = 'market_shopkeeper_invite';
