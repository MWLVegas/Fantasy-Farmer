-- Fantasy Farmer v0.4.16k
-- DB-backed global icon aliases and tool pseudo-icons.

UPDATE game_config
SET app_version = 'v0.4.16k';

INSERT INTO `items` (`code`, `name`, `item_type`, `base_buy_price`, `base_sell_price`, `icon`, `is_active`) VALUES
('tool_harvest', 'Harvest Tool Icon', 'system', 0, 0, 'assets/icons/tool-harvest.png', 1),
('tool_inspect', 'Inspect Tool Icon', 'system', 0, 0, 'assets/icons/tool-inspect.png', 1),
('global_orders', 'Orders Icon', 'system', 0, 0, 'assets/icons/global-orders.png', 1),
('global_backpack', 'Backpack Icon', 'system', 0, 0, 'assets/icons/global-backpack.png', 1),
('global_calendar', 'Calendar Icon', 'system', 0, 0, 'assets/icons/global-calendar.png', 1),
('global_moon', 'Moon Icon', 'system', 0, 0, 'assets/icons/global-moon.png', 1),
('global_sun', 'Sun Icon', 'system', 0, 0, 'assets/icons/global-sun.png', 1),
('global_map', 'Map Icon', 'system', 0, 0, 'assets/icons/global-map.png', 1),
('nav_orders', 'Orders Icon', 'system', 0, 0, 'assets/icons/global-orders.png', 1),
('nav_backpack', 'Backpack Icon', 'system', 0, 0, 'assets/icons/global-backpack.png', 1),
('nav_calendar', 'Calendar Icon', 'system', 0, 0, 'assets/icons/global-calendar.png', 1),
('nav_map', 'Map Icon', 'system', 0, 0, 'assets/icons/global-map.png', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `item_type` = VALUES(`item_type`),
  `icon` = VALUES(`icon`),
  `is_active` = VALUES(`is_active`);

UPDATE game_config
SET sun_icon = 'assets/icons/global-sun.png',
    moon_icon = 'assets/icons/global-moon.png';
