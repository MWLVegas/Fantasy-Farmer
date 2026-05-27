-- Fantasy Farmer v0.4.3
-- First relic pickup, Madam Rune intro event, and Fairy Bell inventory-use flow.
-- Safe to run over v0.4.2.

-- ------------------------------------------------------------
-- MySQL-safe conditional columns for story scheduling/relic pickups
-- ------------------------------------------------------------

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_state' AND COLUMN_NAME = 'madam_rune_visit_at') = 0,
  'ALTER TABLE player_state ADD COLUMN madam_rune_visit_at DATETIME DEFAULT NULL AFTER recognition',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_relics' AND COLUMN_NAME = 'x_ratio') = 0,
  'ALTER TABLE player_relics ADD COLUMN x_ratio DECIMAL(8,6) DEFAULT NULL AFTER source_action',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_relics' AND COLUMN_NAME = 'y_ratio') = 0,
  'ALTER TABLE player_relics ADD COLUMN y_ratio DECIMAL(8,6) DEFAULT NULL AFTER x_ratio',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_relics' AND COLUMN_NAME = 'visual_state') = 0,
  'ALTER TABLE player_relics ADD COLUMN visual_state VARCHAR(40) NOT NULL DEFAULT ''waiting'' AFTER y_ratio',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_relics' AND COLUMN_NAME = 'collected_at') = 0,
  'ALTER TABLE player_relics ADD COLUMN collected_at DATETIME DEFAULT NULL AFTER discovered_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- First relic inventory item
-- ------------------------------------------------------------

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active) VALUES
('relic_first_oddity', 'Strange Buried Relic', 'relic', 0, 0, '🔹', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  item_type = VALUES(item_type),
  icon = VALUES(icon),
  is_active = VALUES(is_active);

-- ------------------------------------------------------------
-- Repair older v0.4.0-v0.4.2 premature first-relic unlocks.
-- Those versions inserted a relic row and unlocked Madam Rune immediately,
-- but did not create the visible pickup/story flow.
-- ------------------------------------------------------------

UPDATE player_relics
SET display_name = 'Strange Buried Relic',
    relic_type = 'rune_vessel',
    x_ratio = COALESCE(x_ratio, 0.500000),
    y_ratio = COALESCE(y_ratio, 0.500000),
    visual_state = 'waiting',
    collected_at = NULL,
    traded_at = NULL,
    traded_to = NULL
WHERE relic_key = 'first_field_relic'
  AND collected_at IS NULL;

DELETE pu
FROM player_unlocks pu
LEFT JOIN (
  SELECT DISTINCT user_id
  FROM player_unlocks
  WHERE unlock_key IN ('first_relic_collected', 'madam_rune_intro_seen')
) keepers ON keepers.user_id = pu.user_id
WHERE pu.unlock_key IN ('first_relic_found', 'location_caravan', 'madam_rune_unlocked', 'caravan_system_unlocked')
  AND keepers.user_id IS NULL;

INSERT IGNORE INTO player_unlocks (user_id, unlock_key, source)
SELECT DISTINCT user_id, 'first_relic_spawned', 'v0_4_3_repair'
FROM player_relics
WHERE relic_key = 'first_field_relic'
  AND collected_at IS NULL;
