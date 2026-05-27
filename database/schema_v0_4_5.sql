-- Fantasy Farmer v0.4.5
-- Corrected MySQL-safe patch.
-- Run after v0.4.4.

-- ------------------------------------------------------------
-- Rename inventory -> player_inventory
-- ------------------------------------------------------------

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory') = 1
  AND
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_inventory') = 0,
  'RENAME TABLE inventory TO player_inventory',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- game_config columns
-- ------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'max_available_orders') = 0,
  'ALTER TABLE game_config ADD COLUMN max_available_orders INT NOT NULL DEFAULT 5', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_board_min_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_board_min_minutes INT NOT NULL DEFAULT 3', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_board_max_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_board_max_minutes INT NOT NULL DEFAULT 8', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_refill_min_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_refill_min_minutes INT NOT NULL DEFAULT 2', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_refill_max_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_refill_max_minutes INT NOT NULL DEFAULT 5', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_normal_min_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_normal_min_minutes INT NOT NULL DEFAULT 30', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_normal_max_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_normal_max_minutes INT NOT NULL DEFAULT 120', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_rush_min_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_rush_min_minutes INT NOT NULL DEFAULT 15', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_rush_max_minutes') = 0,
  'ALTER TABLE game_config ADD COLUMN order_rush_max_minutes INT NOT NULL DEFAULT 30', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'order_late_fee_percent') = 0,
  'ALTER TABLE game_config ADD COLUMN order_late_fee_percent INT NOT NULL DEFAULT 20', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config
SET max_available_orders = COALESCE(NULLIF(max_available_orders, 0), 5),
    order_board_min_minutes = COALESCE(NULLIF(order_board_min_minutes, 0), 3),
    order_board_max_minutes = COALESCE(NULLIF(order_board_max_minutes, 0), 8),
    order_refill_min_minutes = COALESCE(NULLIF(order_refill_min_minutes, 0), 2),
    order_refill_max_minutes = COALESCE(NULLIF(order_refill_max_minutes, 0), 5),
    order_normal_min_minutes = COALESCE(NULLIF(order_normal_min_minutes, 0), 30),
    order_normal_max_minutes = COALESCE(NULLIF(order_normal_max_minutes, 0), 120),
    order_rush_min_minutes = COALESCE(NULLIF(order_rush_min_minutes, 0), 15),
    order_rush_max_minutes = COALESCE(NULLIF(order_rush_max_minutes, 0), 30),
    order_late_fee_percent = COALESCE(NULLIF(order_late_fee_percent, 0), 20);

-- ------------------------------------------------------------
-- player_orders columns
-- ------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'order_status') = 0,
  'ALTER TABLE player_orders ADD COLUMN order_status ENUM(''available'',''accepted'',''fulfilled'',''cancelled'',''expired'') NOT NULL DEFAULT ''accepted''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'fulfillment_minutes') = 0,
  'ALTER TABLE player_orders ADD COLUMN fulfillment_minutes INT NOT NULL DEFAULT 60', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'accepted_at') = 0,
  'ALTER TABLE player_orders ADD COLUMN accepted_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'next_available_at') = 0,
  'ALTER TABLE player_orders ADD COLUMN next_available_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'cancel_reputation_penalty') = 0,
  'ALTER TABLE player_orders ADD COLUMN cancel_reputation_penalty INT NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'late_fee_percent') = 0,
  'ALTER TABLE player_orders ADD COLUMN late_fee_percent INT NOT NULL DEFAULT 20', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_orders' AND COLUMN_NAME = 'completed_late') = 0,
  'ALTER TABLE player_orders ADD COLUMN completed_late TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing unfinished orders from pre-0.4.5 should not flood confirmed slots.
UPDATE player_orders
SET order_status = CASE
    WHEN is_fulfilled = 1 THEN 'fulfilled'
    WHEN is_expired = 1 THEN 'expired'
    ELSE 'expired'
  END,
  accepted_at = NULL,
  cancel_reputation_penalty = COALESCE(NULLIF(cancel_reputation_penalty, 0), 1),
  late_fee_percent = COALESCE(NULLIF(late_fee_percent, 0), 20)
WHERE order_status IS NULL
   OR order_status = ''
   OR (order_status = 'available' AND created_at < DATE_SUB(NOW(), INTERVAL 9 MINUTE));

DELETE FROM player_inventory WHERE quantity <= 0;
