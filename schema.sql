SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS processing_jobs;
DROP TABLE IF EXISTS processing_recipes;
DROP TABLE IF EXISTS player_machines;
DROP TABLE IF EXISTS machines;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS planted_crops;
DROP TABLE IF EXISTS plants;
DROP TABLE IF EXISTS player_tools;
DROP TABLE IF EXISTS tools;
DROP TABLE IF EXISTS garden_plots;
DROP TABLE IF EXISTS gardens;
DROP TABLE IF EXISTS garden_types;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  google_id VARCHAR(128) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) DEFAULT NULL,
  avatar_url TEXT DEFAULT NULL,
  coins INT NOT NULL DEFAULT 25,
  energy INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE items (
  item_id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  item_type ENUM('seed','produce','processed','fuel','material') NOT NULL,
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
  action_radius INT NOT NULL DEFAULT 1,
  action_speed_modifier DECIMAL(8,3) NOT NULL DEFAULT 1.000,
  water_amount INT NOT NULL DEFAULT 0,
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
  UNIQUE KEY uniq_user_tool_type (user_id, tool_id),
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
  seconds_per_step INT NOT NULL DEFAULT 60,
  water_max INT NOT NULL DEFAULT 100,
  water_required INT NOT NULL DEFAULT 20,
  water_drain_per_hour INT NOT NULL DEFAULT 20,
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
  growth_progress_seconds INT NOT NULL DEFAULT 0,
  water_current INT NOT NULL DEFAULT 0,
  has_weeds TINYINT(1) NOT NULL DEFAULT 0,
  has_pests TINYINT(1) NOT NULL DEFAULT 0,
  planted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_harvested TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_user_active (user_id, is_harvested),
  INDEX idx_garden_active (garden_id, is_harvested),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (garden_id) REFERENCES gardens(garden_id) ON DELETE CASCADE,
  FOREIGN KEY (plant_id) REFERENCES plants(plant_id)
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

INSERT INTO garden_types (code, name, description, icon) VALUES
('farm', 'Farm Garden', 'Good soil for humble beginnings.', '🌱'),
('water', 'Water Garden', 'Flooded beds for water-loving crops.', '💧'),
('mountain', 'Mountain Garden', 'Rocky terraces for hardy crops.', '⛰️'),
('mystic', 'Mystic Garden', 'Strange soil humming with magic.', '✨');

INSERT INTO items (code, name, item_type, base_buy_price, base_sell_price, icon) VALUES
('carrot_seed', 'Carrot Seeds', 'seed', 5, 0, '🥕'),
('carrot', 'Carrot', 'produce', 0, 4, '🥕'),
('strawberry_seed', 'Strawberry Seeds', 'seed', 12, 0, '🍓'),
('strawberry', 'Strawberry', 'produce', 0, 7, '🍓'),
('strawberry_jam', 'Strawberry Jam', 'processed', 0, 80, '🍯'),
('blueberry_seed', 'Blueberry Seeds', 'seed', 35, 0, '🫐'),
('blueberry', 'Blueberry', 'produce', 0, 10, '🫐'),
('blueberry_jam', 'Blueberry Jam', 'processed', 0, 120, '🍯');

INSERT INTO tools (code, tool_type, name, level, icon, action_radius, action_speed_modifier, water_amount, upgrade_cost) VALUES
('broken_hoe', 'hoe', 'Broken Hoe', 1, '🪓', 1, 1.000, 0, 50),
('leaky_watering_can', 'watering_can', 'Leaky Watering Can', 1, '🪣', 1, 1.000, 100, 50),
('bent_shovel', 'shovel', 'Bent Shovel', 1, '🥄', 1, 1.000, 0, 50);

INSERT INTO plants (
  code, name, allowed_garden_type_code, seed_item_id, harvest_item_id, width, height,
  growth_steps, seconds_per_step, water_max, water_required, water_drain_per_hour,
  harvest_min, harvest_max, seed_icon, stage_icons_json, mature_icon
) VALUES
('carrot', 'Carrot', 'farm',
 (SELECT item_id FROM items WHERE code = 'carrot_seed'),
 (SELECT item_id FROM items WHERE code = 'carrot'),
 1, 1, 1, 60, 100, 20, 20, 1, 2, '🥕', JSON_ARRAY('🌱','🥕'), '🥕'),
('strawberry', 'Strawberry', 'farm',
 (SELECT item_id FROM items WHERE code = 'strawberry_seed'),
 (SELECT item_id FROM items WHERE code = 'strawberry'),
 1, 1, 3, 90, 100, 30, 25, 2, 4, '🍓', JSON_ARRAY('🌱','🌿','🌸','🍓'), '🍓'),
('blueberry_bush', 'Blueberry Bush', 'farm',
 (SELECT item_id FROM items WHERE code = 'blueberry_seed'),
 (SELECT item_id FROM items WHERE code = 'blueberry'),
 2, 2, 4, 180, 120, 40, 20, 4, 8, '🫐', JSON_ARRAY('🌱','🌿','🌳','🌳','🫐'), '🫐');

INSERT INTO machines (machine_type, name, icon, base_cost, queue_size) VALUES
('preserve', 'Preserve Bin', '🍯', 75, 1);

INSERT INTO processing_recipes (machine_type, input_item_id, input_quantity, output_item_id, output_quantity, processing_time_seconds) VALUES
('preserve',
 (SELECT item_id FROM items WHERE code = 'strawberry'),
 8,
 (SELECT item_id FROM items WHERE code = 'strawberry_jam'),
 1, 300),
('preserve',
 (SELECT item_id FROM items WHERE code = 'blueberry'),
 8,
 (SELECT item_id FROM items WHERE code = 'blueberry_jam'),
 1, 420);
