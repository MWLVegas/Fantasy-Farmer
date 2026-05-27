-- Fantasy Farmer v0.4.4
-- Database-driven story event engine.

CREATE TABLE IF NOT EXISTS events (
  event_id INT AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(100) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  description TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_steps (
  step_id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  step_order INT NOT NULL,
  speaker_name VARCHAR(100) DEFAULT NULL,
  title VARCHAR(180) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  button_text VARCHAR(80) NOT NULL DEFAULT 'Okay',
  background_image VARCHAR(255) DEFAULT NULL,
  portrait_image VARCHAR(255) DEFAULT NULL,
  effects_json JSON DEFAULT NULL,
  UNIQUE KEY uniq_event_step_order (event_id, step_order),
  FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_triggers (
  trigger_id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  trigger_key VARCHAR(120) NOT NULL UNIQUE,
  trigger_type ENUM('manual','scheduled','random','flag_check') NOT NULL DEFAULT 'manual',
  trigger_conditions_json JSON DEFAULT NULL,
  schedule_json JSON DEFAULT NULL,
  priority INT NOT NULL DEFAULT 100,
  is_repeatable TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_event_state (
  player_event_state_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_id INT NOT NULL,
  current_step_order INT NOT NULL DEFAULT 1,
  status ENUM('pending','active','complete','cancelled') NOT NULL DEFAULT 'active',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_user_event_open (user_id, event_id, status),
  INDEX idx_user_status (user_id, status),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO events (event_key, title, description, is_active) VALUES
('madam_rune_intro', 'A Caravan Arrives', 'Madam Rune introduces herself and trades for the first relic.', 1),
('fairy_bell_summon', 'The Fairy Bell Rings', 'The first fairy answers the bell and becomes a water helper.', 1)
ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description), is_active=VALUES(is_active);

DELETE es FROM event_steps es JOIN events e ON e.event_id = es.event_id WHERE e.event_key IN ('madam_rune_intro','fairy_bell_summon');

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, 'Madam Rune', 'A Caravan Arrives',
'<p>Around midday, the quiet of your garden is interrupted by the creak of old wheels and the soft clatter of hanging charms.</p><p>A peculiar wagon rolls to a stop nearby, draped in moss, trailing vines, and enough dangling trinkets to worry any sensible horse.</p><p>From within emerges a goblin woman wrapped in layered fabrics, jangling jewelry, and a confidence usually reserved for people who know what they are doing.</p><p>“The hands of Fate have brought a visitor! ... or was it the <em>hams</em> of Fate?”</p><p>She squints at you, then gasps.</p><p>“Someone comes bearing <strong>DESTINY!</strong> ... and perhaps coupons!”</p><p>She pats her pockets, frowns, then looks suddenly delighted.</p><p>“Ah! Yes. Introductions. My name is... Sister Sa— no, no, that was Tuesday. Lady Cri— wait. What day even <em>is</em> it?”</p><p>She throws both hands into the air.</p><p>“Oh! Of course. <strong>I am Madam Rune!</strong>”</p>',
'... okay?', NULL
FROM events WHERE event_key='madam_rune_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 2, 'Madam Rune', 'Madam Rune Peers at the Relic',
'<p>Madam Rune leans in toward the relic, her eyes widening until you begin to worry they might simply leave her head.</p><p>“Ohhh. Oh, that is <em>old</em>. Old Empire, unless I am mistaken. And I am only mistaken on Wednesdays, in matters of soup, and once about a goose.”</p><p>She taps the relic with one long fingernail.</p><p>“A vessel, you see. It once held <strong>aetherglimmer</strong> — or perhaps <strong>thrumlight</strong>. No, wait. Aetherglimmer. Definitely aetherglimmer. Probably.”</p><p>She presses it to her ear and smiles.</p><p>“Empty now, of course. But it still hums. Beautifully useless. My favorite kind of important.”</p><p>She rummages through her robes and produces three similar relics, each wrapped in bits of cloth and string.</p><p>“I have three others. Each one hums at a different pitch. But with yours? Ah! A quartet! A divine little quartet of forgotten imperial nonsense!”</p><p>Then she holds up an old bell.</p><p>“I tried using this thing, but it is far too high-pitched, and the fae would not leave it alone. Tiny winged busybodies. Always listening. Always curious. Always asking if mushrooms count as chairs.”</p><p>She places the bell in your hand, then adds a damp-looking crystal beside it.</p><p>“And this! An Aqua Amulet. It has the power to make <strong>anything wet</strong>. Simply place it upon the item you wish to moisten, pour water over it, and behold! Moisture!”</p><p>She nods gravely, as if she has just explained fire.</p>',
'... um ... okay?', NULL
FROM events WHERE event_key='madam_rune_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 3, 'Madam Rune', 'The Relic Finds Its Place',
'<p>Madam Rune carefully takes the relic and carries it to her wagon, where three similarly shaped objects rest on a velvet cloth.</p><p>She sets yours beside them.</p><p>Then moves it slightly.</p><p>Then slightly back.</p><p>Then forward by what cannot possibly be more than a hair’s width.</p><p>Several minutes pass.</p><p>At last, she clasps her hands together and beams.</p><p>“THERE! Beautiful! That sound is... the most clarity-inducing noise I have encountered in all my centuries!”</p><p>You listen closely.</p><p>You hear absolutely nothing.</p><p>Madam Rune, wearing a smile that seems to go on for days, sweeps back into her caravan. The door slams shut, the wheels creak, and the whole thing begins to roll away.</p><p>As she disappears down the road, she hollers back:</p><p>“If you find any more, save them for me! I’ll have a whole choir soon, just like the prophecy foretold!”</p><p>More confused than ever, you stow the bell and the moist rock, then turn back toward your garden.</p>',
'Okay.', JSON_OBJECT('inventory', JSON_ARRAY(JSON_OBJECT('code','relic_first_oddity','qty',-1), JSON_OBJECT('code','fairy_bell','qty',1), JSON_OBJECT('code','aqua_amulet','qty',1)), 'flags', JSON_OBJECT('madam_rune_intro_seen', true, 'madam_rune_met', true, 'madam_rune_unlocked', true, 'location_caravan', true, 'caravan_system_unlocked', true, 'aqua_amulet_obtained', true), 'recognition', 1, 'relic_trade', 'madam_rune')
FROM events WHERE event_key='madam_rune_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, NULL, 'Ring the Fairy Bell?',
'<p>The little bell gives off a bright, impossibly clear chime even before you ring it.</p><p>Madam Rune did say the fae would not leave it alone.</p>',
'Ring the Bell', NULL
FROM events WHERE event_key='fairy_bell_summon';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 2, 'A Very Curious Fairy', 'A Tiny Visitor Appears',
'<p>The bell rings once.</p><p>For a moment, nothing happens.</p><p>Then something tiny, bright, and deeply nosy zips out from behind a leaf.</p><p>“Was that a fairy bell? It sounded like a fairy bell. I love fairy bells. Terrible for concentration. Excellent for investigating.”</p><p>She circles your garden, eyes wide.</p><p>“Is this human gardening? I’ve heard about this! You put plants in the dirt on purpose and then become emotionally attached to them?”</p><p>Her gaze lands on the Aqua Amulet.</p><p>“Oh! Is that water magic? You humans use water on plants, don’t you? I can do that. Probably. Almost certainly. Stand back.”</p>',
'Okay.', JSON_OBJECT('inventory', JSON_ARRAY(JSON_OBJECT('code','fairy_bell','qty',-1)), 'flags', JSON_OBJECT('fairy_bell_consumed', true, 'helpers_unlocked', true, 'first_fairy_summoned', true), 'recognition', 1, 'summon_helper', JSON_OBJECT('helper_type','fairy','name','Puddlewink','equipment','aqua_amulet','task','water'), 'unlock_location','forest_folk')
FROM events WHERE event_key='fairy_bell_summon';

INSERT INTO event_triggers (event_id, trigger_key, trigger_type, trigger_conditions_json, schedule_json, priority, is_repeatable, is_active)
SELECT event_id, 'madam_rune_noon_after_first_relic', 'scheduled', JSON_OBJECT('flags', JSON_OBJECT('first_relic_collected', true, 'madam_rune_intro_seen', false)), JSON_OBJECT('player_state_datetime','madam_rune_visit_at'), 10, 0, 1
FROM events WHERE event_key='madam_rune_intro'
ON DUPLICATE KEY UPDATE trigger_conditions_json=VALUES(trigger_conditions_json), schedule_json=VALUES(schedule_json), priority=VALUES(priority), is_active=VALUES(is_active);

INSERT INTO event_triggers (event_id, trigger_key, trigger_type, trigger_conditions_json, schedule_json, priority, is_repeatable, is_active)
SELECT event_id, 'manual_use_fairy_bell', 'manual', JSON_OBJECT('inventory_has', JSON_OBJECT('fairy_bell', 1), 'flags', JSON_OBJECT('helpers_unlocked', false)), NULL, 10, 0, 1
FROM events WHERE event_key='fairy_bell_summon'
ON DUPLICATE KEY UPDATE trigger_conditions_json=VALUES(trigger_conditions_json), priority=VALUES(priority), is_active=VALUES(is_active);
