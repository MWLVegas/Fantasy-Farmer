-- Fantasy Farmer v0.4.40a — Client-only fixes
-- - Ornamental harvest blocked client-side (no flicker)
-- - Seeds with 0 quantity hidden from seed grid
-- No schema changes; version bump only.

UPDATE `game_config` SET `app_version` = 'v0.4.40a' WHERE `config_id` = 1;
