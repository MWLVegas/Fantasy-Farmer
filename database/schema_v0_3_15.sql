-- Fantasy Farmer v0.3.15 migration
-- Icon consolidation + fertilizer scaffolding.
-- Safe intent: do not drop long-lived/content tables.

ALTER TABLE items
  MODIFY item_type ENUM('seed','produce','processed','fuel','material','fertilizer','system') NOT NULL;

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon, is_active)
VALUES
('garden_planted_soil', 'Planted Soil', 'system', 0, 0, 'assets/icons/garden-planted-soil.png', 1),
('fertilizer_speed', 'Speed Fertilizer', 'fertilizer', 35, 6, '⚡', 1),
('fertilizer_hearty', 'Hearty Fertilizer', 'fertilizer', 40, 8, '🌿', 1),
('fertilizer_weedward', 'Weedward Fertilizer', 'fertilizer', 30, 5, '🍃', 1),
('fertilizer_bugbane', 'Bugbane Fertilizer', 'fertilizer', 30, 5, '🐞', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  item_type = VALUES(item_type),
  base_buy_price = VALUES(base_buy_price),
  base_sell_price = VALUES(base_sell_price),
  icon = VALUES(icon),
  is_active = VALUES(is_active);

CREATE TABLE IF NOT EXISTS fertilizer_definitions (
  fertilizer_definition_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL UNIQUE,
  effect_type ENUM('speed','yield','anti_weed','anti_pest') NOT NULL,
  effect_value INT NOT NULL DEFAULT 0,
  visual_icon VARCHAR(255) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fertilizer_definitions_item
    FOREIGN KEY (item_id) REFERENCES items(item_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO fertilizer_definitions (item_id, effect_type, effect_value, visual_icon, description, is_active)
SELECT item_id, 'speed', 1, '⚡', 'Future effect: reduce growth by one eligible growth step/day on crops with 2+ steps.', 1
FROM items WHERE code = 'fertilizer_speed'
ON DUPLICATE KEY UPDATE effect_type = VALUES(effect_type), effect_value = VALUES(effect_value), visual_icon = VALUES(visual_icon), description = VALUES(description), is_active = VALUES(is_active);

INSERT INTO fertilizer_definitions (item_id, effect_type, effect_value, visual_icon, description, is_active)
SELECT item_id, 'yield', 1, '🌿', 'Future effect: increase harvest yield.', 1
FROM items WHERE code = 'fertilizer_hearty'
ON DUPLICATE KEY UPDATE effect_type = VALUES(effect_type), effect_value = VALUES(effect_value), visual_icon = VALUES(visual_icon), description = VALUES(description), is_active = VALUES(is_active);

INSERT INTO fertilizer_definitions (item_id, effect_type, effect_value, visual_icon, description, is_active)
SELECT item_id, 'anti_weed', 1, '🍃', 'Future effect: prevent or reduce weeds.', 1
FROM items WHERE code = 'fertilizer_weedward'
ON DUPLICATE KEY UPDATE effect_type = VALUES(effect_type), effect_value = VALUES(effect_value), visual_icon = VALUES(visual_icon), description = VALUES(description), is_active = VALUES(is_active);

INSERT INTO fertilizer_definitions (item_id, effect_type, effect_value, visual_icon, description, is_active)
SELECT item_id, 'anti_pest', 1, '🐞', 'Future effect: prevent or reduce pests.', 1
FROM items WHERE code = 'fertilizer_bugbane'
ON DUPLICATE KEY UPDATE effect_type = VALUES(effect_type), effect_value = VALUES(effect_value), visual_icon = VALUES(visual_icon), description = VALUES(description), is_active = VALUES(is_active);

CREATE TABLE IF NOT EXISTS crop_fertilizers (
  crop_fertilizer_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  garden_id INT NOT NULL,
  planted_crop_id INT DEFAULT NULL,
  plot_id INT DEFAULT NULL,
  item_id INT NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumed_at DATETIME DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_user_active (user_id, is_active),
  INDEX idx_crop_active (planted_crop_id, is_active),
  INDEX idx_plot_active (plot_id, is_active),
  CONSTRAINT fk_crop_fertilizers_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_crop_fertilizers_garden
    FOREIGN KEY (garden_id) REFERENCES gardens(garden_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_crop_fertilizers_crop
    FOREIGN KEY (planted_crop_id) REFERENCES planted_crops(planted_crop_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_crop_fertilizers_plot
    FOREIGN KEY (plot_id) REFERENCES garden_plots(plot_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_crop_fertilizers_item
    FOREIGN KEY (item_id) REFERENCES items(item_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional cleanup after verifying your icons have been copied into items.icon:
-- ALTER TABLE plants DROP COLUMN seed_icon;
-- ALTER TABLE plants DROP COLUMN mature_icon;
--
-- We are not dropping those columns automatically in this migration to avoid destroying data
-- before you've had a chance to verify item icons are populated correctly.
