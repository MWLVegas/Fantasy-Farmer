SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS processing_jobs;
DROP TABLE IF EXISTS processing_recipes;
DROP TABLE IF EXISTS player_machines;
DROP TABLE IF EXISTS machines;
DROP TABLE IF EXISTS inventory;
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
  goblin_sprite_sheet VARCHAR(255) DEFAULT NULL
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

CREATE TABLE inventory (
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

INSERT INTO game_config (config_id, day_length_seconds, shop_refresh_minutes, day_start_hour, sun_icon, moon_icon, goblin_icon, pouch_icon)
VALUES (1, 720, 60, 6, '☀️', '🌙', '🧌', '👝');

INSERT INTO garden_types (code, name, description, icon) VALUES
('farm', 'Farm Garden', 'Good soil for humble beginnings.', '🌱'),
('water', 'Water Garden', 'Flooded beds for water-loving crops.', '💧'),
('mountain', 'Mountain Garden', 'Rocky terraces for hardy crops.', '⛰️'),
('mystic', 'Mystic Garden', 'Strange soil humming with magic.', '✨');

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon) VALUES
('garden_planted_soil', 'Freshly Planted Soil', 'system', 0, 0, 'assets/icons/garden-planted-soil.png'),
('fairy_bell', 'Fairy Bell', 'material', 0, 0, '🔔'),
('aqua_amulet', 'Aqua Amulet', 'helper_equipment', 0, 0, '💧'),
('relic_first_oddity', 'Strange Buried Relic', 'relic', 0, 0, '🔹'),
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
('blueberry_jam', 'Blueberry Jam', 'processed', 0, 120, '🍯');

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

INSERT INTO machines (machine_type, name, icon, base_cost, queue_size) VALUES
('preserve', 'Preserve Bin', '🍯', 75, 1);

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
  payment_coins INT NOT NULL DEFAULT 0,
  reputation_reward INT NOT NULL DEFAULT 1,
  recognition_reward INT NOT NULL DEFAULT 0,
  order_type ENUM('rush','standard','patient') NOT NULL DEFAULT 'standard',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  fulfilled_at DATETIME DEFAULT NULL,
  is_fulfilled TINYINT(1) NOT NULL DEFAULT 0,
  is_expired TINYINT(1) NOT NULL DEFAULT 0,
  next_available_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
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

INSERT INTO helper_equipment (code, name, task_type, icon, description, sort_order) VALUES
('aqua_amulet', 'Aqua Amulet', 'water', '💧', 'Gives a helper water magic for crop watering.', 10),
('root_charm', 'Root Charm', 'till', '🌱', 'Future tilling and soil preparation charm.', 20),
('harvest_charm', 'Harvest Charm', 'harvest', '🧺', 'Future harvesting charm.', 30),
('weedward_charm', 'Weedward Charm', 'weed', '🛡️', 'Future weed-control charm.', 40),
('bugbane_charm', 'Bugbane Charm', 'pest', '🐞', 'Future pest-control charm.', 50);

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
('fairy_bell_summon', 'The Fairy Bell Rings', 'The first fairy answers the bell and becomes a water helper.', 1)
ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), is_active=VALUES(is_active);

DELETE es FROM event_steps es JOIN events e ON e.event_id = es.event_id WHERE e.event_key IN ('madam_rune_intro','fairy_bell_summon');

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
SELECT event_id, 2, 'A Very Curious Fairy', 'A Tiny Visitor Appears',
'<p>The bell rings once.</p><p>For a moment, nothing happens.</p><p>Then something tiny, bright, and deeply nosy zips out from behind a leaf.</p><p>“Was that a fairy bell? It sounded like a fairy bell. I love fairy bells. Terrible for concentration. Excellent for investigating.”</p><p>She circles your garden, eyes wide.</p><p>“Is this human gardening? I’ve heard about this! You put plants in the dirt on purpose and then become emotionally attached to them?”</p><p>Her gaze lands on the Aqua Amulet.</p><p>“Oh! Is that water magic? You humans use water on plants, don’t you? I can do that. Probably. Almost certainly. Stand back.”</p>',
'Okay.', JSON_OBJECT('inventory', JSON_ARRAY(JSON_OBJECT('code','fairy_bell','qty',-1)), 'flags', JSON_OBJECT('fairy_bell_consumed', true, 'helpers_unlocked', true, 'first_fairy_summoned', true), 'recognition', 1, 'summon_helper', JSON_OBJECT('helper_type','fairy','name','Puddlewink','equipment','aqua_amulet','task','water'), 'unlock_location','forest_folk')
FROM events WHERE event_key='fairy_bell_summon';

INSERT INTO event_triggers (event_id, trigger_key, trigger_type, trigger_conditions_json, schedule_json, priority, is_repeatable, is_active)
SELECT event_id, 'madam_rune_noon_after_first_relic', 'scheduled', JSON_OBJECT('flags', JSON_OBJECT('first_relic_collected', true, 'madam_rune_intro_seen', false)), JSON_OBJECT('player_state_datetime','madam_rune_visit_at'), 10, 0, 1
FROM events WHERE event_key='madam_rune_intro'
ON DUPLICATE KEY UPDATE trigger_conditions_json=VALUES(trigger_conditions_json), schedule_json=VALUES(schedule_json), priority=VALUES(priority), is_active=VALUES(is_active);

INSERT INTO event_triggers (event_id, trigger_key, trigger_type, trigger_conditions_json, schedule_json, priority, is_repeatable, is_active)
SELECT event_id, 'manual_use_fairy_bell', 'manual', JSON_OBJECT('inventory_has', JSON_OBJECT('fairy_bell', 1), 'flags', JSON_OBJECT('helpers_unlocked', false)), NULL, 10, 0, 1
FROM events WHERE event_key='fairy_bell_summon'
ON DUPLICATE KEY UPDATE trigger_conditions_json=VALUES(trigger_conditions_json), priority=VALUES(priority), is_active=VALUES(is_active);

