-- Fantasy Farmer v0.4.39b — Garden type size caps
-- Adds max_size to garden_types. Gardens created from a type
-- inherit this as both max_width and max_height.
-- Run after consolidate_order_items_v0_4_39.sql.

ALTER TABLE `garden_types`
  ADD COLUMN `max_size` int NOT NULL DEFAULT 5 AFTER `unlock_cost`;

-- Ornamental garden is intentionally smaller
UPDATE `garden_types` SET `max_size` = 3 WHERE `code` = 'ornamental';

UPDATE `game_config` SET `app_version` = 'v0.4.39b' WHERE `config_id` = 1;
