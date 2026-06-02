-- Fantasy Farmer v0.4.43a — Add display_icon to plants
-- Ornamental plants need a separate icon for how they look while growing,
-- distinct from their harvest_item icon.

ALTER TABLE `plants`
  ADD COLUMN `display_icon` varchar(255) DEFAULT NULL AFTER `code`;

-- Sunflower: use a sunflower emoji until actual art exists.
-- Replace with an image path (e.g. assets/icons/crop-sunflower.png) once the asset is ready.
UPDATE `plants` SET `display_icon` = '🌻' WHERE `code` = 'sunflower';

UPDATE `game_config` SET `app_version` = 'v0.4.43a' WHERE `config_id` = 1;
