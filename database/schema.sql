SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS processing_jobs;
DROP TABLE IF EXISTS player_shed_objects;
DROP TABLE IF EXISTS shed_station_config;
DROP TABLE IF EXISTS placeable_defs;
DROP TABLE IF EXISTS shed_zones;
DROP TABLE IF EXISTS processing_recipes;
DROP TABLE IF EXISTS player_machines;
DROP TABLE IF EXISTS machines;
DROP TABLE IF EXISTS player_inventory;
DROP TABLE IF EXISTS player_event_state;
DROP TABLE IF EXISTS event_triggers;
DROP TABLE IF EXISTS event_steps;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS player_orders;
DROP TABLE IF EXISTS order_name_parts;
DROP TABLE IF EXISTS player_worker_plant_order;
DROP TABLE IF EXISTS player_workers;
DROP TABLE IF EXISTS worker_types;
DROP TABLE IF EXISTS seed_hybrid_recipes;
DROP TABLE IF EXISTS player_shop_sales;
DROP TABLE IF EXISTS shop_buy_limits;
DROP TABLE IF EXISTS shop_seed_offers;
DROP TABLE IF EXISTS player_pouches;
DROP TABLE IF EXISTS planted_crops;
DROP TABLE IF EXISTS plants;
DROP TABLE IF EXISTS player_tools;
DROP TABLE IF EXISTS tools;
DROP TABLE IF EXISTS garden_plots;
DROP TABLE IF EXISTS gardens;
DROP TABLE IF EXISTS garden_types;
DROP TABLE IF EXISTS player_state;
DROP TABLE IF EXISTS game_config;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  google_id VARCHAR(128) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) DEFAULT NULL,
  avatar_url TEXT DEFAULT NULL,
  coins INT NOT NULL DEFAULT 120,
  energy INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE game_config (
  config_id INT PRIMARY KEY DEFAULT 1,
  day_length_seconds INT NOT NULL DEFAULT 720,
  shop_refresh_minutes INT NOT NULL DEFAULT 60,
  day_start_hour INT NOT NULL DEFAULT 6,
  sun_icon VARCHAR(255) NOT NULL DEFAULT '☀️',
  moon_icon VARCHAR(255) NOT NULL DEFAULT '🌙',
  goblin_icon VARCHAR(255) NOT NULL DEFAULT '🧌',
  pouch_icon VARCHAR(255) NOT NULL DEFAULT '👝',
  pouch_sprite_sheet VARCHAR(255) DEFAULT NULL,
  app_version VARCHAR(20) NOT NULL DEFAULT 'v0.4.16g',
  max_available_orders INT NOT NULL DEFAULT 5,
  order_board_min_minutes INT NOT NULL DEFAULT 3,
  order_board_max_minutes INT NOT NULL DEFAULT 8,
  order_refill_min_minutes INT NOT NULL DEFAULT 1,
  order_refill_max_minutes INT NOT NULL DEFAULT 1,
  order_normal_min_minutes INT NOT NULL DEFAULT 30,
  order_normal_max_minutes INT NOT NULL DEFAULT 120,
  order_rush_min_minutes INT NOT NULL DEFAULT 15,
  order_rush_max_minutes INT NOT NULL DEFAULT 30,
  order_late_fee_percent INT NOT NULL DEFAULT 20,
  order_rush_bonus_percent INT NOT NULL DEFAULT 20,
  map_title VARCHAR(100) NOT NULL DEFAULT 'Town',
  map_background_image VARCHAR(255) DEFAULT NULL,
  map_button_positions_json TEXT DEFAULT NULL,
  shed_background_image VARCHAR(255) DEFAULT NULL,
  map_side_menu_html MEDIUMTEXT DEFAULT NULL,
  year_length_days INT NOT NULL DEFAULT 300
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE map_location_config (
  map_location_config_id INT AUTO_INCREMENT PRIMARY KEY,
  location_key VARCHAR(80) NOT NULL UNIQUE,
  map_x INT NOT NULL DEFAULT 0,
  map_y INT NOT NULL DEFAULT 0,
  map_icon VARCHAR(255) NOT NULL,
  icon_size INT NOT NULL DEFAULT 78,
  glow_color VARCHAR(80) NOT NULL DEFAULT 'rgba(255, 214, 94, .78)',
  side_menu_html MEDIUMTEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_state (
  user_id INT PRIMARY KEY,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_pouch_at DATETIME DEFAULT NULL,
  next_shop_refresh_at DATETIME DEFAULT NULL,
  next_order_at DATETIME DEFAULT NULL,
  reputation INT NOT NULL DEFAULT 0,
  recognition INT NOT NULL DEFAULT 0,
  madam_rune_visit_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE items (
  item_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  item_type ENUM('seed','produce','processed','fuel','material','fertilizer','system','helper_equipment','relic') NOT NULL,
  base_buy_price INT NOT NULL DEFAULT 0,
  base_sell_price INT NOT NULL DEFAULT 0,
  icon VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE garden_types (
  garden_type_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description TEXT DEFAULT NULL,
  icon VARCHAR(255) DEFAULT NULL,
  unlock_cost INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gardens (
  garden_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  garden_type_id INT NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT 'North Field',
  max_width INT NOT NULL DEFAULT 5,
  max_height INT NOT NULL DEFAULT 5,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_type_id) REFERENCES garden_types(garden_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE garden_plots (
  plot_id INT AUTO_INCREMENT PRIMARY KEY,
  garden_id INT NOT NULL,
  x_pos INT NOT NULL,
  y_pos INT NOT NULL,
  is_unlocked TINYINT(1) NOT NULL DEFAULT 0,
  till_progress INT NOT NULL DEFAULT 0,
  is_tilled TINYINT(1) NOT NULL DEFAULT 0,
  unlocked_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_garden_xy (garden_id, x_pos, y_pos),
  FOREIGN KEY (garden_id) REFERENCES gardens(garden_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tools (
  tool_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  tool_type ENUM('hoe','watering_can','shovel') NOT NULL,
  name VARCHAR(100) NOT NULL,
  level INT NOT NULL DEFAULT 1,
  icon VARCHAR(255) DEFAULT NULL,
  strength INT NOT NULL DEFAULT 10,
  radius INT NOT NULL DEFAULT 0,
  action_speed_modifier DECIMAL(8,3) NOT NULL DEFAULT 1.000,
  action_message VARCHAR(255) DEFAULT NULL,
  complete_message VARCHAR(255) DEFAULT NULL,
  upgrade_cost INT NOT NULL DEFAULT 0,
  next_tool_id INT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (next_tool_id) REFERENCES tools(tool_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_tools (
  player_tool_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tool_id INT NOT NULL,
  equipped TINYINT(1) NOT NULL DEFAULT 1,
  acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_tool (user_id, tool_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (tool_id) REFERENCES tools(tool_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE plants (
  plant_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  allowed_garden_type_code VARCHAR(50) NOT NULL DEFAULT 'farm',
  seed_item_id INT NOT NULL,
  harvest_item_id INT NOT NULL,
  width INT NOT NULL DEFAULT 1,
  height INT NOT NULL DEFAULT 1,
  growth_steps INT NOT NULL DEFAULT 1,
  cycle_hour TINYINT NOT NULL DEFAULT 6,
  water_max INT NOT NULL DEFAULT 100,
  water_required INT NOT NULL DEFAULT 20,
  water_drain_per_game_hour INT NOT NULL DEFAULT 3,
  harvest_min INT NOT NULL DEFAULT 1,
  harvest_max INT NOT NULL DEFAULT 1,
  seed_icon VARCHAR(255) DEFAULT NULL,
  stage_icons_json JSON DEFAULT NULL,
  mature_icon VARCHAR(255) DEFAULT NULL,
  unlock_cost INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (seed_item_id) REFERENCES items(item_id),
  FOREIGN KEY (harvest_item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE planted_crops (
  planted_crop_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  garden_id INT NOT NULL,
  plant_id INT NOT NULL,
  origin_x INT NOT NULL,
  origin_y INT NOT NULL,
  growth_step_current INT NOT NULL DEFAULT 0,
  water_current INT NOT NULL DEFAULT 0,
  has_weeds TINYINT(1) NOT NULL DEFAULT 0,
  has_pests TINYINT(1) NOT NULL DEFAULT 0,
  planted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_cycle_index INT NOT NULL DEFAULT -1,
  is_harvested TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_user_active (user_id, is_harvested),
  INDEX idx_garden_active (garden_id, is_harvested),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_id) REFERENCES gardens(garden_id) ON DELETE CASCADE,
  FOREIGN KEY (plant_id) REFERENCES plants(plant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_pouches (
  pouch_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  garden_id INT NOT NULL,
  x_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.5000,
  y_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.5000,
  seed_count INT NOT NULL DEFAULT 1,
  is_claimed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME DEFAULT NULL,
  sprite_sheet VARCHAR(255) DEFAULT NULL,
  sprite_frame INT NOT NULL DEFAULT 0,
  visual_state ENUM('arriving','waiting','leaving') NOT NULL DEFAULT 'arriving',
  visible_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_active (user_id, is_claimed),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_id) REFERENCES gardens(garden_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_inventory (
  inventory_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_user_item (user_id, item_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE machines (
  machine_id INT AUTO_INCREMENT PRIMARY KEY,
  machine_type VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  icon VARCHAR(255) DEFAULT NULL,
  base_cost INT NOT NULL DEFAULT 0,
  queue_size INT NOT NULL DEFAULT 1,
  speed_modifier DECIMAL(8,3) NOT NULL DEFAULT 1.000,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_machines (
  player_machine_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  machine_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_machine (user_id, machine_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (machine_id) REFERENCES machines(machine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE shed_zones (
  shed_zone_id INT AUTO_INCREMENT PRIMARY KEY,
  zone_key VARCHAR(40) NOT NULL UNIQUE,
  display_name VARCHAR(80) NOT NULL,
  origin_x INT NOT NULL DEFAULT 0,
  origin_y INT NOT NULL DEFAULT 0,
  grid_cols INT NOT NULL DEFAULT 1,
  grid_rows INT NOT NULL DEFAULT 1,
  cell_width INT NOT NULL DEFAULT 48,
  cell_height INT NOT NULL DEFAULT 48,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE placeable_defs (
  placeable_id INT AUTO_INCREMENT PRIMARY KEY,
  placeable_key VARCHAR(80) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  category ENUM('machine','tool','decoration') NOT NULL DEFAULT 'decoration',
  zone_key VARCHAR(40) NOT NULL,
  grid_w INT NOT NULL DEFAULT 1,
  grid_h INT NOT NULL DEFAULT 1,
  icon_path VARCHAR(255) DEFAULT NULL,
  can_rotate TINYINT(1) NOT NULL DEFAULT 0,
  is_functional TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_zone_key (zone_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_shed_objects (
  shed_object_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  placeable_id INT NOT NULL,
  object_category ENUM('machine','tool','decoration') NOT NULL DEFAULT 'decoration',
  player_machine_id INT DEFAULT NULL,
  zone_key VARCHAR(40) NOT NULL,
  grid_x INT NOT NULL DEFAULT 0,
  grid_y INT NOT NULL DEFAULT 0,
  rotation INT NOT NULL DEFAULT 0,
  z_index INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_zone (user_id, zone_key, is_active),
  INDEX idx_player_machine (player_machine_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (placeable_id) REFERENCES placeable_defs(placeable_id),
  FOREIGN KEY (player_machine_id) REFERENCES player_machines(player_machine_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shed_station_config (
  station_id INT AUTO_INCREMENT PRIMARY KEY,
  station_key VARCHAR(80) NOT NULL UNIQUE,
  machine_type VARCHAR(50) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  station_icon VARCHAR(255) DEFAULT NULL,
  station_x INT NOT NULL DEFAULT 0,
  station_y INT NOT NULL DEFAULT 0,
  station_width INT NOT NULL DEFAULT 96,
  station_height INT NOT NULL DEFAULT 96,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_machine_type (machine_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE processing_recipes (
  recipe_id INT AUTO_INCREMENT PRIMARY KEY,
  machine_type VARCHAR(50) NOT NULL,
  input_item_id INT NOT NULL,
  input_quantity INT NOT NULL,
  output_item_id INT NOT NULL,
  output_quantity INT NOT NULL DEFAULT 1,
  processing_time_seconds INT NOT NULL DEFAULT 60,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (input_item_id) REFERENCES items(item_id),
  FOREIGN KEY (output_item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE processing_jobs (
  job_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  player_machine_id INT NOT NULL,
  recipe_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finishes_at DATETIME NOT NULL,
  is_collected TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_user_collected (user_id, is_collected),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (player_machine_id) REFERENCES player_machines(player_machine_id) ON DELETE CASCADE,
  FOREIGN KEY (recipe_id) REFERENCES processing_recipes(recipe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO game_config (config_id, day_length_seconds, shop_refresh_minutes, day_start_hour, sun_icon, moon_icon, goblin_icon, pouch_icon, app_version, max_available_orders, order_board_min_minutes, order_board_max_minutes, order_refill_min_minutes, order_refill_max_minutes, order_normal_min_minutes, order_normal_max_minutes, order_rush_min_minutes, order_rush_max_minutes, order_late_fee_percent, order_rush_bonus_percent, map_title, map_background_image, map_button_positions_json, map_side_menu_html, year_length_days)
VALUES (1, 720, 720, 6, '☀️', '🌙', '🧌', '👝', 'v0.4.16g', 5, 3, 8, 1, 1, 30, 120, 15, 30, 20, 20, 'Town', NULL, '{"orders":[298,105],"helpers":[475,125],"shop":[110,290],"garden":[298,298],"shed":[485,290],"bone_brine":[90,510],"market":[298,500],"caravan":[470,510]}', '<p class="hint">Use the map to travel around town.</p>', 300);

INSERT INTO map_location_config (location_key, map_x, map_y, map_icon, icon_size, glow_color, sort_order, is_active) VALUES
('orders', 298, 105, 'assets/map/orders.png', 82, 'rgba(255, 214, 94, .78)', 10, 1),
('helpers', 475, 125, 'assets/map/fairy_folk.png', 86, 'rgba(255, 214, 94, .78)', 20, 1),
('shop', 110, 290, 'assets/map/store.png', 88, 'rgba(255, 214, 94, .78)', 30, 1),
('garden', 298, 298, 'assets/map/garden.png', 86, 'rgba(255, 214, 94, .78)', 40, 1),
('shed', 485, 290, 'assets/map/shed.png', 84, 'rgba(255, 214, 94, .78)', 50, 1),
('bone_brine', 90, 510, 'assets/map/bone_brine.png', 78, 'rgba(255, 214, 94, .78)', 60, 1),
('market', 298, 500, 'assets/map/market.png', 78, 'rgba(255, 214, 94, .78)', 70, 1),
('caravan', 470, 510, 'assets/map/caravan_empty.png', 96, 'rgba(255, 214, 94, .78)', 80, 1);

INSERT INTO garden_types (code, name, description, icon) VALUES
('farm', 'Farm Garden', 'Good soil for humble beginnings.', '🌱'),
('water', 'Water Garden', 'Flooded beds for water-loving crops.', '💧'),
('mountain', 'Mountain Garden', 'Rocky terraces for hardy crops.', '⛰️'),
('mystic', 'Mystic Garden', 'Strange soil humming with magic.', '✨');

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon) VALUES
('garden_planted_soil', 'Freshly Planted Soil', 'system', 0, 0, 'assets/icons/garden-planted-soil.png'),
('global_coin', 'Coin Icon', 'system', 0, 0, 'assets/icons/global-coin.png'),
('global_reputation', 'Reputation Icon', 'system', 0, 0, 'assets/icons/global-reputation.png'),
('global_recognition', 'Recognition Icon', 'system', 0, 0, 'assets/icons/global-recognition.png'),
('nav_map', 'Map Icon', 'system', 0, 0, '🗺️'),
('nav_backpack', 'Backpack Icon', 'system', 0, 0, '🎒'),
('nav_orders', 'Orders Icon', 'system', 0, 0, '📜'),
('quest_available', 'Quest Available Icon', 'system', 0, 0, '!'),
('fairy_bell', 'Fairy Bell', 'material', 0, 0, '🔔'),
('aqua_amulet', 'Aqua Amulet', 'helper_equipment', 0, 0, '💧'),
('relic_first_oddity', 'Strange Buried Relic', 'relic', 0, 0, '🔹'),
('land_claim_note', 'Land Claim Note', 'material', 175, 0, '📜'),
('speed_fertilizer', 'Speed Fertilizer', 'fertilizer', 35, 0, '⚡'),
('hearty_fertilizer', 'Hearty Fertilizer', 'fertilizer', 40, 0, '🌿'),
('weedward_fertilizer', 'Weedward Fertilizer', 'fertilizer', 35, 0, '🛡️'),
('bugbane_fertilizer', 'Bugbane Fertilizer', 'fertilizer', 35, 0, '🐞'),
('carrot_seed', 'Carrot Seeds', 'seed', 5, 0, '🥕'),
('carrot', 'Carrot', 'produce', 0, 4, '🥕'),
('strawberry_seed', 'Strawberry Seeds', 'seed', 12, 0, '🍓'),
('strawberry', 'Strawberry', 'produce', 0, 7, '🍓'),
('strawberry_jam', 'Strawberry Jam', 'processed', 0, 80, '🍯'),
('blueberry_seed', 'Blueberry Seeds', 'seed', 35, 0, '🫐'),
('blueberry', 'Blueberry', 'produce', 0, 10, '🫐'),
('blueberry_jam', 'Blueberry Jam', 'processed', 0, 120, '🍯'),
('root_charm', 'Root Charm', 'helper_equipment', 0, 0, '🌱'),
('seed_satchel', 'Seed Satchel', 'helper_equipment', 0, 0, '🌰'),
('harvest_basket', 'Harvest Basket', 'helper_equipment', 0, 0, '🧺'),
('market_pouch', 'Market Pouch', 'helper_equipment', 0, 0, '👝'),
('order_stamp', 'Order Stamp', 'helper_equipment', 0, 0, '📮');

INSERT INTO shop_buy_limits (item_id, daily_limit, is_basic, is_active) VALUES
((SELECT item_id FROM items WHERE code='carrot'), 12, 1, 1),
((SELECT item_id FROM items WHERE code='strawberry'), 8, 1, 1)
ON DUPLICATE KEY UPDATE daily_limit=VALUES(daily_limit), is_basic=VALUES(is_basic), is_active=VALUES(is_active);

INSERT INTO tools (code, tool_type, name, level, icon, strength, radius, action_speed_modifier, action_message, complete_message, upgrade_cost) VALUES
('broken_hoe', 'hoe', 'Broken Hoe', 1, 'assets/icons/tool-hoe-broken.png', 25, 0, 1.000, 'The broken hoe bites into the dirt.', 'The plot is tilled.', 50),
('wooden_hoe', 'hoe', 'Wooden Hoe', 2, 'assets/icons/tool-hoe-wood.png', 50, 0, 1.000, 'The wooden hoe cuts cleanly through the soil.', 'The plot is fully tilled.', 75),
('iron_hoe', 'hoe', 'Iron Hoe', 3, 'assets/icons/tool-hoe-iron.png', 100, 0, 0.900, 'The iron hoe means business.', 'The plot is fully tilled.', 250),
('leaky_watering_can', 'watering_can', 'Leaky Can', 1, 'assets/icons/tool-can-broken.png', 15, 0, 1.000, 'Watered. Mostly. The can tried.', 'The crop is fully watered.', 50),
('wooden_watering_can', 'watering_can', 'Wooden Can', 2, 'assets/icons/tool-can-wood.png', 30, 0, 1.000, 'A proper splash of water.', 'The crop is fully watered.', 75),
('iron_watering_can', 'watering_can', 'Iron Can', 3, 'assets/icons/tool-can-iron.png', 60, 0, 0.900, 'The iron can rains with authority.', 'The crop is fully watered.', 250),
('bent_shovel', 'shovel', 'Bent Shovel', 1, 'assets/icons/tool-shovel-broken.png', 1, 0, 1.000, 'The bent shovel scrapes at the roots.', 'The crop has been dug up.', 50),
('wooden_shovel', 'shovel', 'Wooden Shovel', 2, 'assets/icons/tool-shovel-wood.png', 2, 0, 1.000, 'The wooden shovel works the roots loose.', 'The crop has been dug up.', 75),
('iron_shovel', 'shovel', 'Iron Shovel', 3, 'assets/icons/tool-shovel-iron.png', 4, 0, 0.900, 'The iron shovel ends negotiations.', 'The crop has been dug up.', 250);

INSERT INTO plants (code, name, allowed_garden_type_code, seed_item_id, harvest_item_id, width, height, growth_steps, cycle_hour, water_max, water_required, water_drain_per_game_hour, harvest_min, harvest_max, seed_icon, stage_icons_json, mature_icon) VALUES
('carrot', 'Carrot', 'farm', (SELECT item_id FROM items WHERE code = 'carrot_seed'), (SELECT item_id FROM items WHERE code = 'carrot'), 1, 1, 1, 6, 100, 20, 4, 1, 2, '🥕', JSON_ARRAY('🌱','🥕'), '🥕'),
('strawberry', 'Strawberry', 'farm', (SELECT item_id FROM items WHERE code = 'strawberry_seed'), (SELECT item_id FROM items WHERE code = 'strawberry'), 1, 1, 3, 6, 100, 30, 5, 2, 4, '🍓', JSON_ARRAY('🌱','🌿','🌸','🍓'), '🍓'),
('blueberry_bush', 'Blueberry Bush', 'farm', (SELECT item_id FROM items WHERE code = 'blueberry_seed'), (SELECT item_id FROM items WHERE code = 'blueberry'), 2, 2, 4, 6, 120, 40, 4, 4, 8, '🫐', JSON_ARRAY('🌱','🌿','🌳','🌳','🫐'), '🫐');

INSERT INTO machines (machine_type, name, icon, base_cost, queue_size, speed_modifier, is_active) VALUES
('preserve', 'Preserves Bin', '🍯', 250, 1, 1.000, 1),
('drying', 'Drying Rack', '🧺', 450, 1, 1.000, 0),
('compost', 'Compost Bin', '♻️', 350, 1, 1.000, 0),
('seed_bin', 'Seed Bin', '🌰', 500, 1, 1.000, 0),
('workbench', 'Workbench', '🛠️', 650, 1, 1.000, 0);

INSERT INTO shed_zones (zone_key, display_name, origin_x, origin_y, grid_cols, grid_rows, cell_width, cell_height, sort_order, is_active) VALUES
('wall', 'Wall', 96, 92, 13, 5, 40, 40, 10, 1),
('floor', 'Floor', 112, 326, 9, 4, 64, 64, 20, 1);

INSERT INTO placeable_defs (placeable_key, display_name, category, zone_key, grid_w, grid_h, icon_path, can_rotate, is_functional, sort_order, is_active) VALUES
('preserve_bin', 'Preserves Bin', 'machine', 'floor', 2, 2, '🍯', 0, 1, 10, 1);

INSERT INTO shed_station_config (station_key, machine_type, display_name, station_icon, station_x, station_y, station_width, station_height, sort_order, is_active) VALUES
('preserves_bin_station', 'preserve', 'Preserves Bin', '🍯', 350, 610, 94, 94, 10, 1),
('drying_rack_station', 'drying', 'Drying Rack', '🧺', 470, 600, 104, 90, 20, 1),
('compost_bin_station', 'compost', 'Compost Bin', '♻️', 590, 625, 92, 92, 30, 1),
('seed_bin_station', 'seed_bin', 'Seed Bin', '🌰', 250, 625, 86, 86, 40, 1),
('workbench_station', 'workbench', 'Workbench', '🛠️', 145, 575, 108, 92, 50, 1);


INSERT INTO processing_recipes (machine_type, input_item_id, input_quantity, output_item_id, output_quantity, processing_time_seconds) VALUES
('preserve', (SELECT item_id FROM items WHERE code = 'strawberry'), 8, (SELECT item_id FROM items WHERE code = 'strawberry_jam'), 1, 300),
('preserve', (SELECT item_id FROM items WHERE code = 'blueberry'), 8, (SELECT item_id FROM items WHERE code = 'blueberry_jam'), 1, 420);



CREATE TABLE shop_seed_offers (
  offer_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  price INT NOT NULL,
  is_rare TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shop_buy_limits (
  shop_buy_limit_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL UNIQUE,
  daily_limit INT NOT NULL DEFAULT 10,
  is_basic TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_shop_sales (
  player_shop_sale_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  shop_day INT NOT NULL,
  quantity_sold INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_user_item_day (user_id, item_id, shop_day),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE seed_hybrid_recipes (
  hybrid_recipe_id INT AUTO_INCREMENT PRIMARY KEY,
  parent_seed_a_item_id INT NOT NULL,
  parent_seed_b_item_id INT NOT NULL,
  result_seed_item_id INT NOT NULL,
  success_chance DECIMAL(5,2) NOT NULL DEFAULT 25.00,
  is_discovered_by_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (parent_seed_a_item_id) REFERENCES items(item_id),
  FOREIGN KEY (parent_seed_b_item_id) REFERENCES items(item_id),
  FOREIGN KEY (result_seed_item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_types (
  worker_type_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  worker_role ENUM('generalist','waterer','tiller','planter','harvester') NOT NULL,
  icon VARCHAR(255) NOT NULL DEFAULT '🧌',
  description TEXT DEFAULT NULL,
  hire_cost INT NOT NULL DEFAULT 25,
  cost_per_game_hour INT NOT NULL DEFAULT 1,
  task_seconds_min INT NOT NULL DEFAULT 45,
  task_seconds_max INT NOT NULL DEFAULT 75,
  sprite_sheet VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_workers (
  player_worker_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  worker_type_id INT NOT NULL,
  nickname VARCHAR(100) DEFAULT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  hired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  current_task VARCHAR(80) DEFAULT NULL,
  target_garden_id INT DEFAULT NULL,
  target_x INT DEFAULT NULL,
  target_y INT DEFAULT NULL,
  task_started_at DATETIME DEFAULT NULL,
  task_finishes_at DATETIME DEFAULT NULL,
  x_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.0500,
  y_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.5000,
  sprite_frame INT NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (worker_type_id) REFERENCES worker_types(worker_type_id),
  FOREIGN KEY (target_garden_id) REFERENCES gardens(garden_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_worker_plant_order (
  order_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  plant_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_user_plant (user_id, plant_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (plant_id) REFERENCES plants(plant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_name_parts (
  name_part_id INT AUTO_INCREMENT PRIMARY KEY,
  part_type ENUM('first','last','business') NOT NULL,
  value VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_orders (
  player_order_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  order_code VARCHAR(40) NOT NULL,
  customer_name VARCHAR(255) NOT NULL,
  base_payment_coins INT NOT NULL DEFAULT 0,
  payment_coins INT NOT NULL DEFAULT 0,
  reputation_reward INT NOT NULL DEFAULT 1,
  recognition_reward INT NOT NULL DEFAULT 0,
  order_type ENUM('rush','standard','patient') NOT NULL DEFAULT 'standard',
  order_status ENUM('available','accepted','fulfilled','cancelled','expired') NOT NULL DEFAULT 'available',
  fulfillment_minutes INT NOT NULL DEFAULT 60,
  accepted_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  fulfilled_at DATETIME DEFAULT NULL,
  is_fulfilled TINYINT(1) NOT NULL DEFAULT 0,
  is_expired TINYINT(1) NOT NULL DEFAULT 0,
  next_available_at DATETIME DEFAULT NULL,
  cancel_reputation_penalty INT NOT NULL DEFAULT 1,
  late_fee_percent INT NOT NULL DEFAULT 20,
  completed_late TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_user_order_status (user_id, order_status, expires_at),
  INDEX idx_user_active (user_id, is_fulfilled, is_expired)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
  order_item_id INT AUTO_INCREMENT PRIMARY KEY,
  player_order_id INT NOT NULL,
  item_id INT NOT NULL,
  quantity_required INT NOT NULL,
  FOREIGN KEY (player_order_id) REFERENCES player_orders(player_order_id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_unlocks (
  unlock_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  unlock_key VARCHAR(100) NOT NULL,
  source VARCHAR(100) DEFAULT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_unlock (user_id, unlock_key),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_relics (
  relic_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  relic_key VARCHAR(100) NOT NULL,
  display_name VARCHAR(180) NOT NULL,
  relic_type VARCHAR(60) NOT NULL DEFAULT 'oddity',
  source_action VARCHAR(60) DEFAULT NULL,
  x_ratio DECIMAL(8,6) DEFAULT NULL,
  y_ratio DECIMAL(8,6) DEFAULT NULL,
  visual_state VARCHAR(40) NOT NULL DEFAULT 'waiting',
  discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  collected_at DATETIME DEFAULT NULL,
  traded_at DATETIME DEFAULT NULL,
  traded_to VARCHAR(100) DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_user_relics (user_id, discovered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE helper_types (
  helper_type_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  species_key VARCHAR(80) NOT NULL,
  name VARCHAR(100) NOT NULL,
  icon VARCHAR(255) NOT NULL DEFAULT '🧚',
  description TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE helper_equipment (
  helper_equipment_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  task_type VARCHAR(80) NOT NULL,
  icon VARCHAR(255) NOT NULL DEFAULT '✨',
  description TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  accessory_tag VARCHAR(80) NOT NULL DEFAULT 'forest_folk_accessory',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE player_helpers (
  player_helper_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  helper_type_id INT NOT NULL,
  helper_name VARCHAR(100) DEFAULT NULL,
  equipped_helper_equipment_id INT DEFAULT NULL,
  active_task VARCHAR(80) DEFAULT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  x_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.5000,
  y_ratio DECIMAL(6,4) NOT NULL DEFAULT 0.5000,
  speed_rating INT NOT NULL DEFAULT 10,
  effectiveness_rating INT NOT NULL DEFAULT 10,
  temp_speed_bonus_until DATETIME DEFAULT NULL,
  temp_effectiveness_bonus_until DATETIME DEFAULT NULL,
  last_action_at DATETIME DEFAULT NULL,
  summoned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (helper_type_id) REFERENCES helper_types(helper_type_id),
  FOREIGN KEY (equipped_helper_equipment_id) REFERENCES helper_equipment(helper_equipment_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fertilizer_definitions (
  fertilizer_definition_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL UNIQUE,
  effect_type VARCHAR(80) NOT NULL,
  effect_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  visual_icon VARCHAR(255) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE crop_fertilizers (
  crop_fertilizer_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  garden_id INT NOT NULL,
  planted_crop_id INT NOT NULL,
  item_id INT NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumed_at DATETIME DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_id) REFERENCES gardens(garden_id) ON DELETE CASCADE,
  FOREIGN KEY (planted_crop_id) REFERENCES planted_crops(planted_crop_id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO helper_types (code, species_key, name, icon, description, sort_order) VALUES
('fairy', 'fairy', 'Fairy', '🧚', 'Curious forest folk who learn human gardening through equipped magic.', 10),
('brownie', 'brownie', 'Brownie', '🍞', 'Future sturdy house-and-garden helper scaffold.', 20),
('mushling', 'mushling', 'Mushling', '🍄', 'Future soil, fertilizer, and compost specialist scaffold.', 30),
('spriggan', 'spriggan', 'Spriggan', '🌿', 'Future weeds and pests specialist scaffold.', 40);

INSERT INTO helper_equipment (code, name, task_type, icon, description, sort_order, accessory_tag) VALUES
('aqua_amulet', 'Aqua Amulet', 'water', '💧', 'Gives a helper water magic for crop watering.', 10, 'forest_folk_accessory'),
('root_charm', 'Root Charm', 'till', '🌱', 'Assigns Till work to a helper.', 20, 'forest_folk_accessory'),
('seed_satchel', 'Seed Satchel', 'plant', '🌰', 'Assigns Plant work to a helper.', 30, 'forest_folk_accessory'),
('harvest_basket', 'Harvest Basket', 'harvest', '🧺', 'Assigns Harvest work to a helper.', 40, 'forest_folk_accessory'),
('market_pouch', 'Market Pouch', 'market_sell', '👝', 'Lets a helper carry produce to market.', 80, 'forest_folk_accessory'),
('order_stamp', 'Order Stamp', 'orders', '📮', 'Lets a helper help with order paperwork.', 90, 'forest_folk_accessory');

INSERT INTO fertilizer_definitions (item_id, effect_type, effect_value, visual_icon, description) VALUES
((SELECT item_id FROM items WHERE code='speed_fertilizer'), 'speed_days', 1, '⚡', 'Future effect: shorten eligible crop cycles.'),
((SELECT item_id FROM items WHERE code='hearty_fertilizer'), 'yield_bonus', 1, '🌿', 'Future effect: increase harvest yield.'),
((SELECT item_id FROM items WHERE code='weedward_fertilizer'), 'prevent_weeds', 1, '🛡️', 'Future effect: prevent weeds.'),
((SELECT item_id FROM items WHERE code='bugbane_fertilizer'), 'prevent_pests', 1, '🐞', 'Future effect: prevent pests.');

INSERT INTO worker_types (code, name, worker_role, icon, description, hire_cost, cost_per_game_hour, task_seconds_min, task_seconds_max) VALUES
('generalist_goblin', 'Generalist Goblin', 'generalist', '🧌', 'Does everything slowly: harvest, till, plant, and water.', 40, 1, 45, 75),
('watering_goblin', 'Watering Goblin', 'waterer', '💧', 'Only waters, but does it faster.', 65, 2, 20, 45),
('tilling_goblin', 'Tilling Goblin', 'tiller', '🪓', 'Only tills soil, but does it faster.', 65, 2, 20, 45),
('planting_goblin', 'Planting Goblin', 'planter', '🌱', 'Only plants seeds from your plant order.', 75, 2, 20, 45),
('harvest_goblin', 'Harvest Goblin', 'harvester', '🧺', 'Only harvests ready crops.', 75, 2, 20, 45);

INSERT INTO order_name_parts (part_type, value) VALUES
('first','Hank'),('first','Mira'),('first','Pip'),('first','Juniper'),('first','Brindle'),('first','Tilda'),('first','Weswick'),('first','Mossbert'),
('last','Thunderbottom'),('last','Picklebranch'),('last','Moonspoon'),('last','Bogwhistle'),('last','Mudpocket'),('last','Fernfidget'),
('business','The Magical Pants Store'),('business','Crooked Spoon Pantry'),('business','Moonlit Turnip Exchange'),('business','Goblin & Sons Wholesale Snacks'),('business','The Very Official Jam Bureau'),('business','Moss & Mildly Legal Produce');


-- v0.4.4 event engine seed/content
-- Fantasy Farmer v0.4.4
-- Database-driven story event engine.

CREATE TABLE IF NOT EXISTS events (
  event_id INT AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(100) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  description TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_steps (
  step_id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  step_order INT NOT NULL,
  speaker_name VARCHAR(100) DEFAULT NULL,
  title VARCHAR(180) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  button_text VARCHAR(80) NOT NULL DEFAULT 'Okay',
  background_image VARCHAR(255) DEFAULT NULL,
  portrait_image VARCHAR(255) DEFAULT NULL,
  effects_json JSON DEFAULT NULL,
  UNIQUE KEY uniq_event_step_order (event_id, step_order),
  FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_triggers (
  trigger_id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  trigger_key VARCHAR(120) NOT NULL UNIQUE,
  trigger_type ENUM('manual','scheduled','random','flag_check') NOT NULL DEFAULT 'manual',
  trigger_conditions_json JSON DEFAULT NULL,
  schedule_json JSON DEFAULT NULL,
  priority INT NOT NULL DEFAULT 100,
  is_repeatable TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_event_state (
  player_event_state_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_id INT NOT NULL,
  current_step_order INT NOT NULL DEFAULT 1,
  status ENUM('pending','active','complete','cancelled') NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_user_event_open (user_id, event_id, status),
  INDEX idx_user_status (user_id, status),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO events (event_key, title, description, is_active) VALUES
('madam_rune_intro', 'A Caravan Arrives', 'Madam Rune introduces herself and trades for the first relic.', 1),
('fairy_bell_summon', 'The Fairy Bell Rings', 'The first fairy answers the bell and becomes a water helper.', 1),
('market_shopkeeper_invite', 'Weekend Market Invite', 'Location-driven invite that unlocks the Farmer''s Market.', 1)
ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), is_active=VALUES(is_active);

DELETE es FROM event_steps es JOIN events e ON e.event_id = es.event_id WHERE e.event_key IN ('madam_rune_intro','fairy_bell_summon','market_shopkeeper_invite');

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, 'Madam Rune', 'A Caravan Arrives',
'<p>Around midday, the quiet of your garden is interrupted by the creak of old wheels and the soft clatter of hanging charms.</p><p>A peculiar wagon rolls to a stop nearby, draped in moss, trailing vines, and enough dangling trinkets to worry any sensible horse.</p><p>From within emerges a goblin woman wrapped in layered fabrics, jangling jewelry, and a confidence usually reserved for people who know what they are doing.</p><p>“The hands of Fate have brought a visitor! ... or was it the <em>hams</em> of Fate?”</p><p>She squints at you, then gasps.</p><p>“Someone comes bearing <strong>DESTINY!</strong> ... and perhaps coupons!”</p><p>She pats her pockets, frowns, then looks suddenly delighted.</p><p>“Ah! Yes. Introductions. My name is... Sister Sa— no, no, that was Tuesday. Lady Cri— wait. What day even <em>is</em> it?”</p><p>She throws both hands into the air.</p><p>“Oh! Of course. <strong>I am Madam Rune!</strong>”</p>',
'... okay?', NULL
FROM events WHERE event_key='madam_rune_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 2, 'Madam Rune', 'Madam Rune Peers at the Relic',
'<p>Madam Rune leans in toward the relic, her eyes widening until you begin to worry they might simply leave her head.</p><p>“Ohhh. Oh, that is <em>old</em>. Old Empire, unless I am mistaken. And I am only mistaken on Wednesdays, in matters of soup, and once about a goose.”</p><p>She taps the relic with one long fingernail.</p><p>“A vessel, you see. It once held <strong>aetherglimmer</strong> — or perhaps <strong>thrumlight</strong>. No, wait. Aetherglimmer. Definitely aetherglimmer. Probably.”</p><p>She presses it to her ear and smiles.</p><p>“Empty now, of course. But it still hums. Beautifully useless. My favorite kind of important.”</p><p>She rummages through her robes and produces three similar relics, each wrapped in bits of cloth and string.</p><p>“I have three others. Each one hums at a different pitch. But with yours? Ah! A quartet! A divine little quartet of forgotten imperial nonsense!”</p><p>Then she holds up an old bell.</p><p>“I tried using this thing, but it is far too high-pitched, and the fae would not leave it alone. Tiny winged busybodies. Always listening. Always curious. Always asking if mushrooms count as chairs.”</p><p>She places the bell in your hand, then adds a damp-looking crystal beside it.</p><p>“And this! An Aqua Amulet. It has the power to make <strong>anything wet</strong>. Simply place it upon the item you wish to moisten, pour water over it, and behold! Moisture!”</p><p>She nods gravely, as if she has just explained fire.</p>',
'... um ... okay?', NULL
FROM events WHERE event_key='madam_rune_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 3, 'Madam Rune', 'The Relic Finds Its Place',
'<p>Madam Rune carefully takes the relic and carries it to her wagon, where three similarly shaped objects rest on a velvet cloth.</p><p>She sets yours beside them.</p><p>Then moves it slightly.</p><p>Then slightly back.</p><p>Then forward by what cannot possibly be more than a hair’s width.</p><p>Several minutes pass.</p><p>At last, she clasps her hands together and beams.</p><p>“THERE! Beautiful! That sound is... the most clarity-inducing noise I have encountered in all my centuries!”</p><p>You listen closely.</p><p>You hear absolutely nothing.</p><p>Madam Rune, wearing a smile that seems to go on for days, sweeps back into her caravan. The door slams shut, the wheels creak, and the whole thing begins to roll away.</p><p>As she disappears down the road, she hollers back:</p><p>“If you find any more, save them for me! I’ll have a whole choir soon, just like the prophecy foretold!”</p><p>More confused than ever, you stow the bell and the moist rock, then turn back toward your garden.</p>',
'Okay.', JSON_OBJECT('inventory', JSON_ARRAY(JSON_OBJECT('code','relic_first_oddity','qty',-1), JSON_OBJECT('code','fairy_bell','qty',1), JSON_OBJECT('code','aqua_amulet','qty',1)), 'flags', JSON_OBJECT('madam_rune_intro_seen', true, 'madam_rune_met', true, 'madam_rune_unlocked', true, 'location_caravan', true, 'caravan_system_unlocked', true, 'aqua_amulet_obtained', true), 'recognition', 1, 'relic_trade', 'madam_rune')
FROM events WHERE event_key='madam_rune_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, NULL, 'Ring the Fairy Bell?',
'<p>The little bell gives off a bright, impossibly clear chime even before you ring it.</p><p>Madam Rune did say the fae would not leave it alone.</p>',
'Ring the Bell', NULL
FROM events WHERE event_key='fairy_bell_summon';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 2, 'Puddlewink', 'A Tiny Visitor Appears',
'<p>The bell rings once.</p><p>For a moment, nothing happens.</p><p>Then something tiny, bright, and deeply nosy zips out from behind a leaf.</p><p>“Was that a fairy bell? It sounded like a fairy bell. I love fairy bells. Terrible for concentration. Excellent for investigating.”</p><p>She freezes midair, staring at your plots.</p><p>“WAIT. Is this gardening? I mean <em>human</em> gardening?!”</p><p>Her wings blur with excitement.</p><p>“That is so awesome! I have heard all about this! You throw old plant parts into the ground, get emotionally attached, and then somehow food happens, right?”</p><p>She presses both hands to her cheeks.</p><p>“I could help with that. I <em>love</em> getting emotionally attached to things. Usually they die, though.”</p><p>She winces.</p><p>“Oof. I hope that is not some sort of omen.”</p><p>She straightens proudly, as if she has just been hired by a very important turnip.</p><p>“My name is <strong>Puddlewink</strong>. I am a fairy, technically, though I prefer <em>garden consultant</em>. Give me an accessory and I can help. Without one, I mostly supervise with excellent posture.”</p>',
'Continue', NULL
FROM events WHERE event_key='fairy_bell_summon';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 3, 'Puddlewink', 'The Water Problem',
'<p>Puddlewink circles your plots like a tiny vulture, eyeing each crop with grave professional concern.</p><p>“This one should be named <strong>Fizzlewick</strong>. Look at him. He looks like a Fizzlewick, does he not? That weird little nose and all.”</p><p>She darts to the next plot.</p><p>“Ooh, and this one can be—”</p><p>She stops mid-sentence, hovering perfectly still.</p><p>For one shining second, it looks as though the largest thought in fairy history has just struck her directly between the eyes.</p><p>“Oh. My. Dragons.”</p><p>She spins toward you.</p><p>“I just realized. Your food needs <em>water</em>.”</p><p>Puddlewink clutches her head dramatically.</p><p>“Blast it! I used to have an old Aqua Amulet that let me perform water magic. That would be the perfect job for me. The <em>perfect</em> job. I could zip around, sprinkle everything, look extremely important—ugh, we need to find a new one!”</p><p>She immediately begins checking under rocks, leaves, roots, and one suspiciously innocent clump of dirt, as if the missing amulet might have politely waited there for several years.</p><p>“It has been a few years since I last saw it, but it is probably around here somewhere, right? That is how lost things work. Anyway, if you find it, just let me know. You know where to find me!”</p><p>Before you can ask where, exactly, that is, she zips off into the distance with the confidence of someone who assumes you have known her your entire life.</p><p>You try to call after her, but she is gone before the first sound leaves your mouth.</p><p>Slowly, you pull the water stone from your pocket and turn it over in your hand.</p><p><em>“Just add water,”</em> Madam Rune had said.</p><p>Is this what Puddlewink is looking for?</p>',
'I’ll keep an eye out.', JSON_OBJECT('inventory', JSON_ARRAY(JSON_OBJECT('code','fairy_bell','qty',-1)), 'flags', JSON_OBJECT('fairy_bell_consumed', true, 'helpers_unlocked', true, 'first_fairy_summoned', true), 'recognition', 1, 'summon_helper', JSON_OBJECT('helper_type','fairy','name','Puddlewink','equipment','','task','idle'), 'unlock_location','forest_folk')
FROM events WHERE event_key='fairy_bell_summon';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, 'Shopkeepers', 'A Quiet Invitation',
'<p>The General Store feels warmer than usual today, as if the room has been holding its breath and trying very hard not to look suspicious.</p><p>Behind the counter, the kind old woman glances toward you and gives the old man beside her a tiny nod. <em>That one?</em></p><p>The old man studies you for a moment, then nods back. <em>Yep. That one.</em></p><p>The woman folds her hands over the counter and smiles.</p><p>“Tell me, farmer. Have you ever heard of the <strong>Fae Market</strong>?”</p>',
'The what?', NULL
FROM events WHERE event_key='market_shopkeeper_invite';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 2, 'Shopkeeper', 'Not Exactly Normal',
'<p>“Right. So.” She lowers her voice, though the old man is already pretending very loudly to organize seed packets.</p><p>“You have met <strong>Puddlewink</strong>, which means you have probably noticed our town is not exactly... normal.”</p><p>She gives a sheepish little smile.</p><p>“When you first arrived, we were not sure whether we would need to send you politely on your way. Outsiders can be tricky. They ask questions. They touch glowing mushrooms. They try to pay fairies in button lint.”</p><p>“But Puddlewink assured us you were good-hearted. And you have already helped the townsfolk more than most people manage in a season.”</p><p>For one brief moment, her shoes lift from the floor. A pair of vibrant gossamer wings flicker behind her shoulders, bright as dew in sunrise. Then she settles back down, and the wings vanish as if they had only been a trick of the light.</p><p>“So we would like to formally invite you to visit our stall at the Fae Market this weekend.”</p>',
'You have wings?', NULL
FROM events WHERE event_key='market_shopkeeper_invite';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 3, 'Shopkeeper', 'The Fae Market',
'<p>The old man chuckles. “Most folk around here have a surprise or two tucked away.”</p><p>The woman gives him a look, then turns back to you.</p><p>“The Fae Market opens every weekend from <strong>7:00 to 18:00</strong>. Our little store buys only the basics, and only so much in a day. The market is different.”</p><p>“Bring any crop you like. Market folk always have a use for good produce, and they will buy as much as you can carry.”</p><p>Her smile turns bright and conspiratorial.</p><p>“Stop by when the gates are open. It may open your eyes to a much wider world.”</p>',
'I’ll visit this weekend.', JSON_OBJECT('unlock_location','market')
FROM events WHERE event_key='market_shopkeeper_invite';

INSERT INTO event_triggers (event_id, trigger_key, trigger_type, trigger_conditions_json, schedule_json, priority, is_repeatable, is_active)
SELECT event_id, 'madam_rune_noon_after_first_relic', 'scheduled', JSON_OBJECT('flags', JSON_OBJECT('first_relic_collected', true, 'madam_rune_intro_seen', false)), JSON_OBJECT('player_state_datetime','madam_rune_visit_at'), 10, 0, 1
FROM events WHERE event_key='madam_rune_intro'
ON DUPLICATE KEY UPDATE trigger_conditions_json=VALUES(trigger_conditions_json), schedule_json=VALUES(schedule_json), priority=VALUES(priority), is_active=VALUES(is_active);

INSERT INTO event_triggers (event_id, trigger_key, trigger_type, trigger_conditions_json, schedule_json, priority, is_repeatable, is_active)
SELECT event_id, 'manual_use_fairy_bell', 'manual', JSON_OBJECT('inventory_has', JSON_OBJECT('fairy_bell', 1), 'flags', JSON_OBJECT('helpers_unlocked', false)), NULL, 10, 0, 1
FROM events WHERE event_key='fairy_bell_summon'
ON DUPLICATE KEY UPDATE trigger_conditions_json=VALUES(trigger_conditions_json), priority=VALUES(priority), is_active=VALUES(is_active);

