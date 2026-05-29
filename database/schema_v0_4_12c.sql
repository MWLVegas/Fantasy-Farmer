-- Fantasy Farmer v0.4.12c
-- Run after v0.4.12b.
-- Forest Folk worker behavior, accessory visibility, order completion guard, crisp canvas text, and expanded Puddlewink intro.

UPDATE game_config
SET app_version = 'v0.4.12c';

SET @sql := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE player_helpers ADD COLUMN last_action_at DATETIME DEFAULT NULL',
  'SELECT 1'
) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'player_helpers' AND COLUMN_NAME = 'last_action_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Only one harvest accessory should exist in the active set.
UPDATE helper_equipment SET is_active = 0 WHERE code = 'harvest_charm';
UPDATE items SET is_active = 0 WHERE code = 'harvest_charm';

-- Remove in-game copy that talked about future implementation instead of actual in-world rules.
UPDATE helper_equipment
SET description = CASE code
  WHEN 'aqua_amulet' THEN 'Lets a helper water crops with small splashes of magic.'
  WHEN 'root_charm' THEN 'Lets a helper slowly till unlocked soil.'
  WHEN 'seed_satchel' THEN 'Lets a helper plant available seeds into ready soil.'
  WHEN 'harvest_basket' THEN 'Lets a helper gather ready crops carefully, but less efficiently than you.'
  WHEN 'market_pouch' THEN 'Lets a helper carry produce to market.'
  WHEN 'order_stamp' THEN 'Lets a helper help with order paperwork.'
  ELSE description
END
WHERE code IN ('aqua_amulet','root_charm','seed_satchel','harvest_basket','market_pouch','order_stamp');

-- Puddlewink step 2: introduction only. No unlock/effects yet.
UPDATE event_steps es
JOIN events e ON e.event_id = es.event_id
SET
  es.speaker_name = 'Puddlewink',
  es.title = 'A Tiny Visitor Appears',
  es.body_html = '<p>The bell rings once.</p><p>For a moment, nothing happens.</p><p>Then something tiny, bright, and deeply nosy zips out from behind a leaf.</p><p>“Was that a fairy bell? It sounded like a fairy bell. I love fairy bells. Terrible for concentration. Excellent for investigating.”</p><p>She freezes midair, staring at your plots.</p><p>“WAIT. Is this gardening? I mean <em>human</em> gardening?!”</p><p>Her wings blur with excitement.</p><p>“That is so awesome! I have heard all about this! You throw old plant parts into the ground, get emotionally attached, and then somehow food happens, right?”</p><p>She presses both hands to her cheeks.</p><p>“I could help with that. I <em>love</em> getting emotionally attached to things. Usually they die, though.”</p><p>She winces.</p><p>“Oof. I hope that is not some sort of omen.”</p><p>She straightens proudly, as if she has just been hired by a very important turnip.</p><p>“My name is <strong>Puddlewink</strong>. I am a fairy, technically, though I prefer <em>garden consultant</em>. Give me an accessory and I can help. Without one, I mostly supervise with excellent posture.”</p>',
  es.button_text = 'Continue',
  es.effects_json = NULL
WHERE e.event_key = 'fairy_bell_summon'
  AND es.step_order = 2;

-- Puddlewink step 3: the water problem. Unlock helper at the end, but do not equip the Aqua Amulet.
INSERT INTO event_steps (
  event_id,
  step_order,
  speaker_name,
  title,
  body_html,
  button_text,
  effects_json
)
SELECT
  e.event_id,
  3,
  'Puddlewink',
  'The Water Problem',
  '<p>Puddlewink circles your plots like a tiny vulture, eyeing each crop with grave professional concern.</p><p>“This one should be named <strong>Fizzlewick</strong>. Look at him. He looks like a Fizzlewick, does he not? That weird little nose and all.”</p><p>She darts to the next plot.</p><p>“Ooh, and this one can be—”</p><p>She stops mid-sentence, hovering perfectly still.</p><p>For one shining second, it looks as though the largest thought in fairy history has just struck her directly between the eyes.</p><p>“Oh. My. Dragons.”</p><p>She spins toward you.</p><p>“I just realized. Your food needs <em>water</em>.”</p><p>Puddlewink clutches her head dramatically.</p><p>“Blast it! I used to have an old Aqua Amulet that let me perform water magic. That would be the perfect job for me. The <em>perfect</em> job. I could zip around, sprinkle everything, look extremely important—ugh, we need to find a new one!”</p><p>She immediately begins checking under rocks, leaves, roots, and one suspiciously innocent clump of dirt, as if the missing amulet might have politely waited there for several years.</p><p>“It has been a few years since I last saw it, but it is probably around here somewhere, right? That is how lost things work. Anyway, if you find it, just let me know. You know where to find me!”</p><p>Before you can ask where, exactly, that is, she zips off into the distance with the confidence of someone who assumes you have known her your entire life.</p><p>You try to call after her, but she is gone before the first sound leaves your mouth.</p><p>Slowly, you pull the water stone from your pocket and turn it over in your hand.</p><p><em>“Just add water,”</em> Madam Rune had said.</p><p>Is this what Puddlewink is looking for?</p>',
  'I’ll keep an eye out.',
  JSON_OBJECT(
    'inventory', JSON_ARRAY(JSON_OBJECT('code','fairy_bell','qty',-1)),
    'flags', JSON_OBJECT('fairy_bell_consumed', true, 'helpers_unlocked', true, 'first_fairy_summoned', true),
    'recognition', 1,
    'summon_helper', JSON_OBJECT('helper_type','fairy','name','Puddlewink','equipment','','task','idle'),
    'unlock_location','forest_folk'
  )
FROM events e
WHERE e.event_key = 'fairy_bell_summon'
ON DUPLICATE KEY UPDATE
  speaker_name = VALUES(speaker_name),
  title = VALUES(title),
  body_html = VALUES(body_html),
  button_text = VALUES(button_text),
  effects_json = VALUES(effects_json);
