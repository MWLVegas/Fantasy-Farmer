-- Fantasy Farmer v0.4.6
-- Order board display cleanup, rush bonus/late payout data, and database-backed app version.
-- Run after v0.4.5.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'app_version') = 0,
  'ALTER TABLE game_config ADD COLUMN app_version VARCHAR(20) NOT NULL DEFAULT ''v0.4.6''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'max_available_orders') = 0,
  'ALTER TABLE game_config ADD COLUMN max_available_orders INT NOT NULL DEFAULT 5', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_late_fee_percent') = 0,
  'ALTER TABLE game_config ADD COLUMN order_late_fee_percent INT NOT NULL DEFAULT 20', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_rush_bonus_percent') = 0,
  'ALTER TABLE game_config ADD COLUMN order_rush_bonus_percent INT NOT NULL DEFAULT 20', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.6',
    order_rush_bonus_percent = COALESCE(NULLIF(order_rush_bonus_percent, 0), 20),
    order_late_fee_percent = COALESCE(NULLIF(order_late_fee_percent, 0), 20),
    max_available_orders = COALESCE(NULLIF(max_available_orders, 0), 5);

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'base_payment_coins') = 0,
  'ALTER TABLE player_orders ADD COLUMN base_payment_coins INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE player_orders
SET base_payment_coins = CASE
    WHEN base_payment_coins > 0 THEN base_payment_coins
    WHEN order_type = 'rush' THEN FLOOR(payment_coins / 1.2)
    ELSE payment_coins
  END,
  late_fee_percent = COALESCE(NULLIF(late_fee_percent, 0), 20)
WHERE base_payment_coins = 0 OR base_payment_coins IS NULL;
