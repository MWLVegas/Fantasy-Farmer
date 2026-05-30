-- Fantasy Farmer v0.4.16p
-- Store secret-night gating, caravan schedule/icon scaffolding, and Bone & Brine caravan event hook.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'active_map_icon') = 0,
  'ALTER TABLE map_location_config ADD COLUMN active_map_icon VARCHAR(255) NULL AFTER map_icon',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'map_location_config' AND COLUMN_NAME = 'inactive_map_icon') = 0,
  'ALTER TABLE map_location_config ADD COLUMN inactive_map_icon VARCHAR(255) NULL AFTER active_map_icon',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'game_config' AND COLUMN_NAME = 'caravan_biweekly_offset') = 0,
  'ALTER TABLE game_config ADD COLUMN caravan_biweekly_offset INT NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE game_config
SET app_version = 'v0.4.16p',
    caravan_biweekly_offset = COALESCE(caravan_biweekly_offset, 0);

UPDATE map_location_config
SET inactive_map_icon = 'assets/map/caravan_empty.png',
    active_map_icon = 'assets/map/caravan_full.png',
    map_icon = COALESCE(NULLIF(map_icon, ''), 'assets/map/caravan_empty.png')
WHERE location_key IN ('caravan', 'caravan_camp');

-- The caravan camp is now a scheduled town location. Existing users get the camp marker.
INSERT IGNORE INTO player_unlocks (user_id, unlock_key, source)
SELECT user_id, 'location_caravan', 'v0.4.16p'
FROM users;

-- Bone & Brine appears as a scripted caravan after Strange Relic #2 is found.
INSERT INTO events (event_key, title, description, is_active) VALUES
('bone_brine_caravan_intro', 'A Questionable Caravan', 'Bone & Brine arrives at the caravan camp after the second strange relic is found.', 1)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  description = VALUES(description),
  is_active = VALUES(is_active);

DELETE es
FROM event_steps es
JOIN events e ON e.event_id = es.event_id
WHERE e.event_key = 'bone_brine_caravan_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 1, 'Bone', 'A Wagon of Questionable Origins',
'<p>A crooked wagon has rolled into the caravan camp, its wheels wrapped in salt-stiff rope and its lanterns glowing with a pale green flame.</p><p>A grumpy little figure leans over the counter and squints at the relic in your hands.</p><p>“Well. That explains the smell of old magic.”</p>',
'Continue', NULL
FROM events
WHERE event_key = 'bone_brine_caravan_intro';

INSERT INTO event_steps (event_id, step_order, speaker_name, title, body_html, button_text, effects_json)
SELECT event_id, 2, 'Brine', 'An Invitation, Probably Legal',
'<p>A cheerful sea-witch pops up beside him, smiling far too brightly for someone surrounded by suspicious jars.</p><p>“Do not mind Bone. He gets grumpy when destiny arrives before breakfast.”</p><p>She taps the side of the wagon, and a small brass sign unfolds itself with a delighted little clack.</p><p>“Bone & Brine Relics is officially open to you. Rare relics, questionable origins, excellent taste.”</p>',
'Open Bone & Brine', JSON_OBJECT('unlock_location','bone_brine','flags',JSON_OBJECT('bone_brine_caravan_seen',true))
FROM events
WHERE event_key = 'bone_brine_caravan_intro';
