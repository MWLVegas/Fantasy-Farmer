-- Fantasy Farmer v0.4.36
-- Use DB-backed image icons for garden plot states instead of canvas-drawn CSS/SVG-style plots.

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active)
VALUES
  ('plot_untilled', 'Untilled Plot Icon', 'system', 0, 0, 'assets/icons/plot-untilled.png', 1),
  ('plot_partial', 'Partially Tilled Plot Icon', 'system', 0, 0, 'assets/icons/plot-partial.png', 1),
  ('plot_tilled', 'Tilled Plot Icon', 'system', 0, 0, 'assets/icons/plot-tilled.png', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  item_type = VALUES(item_type),
  icon = VALUES(icon),
  is_active = VALUES(is_active);

UPDATE game_config
SET app_version = 'v0.4.36'
WHERE config_id = 1;
