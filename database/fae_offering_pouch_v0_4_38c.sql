-- Fantasy Farmer v0.4.38c — Fae Offering Pouches
-- Adds pouch_type to player_pouches so ornamental gardens
-- can drop fae offerings instead of seed pouches.
-- Also adds last_fae_offering_at to player_state so ornamental
-- and farm pouch timers don't interfere with each other.
-- Run after event_trigger_locations_v0_4_38b.sql.

ALTER TABLE `player_pouches`
  ADD COLUMN `pouch_type` enum('seed','fae_offering') NOT NULL DEFAULT 'seed' AFTER `seed_count`;

ALTER TABLE `player_state`
  ADD COLUMN `last_fae_offering_at` datetime DEFAULT NULL AFTER `last_pouch_at`;

UPDATE `game_config` SET `app_version` = 'v0.4.38c' WHERE `config_id` = 1;
