-- Fantasy Farmer v0.4.38b — Move location event triggers into the database
-- Run against farmer_game database AFTER ornamental_garden_v0_4_38.sql.
-- Removes hardcoded PHP trigger conditions; all location events now live here.

-- -------------------------------------------------------
-- 1. Extend event_triggers with location support
-- -------------------------------------------------------

-- Add 'location' to the trigger_type enum
ALTER TABLE `event_triggers`
  MODIFY COLUMN `trigger_type`
    enum('manual','scheduled','random','flag_check','location')
    NOT NULL DEFAULT 'manual';

-- Which map location this event marker appears on (NULL for non-location triggers)
ALTER TABLE `event_triggers`
  ADD COLUMN `location_key` varchar(80) DEFAULT NULL AFTER `trigger_key`;

-- Tooltip text shown on the map location marker
ALTER TABLE `event_triggers`
  ADD COLUMN `ui_tooltip` text DEFAULT NULL AFTER `location_key`;

-- -------------------------------------------------------
-- 2. Location trigger rows
-- -------------------------------------------------------
-- trigger_conditions_json keys supported by evaluateTriggerConditions():
--   flags             { "unlock_key": true/false }  — player must have/not have unlock
--   min_reputation    int                           — player reputation threshold
--   min_recognition   int                           — player recognition threshold
--   caravan_active    true                          — caravan must be active this week
--   caravan_bone_brine_ready  true                  — bone & brine relic state must be ready

-- Fae Market invitation (General Store, reputation >= 10, market not yet unlocked)
INSERT INTO `event_triggers`
  (`trigger_id`, `event_id`, `trigger_key`, `trigger_type`,
   `location_key`, `ui_tooltip`,
   `trigger_conditions_json`, `schedule_json`,
   `priority`, `is_repeatable`, `is_active`)
VALUES
  (3, 3, 'market_invite_rep_threshold', 'location',
   'shop', 'The shopkeepers have something to tell you.',
   '{"min_reputation": 10, "flags": {"location_market": false}}', NULL,
   10, 0, 1);

-- Bone & Brine caravan intro (Caravan Camp, active caravan week + second relic)
INSERT INTO `event_triggers`
  (`trigger_id`, `event_id`, `trigger_key`, `trigger_type`,
   `location_key`, `ui_tooltip`,
   `trigger_conditions_json`, `schedule_json`,
   `priority`, `is_repeatable`, `is_active`)
VALUES
  (4, 4, 'bone_brine_caravan_active', 'location',
   'caravan', 'A Bone & Brine wagon has arrived after your second strange relic.',
   '{"caravan_active": true, "caravan_bone_brine_ready": true}', NULL,
   10, 0, 1);

-- Granny Briar ornamental garden intro (Garden, reputation >= 15, market already unlocked)
INSERT INTO `event_triggers`
  (`trigger_id`, `event_id`, `trigger_key`, `trigger_type`,
   `location_key`, `ui_tooltip`,
   `trigger_conditions_json`, `schedule_json`,
   `priority`, `is_repeatable`, `is_active`)
VALUES
  (5, 5, 'granny_briar_garden_visit', 'location',
   'garden', 'Someone is waiting at your garden.',
   '{"min_reputation": 15, "flags": {"location_market": true, "ornamental_garden_unlocked": false}}', NULL,
   20, 0, 1);

-- -------------------------------------------------------
-- Done. Bump app version.
-- -------------------------------------------------------
UPDATE `game_config` SET `app_version` = 'v0.4.38b' WHERE `config_id` = 1;
