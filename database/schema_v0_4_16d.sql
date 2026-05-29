-- Fantasy Farmer v0.4.16d
-- Run after v0.4.16c.
-- Order-board calm-down, map/sidebar polish, configurable nav/quest icons, and location-driven event marker scaffolding.

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'side_menu_html'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE map_location_config ADD COLUMN side_menu_html MEDIUMTEXT DEFAULT NULL AFTER glow_color',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'map_side_menu_html'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE game_config ADD COLUMN map_side_menu_html MEDIUMTEXT DEFAULT NULL AFTER shed_background_image',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.16d',
    shop_refresh_minutes = 720,
    map_side_menu_html = '<p class="hint">Use the map to travel around town.</p>';

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active) VALUES
('nav_map', 'Map Icon', 'system', 0, 0, '🗺️', 1),
('nav_backpack', 'Backpack Icon', 'system', 0, 0, '🎒', 1),
('nav_orders', 'Orders Icon', 'system', 0, 0, '📜', 1),
('quest_available', 'Quest Available Icon', 'system', 0, 0, '❗', 1)
ON DUPLICATE KEY UPDATE icon = VALUES(icon), is_active = VALUES(is_active);

DELETE FROM player_unlocks
WHERE unlock_key = 'location_market'
  AND source = 'reputation_10';

UPDATE map_location_config
SET side_menu_html = '<p class="hint">The order board shows your confirmed orders and available requests.</p><p class="hint">Select an available order to review it. If you have an open order slot, you can confirm it and earn local reputation when it is completed on time.</p><p class="hint">Late orders pay less. Cancelling a confirmed order costs reputation.</p>'
WHERE location_key = 'orders';

INSERT INTO events (event_key, title, description, is_active) VALUES
('market_shopkeeper_invite', 'Weekend Market Invite', 'Location-driven invite that unlocks the Farmer''s Market.', 1)
ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), is_active = VALUES(is_active);

DELETE es FROM event_steps es JOIN events e ON e.event_id = es.event_id WHERE e.event_key = 'market_shopkeeper_invite';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, 'Shopkeeper', 'A Weekend Invitation',
'<p>The shopkeeper gives you a measuring look, then smiles.</p><p>“You have been getting reliable work done around here. The weekend market could use another steady grower.”</p><p>“Come by when the market is open. Bring good produce, keep your promises, and try not to teach the carrots any bad habits.”</p>',
'Open the Market',
JSON_OBJECT('unlock_location','market')
FROM events WHERE event_key = 'market_shopkeeper_invite';
