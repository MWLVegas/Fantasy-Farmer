-- Fantasy Farmer v0.4.12b
-- Run after v0.4.12a.
-- Forest Folk accessory scaffolding, plot deeds, faster order refill, sharper UI scaffolds.

UPDATE game_config
SET app_version = 'v0.4.12b',
    order_refill_min_minutes = 1,
    order_refill_max_minutes = 1,
    max_available_orders = 5;

-- Fairy-world calendar support. 300 days keeps the world fairytale-ish and avoids Day 8123918123.
SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE game_config ADD COLUMN year_length_days INT NOT NULL DEFAULT 300',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='game_config' AND COLUMN_NAME='year_length_days');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE game_config SET year_length_days = 300;

-- Land Claim Notes unlock locked plots one at a time.
INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active)
VALUES ('land_claim_note', 'Land Claim Note', 'material', 175, 0, '📜', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), base_buy_price=VALUES(base_buy_price), icon=VALUES(icon), is_active=1;

-- Helper movement / stat scaffolding.
SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE player_helpers ADD COLUMN x_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.5000',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='player_helpers' AND COLUMN_NAME='x_ratio');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE player_helpers ADD COLUMN y_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.5000',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='player_helpers' AND COLUMN_NAME='y_ratio');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE player_helpers ADD COLUMN speed_rating INT NOT NULL DEFAULT 10',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='player_helpers' AND COLUMN_NAME='speed_rating');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE player_helpers ADD COLUMN effectiveness_rating INT NOT NULL DEFAULT 10',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='player_helpers' AND COLUMN_NAME='effectiveness_rating');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE player_helpers ADD COLUMN temp_speed_bonus_until DATETIME DEFAULT NULL',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='player_helpers' AND COLUMN_NAME='temp_speed_bonus_until');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE player_helpers ADD COLUMN temp_effectiveness_bonus_until DATETIME DEFAULT NULL',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='player_helpers' AND COLUMN_NAME='temp_effectiveness_bonus_until');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE helper_equipment ADD COLUMN accessory_tag VARCHAR(80) NOT NULL DEFAULT ''forest_folk_accessory''',
  'SELECT 1')
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='helper_equipment' AND COLUMN_NAME='accessory_tag');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE helper_equipment SET accessory_tag = 'forest_folk_accessory';

-- Equipment/accessory content. These are also inventory items so the backpack can sort them easily.
INSERT INTO helper_equipment (code, name, task_type, icon, description, sort_order, is_active, accessory_tag) VALUES
('aqua_amulet', 'Aqua Amulet', 'water', '💧', 'Assigns Water work to a helper.', 10, 1, 'forest_folk_accessory'),
('root_charm', 'Root Charm', 'till', '🌱', 'Assigns Till work to a helper.', 20, 1, 'forest_folk_accessory'),
('seed_satchel', 'Seed Satchel', 'plant', '🌰', 'Assigns Plant work to a helper.', 30, 1, 'forest_folk_accessory'),
('harvest_basket', 'Harvest Basket', 'harvest', '🧺', 'Assigns Harvest work to a helper.', 40, 1, 'forest_folk_accessory'),
('market_pouch', 'Market Pouch', 'market_sell', '👝', 'Future Farmer''s Market auto-sell accessory.', 80, 1, 'forest_folk_accessory'),
('order_stamp', 'Order Stamp', 'orders', '📮', 'Future order-completion accessory.', 90, 1, 'forest_folk_accessory')
ON DUPLICATE KEY UPDATE name=VALUES(name), task_type=VALUES(task_type), icon=VALUES(icon), description=VALUES(description), sort_order=VALUES(sort_order), is_active=VALUES(is_active), accessory_tag=VALUES(accessory_tag);

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active) VALUES
('root_charm', 'Root Charm', 'helper_equipment', 0, 0, '🌱', 1),
('seed_satchel', 'Seed Satchel', 'helper_equipment', 0, 0, '🌰', 1),
('harvest_basket', 'Harvest Basket', 'helper_equipment', 0, 0, '🧺', 1),
('market_pouch', 'Market Pouch', 'helper_equipment', 0, 0, '👝', 1),
('order_stamp', 'Order Stamp', 'helper_equipment', 0, 0, '📮', 1)
ON DUPLICATE KEY UPDATE item_type=VALUES(item_type), icon=VALUES(icon), is_active=VALUES(is_active);

-- Rename the first summoned fairy if already created by the old event, and make accessories explicit instead of implied.
UPDATE player_helpers ph
JOIN helper_types ht ON ht.helper_type_id = ph.helper_type_id
SET ph.helper_name = COALESCE(NULLIF(ph.helper_name,''), 'Puddlewink'),
    ph.active_task = COALESCE((SELECT he.task_type FROM helper_equipment he WHERE he.helper_equipment_id = ph.equipped_helper_equipment_id), 'idle')
WHERE ht.code = 'fairy';

-- Shop pacing: only the Preserves Bin stays in the general store; later machines move to specials/market/events.
UPDATE machines SET base_cost = CASE machine_type
  WHEN 'preserve' THEN 250
  WHEN 'drying' THEN 450
  WHEN 'compost' THEN 350
  WHEN 'seed_bin' THEN 500
  WHEN 'workbench' THEN 650
  ELSE base_cost END;
UPDATE machines SET is_active = CASE WHEN machine_type = 'preserve' THEN 1 ELSE 0 END;

-- The first fairy summon should create a named fairy with an empty accessory slot.
UPDATE event_steps es
JOIN events e ON e.event_id = es.event_id
SET es.body_html = '<p>The bell rings once.</p><p>For a moment, nothing happens.</p><p>Then something tiny, bright, and deeply nosy zips out from behind a leaf.</p><p>“Was that a fairy bell? It sounded like a fairy bell. I love fairy bells. Terrible for concentration. Excellent for investigating.”</p><p>She circles your garden, eyes wide.</p><p>“My name is <strong>Puddlewink</strong>. I am a fairy, technically, though I prefer <em>garden consultant</em>. Give me an accessory and I can help. Without one, I mostly supervise with excellent posture.”</p>',
    es.effects_json = JSON_OBJECT('inventory', JSON_ARRAY(JSON_OBJECT('code','fairy_bell','qty',-1)), 'flags', JSON_OBJECT('fairy_bell_consumed', true, 'helpers_unlocked', true, 'first_fairy_summoned', true), 'recognition', 1, 'summon_helper', JSON_OBJECT('helper_type','fairy','name','Puddlewink','equipment','','task','idle'), 'unlock_location','forest_folk')
WHERE e.event_key='fairy_bell_summon' AND es.step_order=2;
