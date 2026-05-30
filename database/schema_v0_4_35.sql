-- Fantasy Farmer v0.4.35
-- Move garden click/use pop effect graphics out of JS and into editable system item rows.

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active) VALUES
('tool_harvest', 'Harvest Tool Cursor Icon', 'system', 0, 0, '🧺', 1),
('tool_inspect', 'Inspect Tool Cursor Icon', 'system', 0, 0, '🔎', 1),
('fx_water', 'Water Click Effect Icon', 'system', 0, 0, '💧', 1),
('fx_till', 'Till Click Effect Icon', 'system', 0, 0, '💢', 1),
('fx_plant', 'Plant Click Effect Icon', 'system', 0, 0, '✨', 1),
('fx_harvest', 'Harvest Click Effect Icon', 'system', 0, 0, '✦', 1),
('fx_dig', 'Dig Click Effect Icon', 'system', 0, 0, '🪨', 1),
('fx_pouch', 'Pouch Click Effect Icon', 'system', 0, 0, '💨', 1),
('fx_relic', 'Relic Click Effect Icon', 'system', 0, 0, '🔹', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  item_type = VALUES(item_type),
  is_active = VALUES(is_active);

UPDATE game_config
SET app_version = 'v0.4.35'
WHERE config_id = 1;
