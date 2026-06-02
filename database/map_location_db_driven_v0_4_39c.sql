-- Fantasy Farmer v0.4.39c — Fully DB-driven map locations
-- Adds display_name, hint, unlock_key, and is_unlocked_by_default to
-- map_location_config so getLocationsForPlayer() no longer needs a
-- hardcoded list. Any row in this table will appear on the map.
-- Run after garden_type_sizes_v0_4_39b.sql.

ALTER TABLE `map_location_config`
  ADD UNIQUE KEY `uq_location_key` (`location_key`),
  ADD COLUMN `display_name` varchar(100) NOT NULL DEFAULT '' AFTER `location_key`,
  ADD COLUMN `hint` text AFTER `side_menu_html`,
  ADD COLUMN `unlock_key` varchar(100) DEFAULT NULL AFTER `hint`,
  ADD COLUMN `is_unlocked_by_default` tinyint(1) NOT NULL DEFAULT 0 AFTER `unlock_key`;

-- -------------------------------------------------------
-- Populate existing rows
-- -------------------------------------------------------
UPDATE `map_location_config` SET
  `display_name` = 'Orders Board',
  `hint`         = 'Requests from townsfolk.',
  `unlock_key`   = 'orders_board',
  `is_unlocked_by_default` = 1
WHERE `location_key` = 'orders';

UPDATE `map_location_config` SET
  `display_name` = 'Forest Folk',
  `hint`         = 'Summoned helpers using bells and equipped accessories.',
  `unlock_key`   = 'helpers_unlocked',
  `is_unlocked_by_default` = 0
WHERE `location_key` = 'helpers';

UPDATE `map_location_config` SET
  `display_name` = 'General Store',
  `hint`         = 'Seeds, tools, and a weekly special.',
  `unlock_key`   = 'location_shop',
  `is_unlocked_by_default` = 1
WHERE `location_key` = 'shop';

UPDATE `map_location_config` SET
  `display_name` = 'Garden',
  `hint`         = 'Your growing fields.',
  `unlock_key`   = 'location_garden',
  `is_unlocked_by_default` = 1
WHERE `location_key` = 'garden';

UPDATE `map_location_config` SET
  `display_name` = 'Workroom / Shed',
  `hint`         = 'Processing machines and equipment.',
  `unlock_key`   = 'location_shed',
  `is_unlocked_by_default` = 0
WHERE `location_key` = 'shed';

UPDATE `map_location_config` SET
  `display_name` = 'Fae Market',
  `hint`         = 'Opens after the shopkeeper invitation.',
  `unlock_key`   = 'location_market',
  `is_unlocked_by_default` = 0
WHERE `location_key` = 'market';

UPDATE `map_location_config` SET
  `display_name` = 'Caravan Camp',
  `hint`         = 'A travelling camp. Visitors arrive here on occasion.',
  `unlock_key`   = 'location_caravan',
  `is_unlocked_by_default` = 1
WHERE `location_key` = 'caravan';

UPDATE `map_location_config` SET
  `display_name` = 'Bone & Brine',
  `hint`         = 'A permanent oddities stall, once unlocked.',
  `unlock_key`   = 'location_bone_brine',
  `is_unlocked_by_default` = 0
WHERE `location_key` = 'bone_brine';

-- -------------------------------------------------------
-- Murkfen — locked by default, event unlocks it later
-- -------------------------------------------------------
-- UPDATE in case the user already inserted the row manually.
-- INSERT in case they did not.
INSERT INTO `map_location_config`
  (`location_key`, `display_name`, `hint`, `unlock_key`, `is_unlocked_by_default`,
   `map_x`, `map_y`, `map_icon`, `sort_order`, `side_menu_html`, `is_active`, `icon_size`, `glow_color`)
VALUES
  ('murkfen', 'Murkfen', 'A place shrouded in mist and old roots. Something waits there.',
   'location_murkfen', 0,
   150, 380, 'assets/map/murkfen.png', 95, '', 1, 78, 'rgba(120, 180, 140, .78)')
ON DUPLICATE KEY UPDATE
  `display_name`           = VALUES(`display_name`),
  `hint`                   = VALUES(`hint`),
  `unlock_key`             = VALUES(`unlock_key`),
  `is_unlocked_by_default` = VALUES(`is_unlocked_by_default`);

UPDATE `game_config` SET `app_version` = 'v0.4.39c' WHERE `config_id` = 1;
