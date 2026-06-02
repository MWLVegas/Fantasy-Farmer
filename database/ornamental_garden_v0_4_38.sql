-- Fantasy Farmer v0.4.38 — Ornamental Garden & Granny Briar
-- Run against farmer_game database.
-- Safe to run multiple times (uses INSERT IGNORE / IF NOT EXISTS patterns where possible).

-- -------------------------------------------------------
-- 1. Add cycle_length_hours to plants
-- -------------------------------------------------------
ALTER TABLE `plants`
  ADD COLUMN `cycle_length_hours` int NOT NULL DEFAULT 24 AFTER `cycle_hour`;

-- -------------------------------------------------------
-- 2. Add is_type_locked to gardens
--    0 = garden type can be changed when empty
--    1 = garden type is fixed (e.g. the Ornamental Garden)
-- -------------------------------------------------------
ALTER TABLE `gardens`
  ADD COLUMN `is_type_locked` tinyint(1) NOT NULL DEFAULT 0 AFTER `name`;

-- -------------------------------------------------------
-- 3. Ornamental Garden type
-- -------------------------------------------------------
INSERT IGNORE INTO `garden_types`
  (`garden_type_id`, `code`, `name`, `description`, `icon`,
   `day_background_image`, `night_background_image`,
   `weed_icons_json`, `pest_icons_json`, `unlock_cost`, `is_active`)
VALUES
  (9, 'ornamental', 'Ornamental Garden',
   'A quiet, cultivated bed for flowers and decorative plants. Fae folk find these irresistible.',
   '🌸',
   'assets/gardens/garden_day_ornamental.png',
   'assets/gardens/garden_night_ornamental.png',
   '["🌿", "☘️"]', '["🐛"]',
   0, 1);

-- -------------------------------------------------------
-- 4. Items
-- -------------------------------------------------------
INSERT IGNORE INTO `items`
  (`item_id`, `code`, `name`, `item_type`,
   `base_buy_price`, `base_sell_price`,
   `icon`, `shop_row_icon`, `work_sprite`, `is_active`)
VALUES
  -- Seed/bulb given by Granny Briar; not sold in shop
  (91, 'sunflower_bulb', 'Sunflower Bulb', 'seed',
   0, 0,
   'assets/icons/seed-sunflower.png', 'assets/icons/seed-sunflower.png', NULL, 1),

  -- Harvest result; sold at shop or fae market
  (92, 'fae_tip', 'Fae Offering', 'material',
   0, 20,
   '✨', '✨', NULL, 1);

-- -------------------------------------------------------
-- 5. Sunflower plant (ornamental, weekly cycles)
-- -------------------------------------------------------
-- max_cycles=2 means two full in-game weeks before first bloom.
-- cycle_length_hours=168 = 7 game-days (1 game-week per cycle).
-- Low water drain so it doesn't punish infrequent play.
INSERT IGNORE INTO `plants`
  (`plant_id`, `code`, `name`,
   `allowed_garden_type_code`, `allowed_garden_types_json`,
   `seed_item_id`, `harvest_item_id`,
   `width`, `height`, `max_cycles`, `cycle_hour`, `cycle_length_hours`,
   `water_max`, `water_required`, `water_drain_per_game_hour`,
   `harvest_min`, `harvest_max`, `unlock_cost`, `is_active`)
VALUES
  (12, 'sunflower', 'Sunflower',
   'ornamental', '[9]',
   91, 92,
   1, 1, 2, 6, 168,
   80, 10, 1,
   3, 5, 0, 1);

-- -------------------------------------------------------
-- 6. Shop buy limit for Fae Offering (small daily quota)
-- -------------------------------------------------------
INSERT IGNORE INTO `shop_buy_limits`
  (`shop_buy_limit_id`, `item_id`, `daily_limit`, `is_basic`, `is_active`)
VALUES
  (3, 92, 5, 0, 1);

-- Fae market can also purchase it (available both phases)
INSERT IGNORE INTO `fae_market_inventory`
  (`fae_market_inventory_id`, `item_id`, `bundle_quantity`, `market_price`,
   `market_phase`, `stock_mode`, `daily_limit`, `is_active`, `sort_order`)
VALUES
  (8, 92, 1, NULL, 'both', 'infinite', NULL, 0, 200);
-- Note: is_active=0 initially; enable once player unlocks ornamental garden

-- -------------------------------------------------------
-- 7. Granny Briar intro event
-- -------------------------------------------------------
INSERT IGNORE INTO `events`
  (`event_id`, `event_key`, `title`, `description`, `is_active`)
VALUES
  (5, 'granny_briar_ornamental_intro',
   'A Garden Worth Admiring',
   'Granny Briar visits the farm and introduces the ornamental garden.',
   1);

-- -------------------------------------------------------
-- 8. Event steps
-- -------------------------------------------------------
INSERT IGNORE INTO `event_steps`
  (`step_id`, `event_id`, `step_order`, `speaker_name`, `title`,
   `body_html`, `button_text`,
   `background_image`, `portrait_image`, `effects_json`)
VALUES

-- Step 1 --
(13, 5, 1, 'Granny Briar', 'A Garden Worth Admiring',
'<p>You are between tasks when you notice someone standing at the edge of your field, studying everything with the patient, unhurried attention of someone who has seen a lot of gardens.</p>
<p>She is older — sturdy, weather-lined, wearing the kind of layered clothing that looks like it grew there naturally. A cloth bundle hangs from one hand.</p>
<p>"You are the one they have been talking about," she says, not unkindly. "The new farmer. I am Granny Briar. I live just past the treeline."</p>
<p>She glances at your plots.</p>
<p>"Decent work. Clean rows. The carrots look well-attended." She tilts her head at one section. "That strawberry patch is a bit crowded, but I will keep that to myself."</p>
<p>She does not keep it to herself.</p>',
'Nice to meet you.',
NULL, NULL, NULL),

-- Step 2 --
(14, 5, 2, 'Granny Briar', 'What the Fae Like',
'<p>"I hear you have met a few of the forest folk already," she says. "Good. They are friendlier than most give them credit for. Nosier, but friendlier."</p>
<p>She sets the cloth bundle down on a nearby post and folds her arms.</p>
<p>"You should know — fae folk are drawn to beauty. Not so much <em>useful</em> things. A carrot is a carrot to them. But a flower? A proper ornamental garden, well-kept and in bloom?" She makes a small, satisfied sound. "They cannot help themselves. They linger. They admire. And when fae folk linger somewhere long enough, they tend to leave things behind."</p>
<p>"Little coins. Tokens. Offerings, they call it — their way of saying <em>thank you for something lovely</em>. They do not think much of it. You will not even see them do it, half the time. You will just find a small glitter of coins near the blooms."</p>
<p>She says this the way someone explains that a window draft causes colds — as plain fact, long accepted.</p>',
'That sounds worth trying.',
NULL, NULL, NULL),

-- Step 3 --
(15, 5, 3, 'Granny Briar', 'A Sunflower Bulb',
'<p>Granny Briar picks up the bundle from the post and unwraps it to reveal three pale, papery bulbs.</p>
<p>"Sunflowers. My preferred starter — reliable, easy-tempered, and the fae go absolutely <em>mad</em> for them. Something about the way they follow the light." She hands them over.</p>
<p>"You will want a separate garden bed for ornamentals. They do not mix well with vegetables — different soil needs, different pace. Ornamentals take their time. A week or so per growth stage, usually. Patience is the whole point."</p>
<p>She glances back toward the treeline, as if she has somewhere to be, or simply prefers to keep moving.</p>
<p>"Plant them, water them, leave them be. When they bloom, check the soil nearby. You might be surprised."</p>
<p>She gives a short nod — the kind that means <em>conversation over</em> — and heads back the way she came, already looking at something in the middle distance.</p>',
'Thank you, Granny Briar.',
NULL, NULL,
'{"inventory": [{"code": "sunflower_bulb", "qty": 3}], "flags": {"granny_briar_met": true, "ornamental_garden_unlocked": true}, "create_garden": {"garden_type_code": "ornamental", "name": "Ornamental Garden", "locked": true, "unlock_plots": 4}}');

-- -------------------------------------------------------
-- Done. Bump app version.
-- -------------------------------------------------------
UPDATE `game_config` SET `app_version` = 'v0.4.38' WHERE `config_id` = 1;
