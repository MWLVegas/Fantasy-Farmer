-- Fantasy Farmer v0.4.16j
-- Helper activity polish, order modal flow, and calendar icon/scaffolding.

UPDATE game_config
SET app_version = 'v0.4.16j';

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active)
VALUES ('nav_calendar', 'Calendar Icon', 'system', 0, 0, '📅', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  item_type = VALUES(item_type),
  icon = VALUES(icon),
  is_active = VALUES(is_active);
