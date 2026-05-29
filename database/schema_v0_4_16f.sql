-- Fantasy Farmer v0.4.16f
-- Run after v0.4.16e.
-- Adds the multi-step Fae Market invite, market-open gating, shop daily sell limits, and golden quest marker defaults.

UPDATE game_config
SET app_version = 'v0.4.16f';

UPDATE items
SET icon = '!'
WHERE code = 'quest_available'
  AND item_type = 'system';

CREATE TABLE IF NOT EXISTS shop_buy_limits (
  shop_buy_limit_id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL UNIQUE,
  daily_limit INT NOT NULL DEFAULT 10,
  is_basic TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_shop_sales (
  player_shop_sale_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  shop_day INT NOT NULL,
  quantity_sold INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_user_item_day (user_id, item_id, shop_day),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO shop_buy_limits (item_id, daily_limit, is_basic, is_active) VALUES
((SELECT item_id FROM items WHERE code='carrot'), 12, 1, 1),
((SELECT item_id FROM items WHERE code='strawberry'), 8, 1, 1)
ON DUPLICATE KEY UPDATE
  daily_limit = VALUES(daily_limit),
  is_basic = VALUES(is_basic),
  is_active = VALUES(is_active);

INSERT INTO events (event_key, title, description, is_active) VALUES
('market_shopkeeper_invite', 'Fae Market Invitation', 'The General Store shopkeepers formally invite the player to the weekend Fae Market.', 1)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  description = VALUES(description),
  is_active = VALUES(is_active);

DELETE es
FROM event_steps es
JOIN events e ON e.event_id = es.event_id
WHERE e.event_key = 'market_shopkeeper_invite';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, 'Shopkeepers', 'A Quiet Invitation',
'<p>The General Store feels warmer than usual today, as if the room has been holding its breath and trying very hard not to look suspicious.</p><p>Behind the counter, the kind old woman glances toward you and gives the old man beside her a tiny nod. <em>That one?</em></p><p>The old man studies you for a moment, then nods back. <em>Yep. That one.</em></p><p>The woman folds her hands over the counter and smiles.</p><p>“Tell me, farmer. Have you ever heard of the <strong>Fae Market</strong>?”</p>',
'The what?', NULL
FROM events
WHERE event_key = 'market_shopkeeper_invite';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 2, 'Shopkeeper', 'Not Exactly Normal',
'<p>“Right. So.” She lowers her voice, though the old man is already pretending very loudly to organize seed packets.</p><p>“You have met <strong>Puddlewink</strong>, which means you have probably noticed our town is not exactly... normal.”</p><p>She gives a sheepish little smile.</p><p>“When you first arrived, we were not sure whether we would need to send you politely on your way. Outsiders can be tricky. They ask questions. They touch glowing mushrooms. They try to pay fairies in button lint.”</p><p>“But Puddlewink assured us you were good-hearted. And you have already helped the townsfolk more than most people manage in a season.”</p><p>For one brief moment, her shoes lift from the floor. A pair of vibrant gossamer wings flicker behind her shoulders, bright as dew in sunrise. Then she settles back down, and the wings vanish as if they had only been a trick of the light.</p><p>“So we would like to formally invite you to visit our stall at the Fae Market this weekend.”</p>',
'You have wings?', NULL
FROM events
WHERE event_key = 'market_shopkeeper_invite';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 3, 'Shopkeeper', 'The Fae Market',
'<p>The old man chuckles. “Most folk around here have a surprise or two tucked away.”</p><p>The woman gives him a look, then turns back to you.</p><p>“The Fae Market opens every weekend from <strong>7:00 to 18:00</strong>. Our little store buys only the basics, and only so much in a day. The market is different.”</p><p>“Bring any crop you like. Market folk always have a use for good produce, and they will buy as much as you can carry.”</p><p>Her smile turns bright and conspiratorial.</p><p>“Stop by when the gates are open. It may open your eyes to a much wider world.”</p>',
'I’ll visit this weekend.', JSON_OBJECT('unlock_location','market')
FROM events
WHERE event_key = 'market_shopkeeper_invite';
