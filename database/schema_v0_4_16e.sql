-- Fantasy Farmer v0.4.16e
-- Run after v0.4.16d.
-- Hotfix: database version bump and Farmer's Market visibility rollback.

UPDATE game_config
SET app_version = 'v0.4.16e';

-- Remove accidental market unlocks from reputation/config scaffolding.
-- Legitimate event unlocks use source='event_effect' and are preserved.
DELETE FROM player_unlocks
WHERE unlock_key = 'location_market'
  AND source <> 'event_effect';
