-- Fantasy Farmer v0.4.39 — Fold order_items into player_orders
-- Every order has exactly one item; the separate table is unnecessary.
-- Run after fae_offering_pouch_v0_4_38c.sql.

-- Add item columns to player_orders
ALTER TABLE `player_orders`
  ADD COLUMN `item_id` int DEFAULT NULL AFTER `base_payment_coins`,
  ADD COLUMN `quantity_required` int NOT NULL DEFAULT 1 AFTER `item_id`;

-- Migrate existing data from order_items
UPDATE `player_orders` po
INNER JOIN `order_items` oi ON oi.player_order_id = po.player_order_id
SET po.item_id = oi.item_id,
    po.quantity_required = oi.quantity_required;

-- Drop the now-redundant table
DROP TABLE `order_items`;

UPDATE `game_config` SET `app_version` = 'v0.4.39' WHERE `config_id` = 1;
