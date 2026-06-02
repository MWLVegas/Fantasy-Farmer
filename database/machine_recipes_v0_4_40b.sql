-- Fantasy Farmer v0.4.40b — Machine Recipes overhaul
-- Renames processing_recipes → machine_recipes and adds proper
-- cycle-based timing, output min/max, and per-recipe metadata.
-- All crafting machines share this one table (machine_type discriminates).
-- Vendors with different mechanics (Murkfen barters etc.) get their own tables.

-- Drop FK that references processing_recipes
ALTER TABLE `processing_jobs`
  DROP FOREIGN KEY `processing_jobs_ibfk_3`;

-- Rename
RENAME TABLE `processing_recipes` TO `machine_recipes`;

-- Add new columns
ALTER TABLE `machine_recipes`
  ADD COLUMN `recipe_key`  varchar(80)  DEFAULT NULL  AFTER `recipe_id`,
  ADD COLUMN `name`        varchar(100) DEFAULT NULL  AFTER `recipe_key`,
  ADD COLUMN `output_min`  int NOT NULL DEFAULT 1     AFTER `output_quantity`,
  ADD COLUMN `output_max`  int NOT NULL DEFAULT 1     AFTER `output_min`,
  ADD COLUMN `cycle_count` int NOT NULL DEFAULT 1     AFTER `output_max`,
  ADD COLUMN `cycle_hours` int NOT NULL DEFAULT 12    AFTER `cycle_count`,
  ADD COLUMN `sort_order`  int NOT NULL DEFAULT 0     AFTER `cycle_hours`;

-- Populate existing recipes
-- Strawberry jam: 8 strawberries → 1 jar, 1 cycle × 12 game-hours
UPDATE `machine_recipes` SET
  recipe_key  = 'preserve_strawberry_jam',
  name        = 'Strawberry Jam',
  output_min  = 1, output_max = 1,
  cycle_count = 1, cycle_hours = 12, sort_order = 10
WHERE recipe_id = 1;

-- Blueberry jam: 8 blueberries → 1 jar, 1 cycle × 14 game-hours (slightly rarer berry)
UPDATE `machine_recipes` SET
  recipe_key  = 'preserve_blueberry_jam',
  name        = 'Blueberry Jam',
  output_min  = 1, output_max = 1,
  cycle_count = 1, cycle_hours = 14, sort_order = 20
WHERE recipe_id = 2;

-- Re-add FK pointing at the renamed table
ALTER TABLE `processing_jobs`
  ADD CONSTRAINT `processing_jobs_ibfk_3`
  FOREIGN KEY (`recipe_id`) REFERENCES `machine_recipes` (`recipe_id`);

-- Preserve bin gets a 4-slot queue so multiple jobs can be started
UPDATE `machines` SET `queue_size` = 4 WHERE `machine_type` = 'preserve';

UPDATE `game_config` SET `app_version` = 'v0.4.40b' WHERE `config_id` = 1;
