-- Fantasy Farmer v0.4.13
-- Run after v0.4.12c.
-- Makes global money/reputation/recognition icons database-driven.

UPDATE game_config
SET app_version = 'v0.4.13';

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active)
VALUES
('global_coin', 'Coin Icon', 'system', 0, 0, 'assets/icons/global-coin.png', 1),
('global_reputation', 'Reputation Icon', 'system', 0, 0, 'assets/icons/global-reputation.png', 1),
('global_recognition', 'Recognition Icon', 'system', 0, 0, 'assets/icons/global-recognition.png', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  item_type = VALUES(item_type),
  base_buy_price = VALUES(base_buy_price),
  base_sell_price = VALUES(base_sell_price),
  icon = VALUES(icon),
  is_active = VALUES(is_active);
