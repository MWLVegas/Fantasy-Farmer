-- Fantasy Farmer v0.4.43b — Drop display_icon from plants
-- Growth stages are shown exclusively via assets/icons/crops/<plant_code>_<step>.png

ALTER TABLE `plants` DROP COLUMN `display_icon`;

UPDATE `game_config` SET `app_version` = 'v0.4.43b' WHERE `config_id` = 1;
