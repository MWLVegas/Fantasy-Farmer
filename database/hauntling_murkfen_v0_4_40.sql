-- Fantasy Farmer v0.4.40 — Hauntling Pepper & Murkfen
-- Adds the hauntling pepper crop chain and the Murkfen intro event.
-- Murkfen is a barter vendor (items-for-items) unlocked after:
--   1. Bone & Brine is unlocked (bone_brine_caravan_seen flag)
--   2. Player plants their first hauntling pepper (hauntling_pepper_planted flag)
-- Run after map_location_db_driven_v0_4_39c.sql.

-- -------------------------------------------------------
-- 1. Items
-- -------------------------------------------------------
INSERT IGNORE INTO `items`
  (`item_id`, `code`, `name`, `item_type`,
   `base_buy_price`, `base_sell_price`,
   `icon`, `shop_row_icon`, `work_sprite`, `is_active`)
VALUES
  -- Given by Bone & Brine; not sold in general store
  (93, 'hauntling_pepper_seed', 'Hauntling Pepper Seeds', 'seed',
   0, 0,
   'assets/icons/seed-hauntling_pepper.png', 'assets/icons/seed-hauntling_pepper.png', NULL, 1),

  -- Produce; used for Murkfen barter and minor shop/market sales
  (94, 'hauntling_pepper', 'Hauntling Pepper', 'produce',
   0, 12,
   'assets/icons/item-hauntling_pepper.png', 'assets/icons/item-hauntling_pepper.png', NULL, 1);

-- -------------------------------------------------------
-- 2. Plant
-- -------------------------------------------------------
-- Farm garden, 4 growth cycles, slightly darker crop.
INSERT IGNORE INTO `plants`
  (`plant_id`, `code`, `name`,
   `allowed_garden_type_code`, `allowed_garden_types_json`,
   `seed_item_id`, `harvest_item_id`,
   `width`, `height`, `max_cycles`, `cycle_hour`, `cycle_length_hours`,
   `water_max`, `water_required`, `water_drain_per_game_hour`,
   `harvest_min`, `harvest_max`, `unlock_cost`, `is_active`)
VALUES
  (13, 'hauntling_pepper', 'Hauntling Pepper',
   'farm', '[1]',
   93, 94,
   1, 1, 4, 6, 24,
   100, 25, 3,
   1, 3, 0, 1);

-- -------------------------------------------------------
-- 3. Shop buy limit for Hauntling Pepper
--    (small daily quota — primary use is Murkfen barter)
-- -------------------------------------------------------
INSERT IGNORE INTO `shop_buy_limits`
  (`shop_buy_limit_id`, `item_id`, `daily_limit`, `is_basic`, `is_active`)
VALUES
  (4, 94, 4, 0, 1);

-- -------------------------------------------------------
-- 4. Bone & Brine event — Step 3: Bone gives hauntling seeds
-- -------------------------------------------------------
INSERT IGNORE INTO `event_steps`
  (`step_id`, `event_id`, `step_order`, `speaker_name`, `title`,
   `body_html`, `button_text`,
   `background_image`, `portrait_image`, `effects_json`)
VALUES
(18, 4, 3, 'Bone', 'One More Thing',
'<p>Bone rummages beneath the counter with the quiet urgency of someone who has misplaced something important and would prefer you not notice.</p>
<p>He surfaces with a small cloth packet tied shut with what appears to be seaweed.</p>
<p>"These are hauntling pepper seeds." He sets them on the counter with the reluctance of someone parting with something they are not entirely sure they should be parting with. "They grow in ordinary soil. More than can be said for most things from that direction."</p>
<p>He pushes them toward you.</p>
<p>"Someone will find out you have them eventually and come looking." He points one narrow finger at you. "That is not our doing. We are simply merchants. We neither confirm nor deny anything about pepper provenance."</p>
<p>Behind him, Brine waves cheerfully from behind a jar of something that appears to have opinions.</p>',
'I''ll plant them.',
NULL, NULL,
'{"inventory": [{"code": "hauntling_pepper_seed", "qty": 3}], "flags": {"hauntling_seeds_received": true}}');

-- -------------------------------------------------------
-- 5. Murkfen intro event
-- -------------------------------------------------------
INSERT IGNORE INTO `events`
  (`event_id`, `event_key`, `title`, `description`, `is_active`)
VALUES
  (6, 'murkfen_intro',
   'Something in the Fog',
   'Murkfen introduces himself and establishes his barter arrangement.',
   1);

INSERT IGNORE INTO `event_steps`
  (`step_id`, `event_id`, `step_order`, `speaker_name`, `title`,
   `body_html`, `button_text`,
   `background_image`, `portrait_image`, `effects_json`)
VALUES

(19, 6, 1, NULL, 'Something in the Fog',
'<p>The path to Murkfen is not on any map you have seen. It is the kind of place that appears when you already know to look for it, and disappears the moment you stop paying attention.</p>
<p>What you find is less of a shop and more of a collection of intentions — shelves of things that hum faintly, bundles of things that were probably herbs at some point, and jars of things that are definitely still alive.</p>
<p>At the center of it all is a figure that is short, still, and watching you with eyes like polished river stones.</p>
<p>"You grew them," they say.</p>
<p>Not a question.</p>',
'The hauntling peppers?',
NULL, NULL, NULL),

(20, 6, 2, 'Murkfen', 'An Understanding',
'<p>"I have been here longer than the caravan. Longer than the market. Longer than most of what currently calls itself a town."</p>
<p>Murkfen moves between the shelves the way water moves through roots — purposeful, unhurried, aware of every available path.</p>
<p>"I make things. Useful things. Things that address certain problems, certain conditions, certain situations that seeds and coins do not cover." A pause. "Elixirs. Tonics. Improvements."</p>
<p>They stop and look at you directly for the first time since you arrived, with the measured attention of someone calculating whether an investment is worth making.</p>
<p>"I do not deal in money. I never have. I trade ingredient for result. You bring what I need. I make what you want."</p>
<p>The fog thickens slightly, the way it does when a conversation is considered finished.</p>
<p>"The peppers are an acceptable beginning. Come back when you have more — or when you need something."</p>',
'Understood.',
NULL, NULL,
'{"flags": {"murkfen_met": true}, "unlock_location": "murkfen"}');

-- -------------------------------------------------------
-- 6. Event trigger for Murkfen intro
-- -------------------------------------------------------
INSERT IGNORE INTO `event_triggers`
  (`trigger_id`, `event_id`, `trigger_key`, `trigger_type`,
   `location_key`, `ui_tooltip`,
   `trigger_conditions_json`, `schedule_json`,
   `priority`, `is_repeatable`, `is_active`)
VALUES
  (6, 6, 'murkfen_hauntling_visit', 'location',
   'murkfen', 'Something stirs in the fog.',
   '{"flags": {"bone_brine_caravan_seen": true, "hauntling_pepper_planted": true, "murkfen_met": false}}', NULL,
   10, 0, 1);

-- -------------------------------------------------------
-- Done.
-- -------------------------------------------------------
UPDATE `game_config` SET `app_version` = 'v0.4.40' WHERE `config_id` = 1;
