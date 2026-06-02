-- Fantasy Farmer v0.4.40c — Game identity, order progression,
-- relic drops, and shop hints.

-- -------------------------------------------------------
-- 1. Game identity in game_config
-- -------------------------------------------------------
ALTER TABLE `game_config`
  ADD COLUMN `game_name`  varchar(100) NOT NULL DEFAULT 'Fairytale Farm'       AFTER `app_version`,
  ADD COLUMN `town_name`  varchar(100) NOT NULL DEFAULT 'Mossroot Hollow'      AFTER `game_name`,
  ADD COLUMN `tagline`    text                                                   AFTER `town_name`;

UPDATE `game_config` SET
  `game_name` = 'Fairytale Farm',
  `town_name` = 'Mossroot Hollow',
  `tagline`   = 'Grow crops, uncover relics, and bring magic back to Mossroot Hollow.'
WHERE `config_id` = 1;

-- -------------------------------------------------------
-- 2. Order reputation tiers on items
--    Tier 0 = always in order pool
--    Tier 1 = needs reputation >= 5
--    Tier 2 = needs reputation >= 12
--    Tier 3 = needs reputation >= 20
-- -------------------------------------------------------
ALTER TABLE `items`
  ADD COLUMN `order_tier` int NOT NULL DEFAULT 0 AFTER `is_active`;

-- Tier 0 — starter crops (carrot, onion)
UPDATE `items` SET `order_tier` = 0 WHERE `code` IN ('carrot','onion');

-- Tier 1 — early progression
UPDATE `items` SET `order_tier` = 1 WHERE `code` IN ('strawberry','bell_pepper','corn','tomato','squash');

-- Tier 2 — mid-game
UPDATE `items` SET `order_tier` = 2 WHERE `code` IN ('blueberry','pumpkin','potato','chili_pepper');

-- Tier 3 — advanced / special
UPDATE `items` SET `order_tier` = 3 WHERE `code` IN ('hauntling_pepper');

-- -------------------------------------------------------
-- 3. Relic items
-- -------------------------------------------------------
INSERT IGNORE INTO `items`
  (`item_id`, `code`, `name`, `item_type`,
   `base_buy_price`, `base_sell_price`,
   `icon`, `shop_row_icon`, `work_sprite`, `is_active`)
VALUES
  -- Common relic fragment found while hoeing / digging; sell at fae market
  (95, 'relic_fragment', 'Relic Fragment', 'relic',
   0, 35,
   'assets/icons/item-relic-fragment.png', NULL, NULL, 1),

  -- The second special relic that triggers Bone & Brine
  (96, 'relic_second_oddity', 'Strange Buried Relic', 'relic',
   0, 0,
   'assets/icons/item-strange-relic.png', NULL, NULL, 1);

-- -------------------------------------------------------
-- 4. Shop hints table
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shop_hints` (
  `hint_id`      int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `hint_text`    text NOT NULL,
  `hint_speaker` varchar(80) DEFAULT NULL,
  `sort_order`   int NOT NULL DEFAULT 0,
  `is_active`    tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `shop_hints` (`hint_text`, `hint_speaker`, `sort_order`) VALUES
('Carrot seeds never hurt anyone. We''ve sold quite a lot. The carrots mostly turn out fine.',             'Shopkeeper', 10),
('The preserve bin turns fruit into jam. Jam keeps longer than fruit and sells for more. We''re not sure if that''s magic or just chemistry.', 'Shopkeeper', 20),
('You''ll want a Land Claim Note to unlock additional garden plots. Limit of three per customer. No, we will not explain why three is the limit.',                 'Shopkeeper', 30),
('The Fae Market opens every weekend. They buy things we don''t. Weeds, bugs, things from the ground. Don''t ask.',  'Shopkeeper', 40),
('Orders from townsfolk arrive automatically. Completing them on time earns reputation. Reputation earns you more interesting orders.', 'Shopkeeper', 50),
('The wooden hoe is a fine starter tool. It was also, once, involved in something unusual. We''ve chosen not to elaborate.',          'Shopkeeper', 60),
('Seeds with a red background won''t grow in your current garden. Not a complaint. Just an observation.',    'Shopkeeper', 70),
('Some things buried in the soil aren''t seeds.',                                                            'Shopkeeper', 80),
('Strawberries take longer than carrots but fetch a higher price. Whether that''s better is a matter of patience, which varies.',     'Shopkeeper', 90),
('The caravan arrives twice a month. What they sell isn''t always what you''d expect. That''s rather the point.', 'Shopkeeper', 100);

-- -------------------------------------------------------
-- Done.
-- -------------------------------------------------------
UPDATE `game_config` SET `app_version` = 'v0.4.40c' WHERE `config_id` = 1;
