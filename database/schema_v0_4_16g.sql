-- Fantasy Farmer v0.4.16g
-- Fix newly-planted crops jumping growth stages when planted after their cycle hour.

UPDATE game_config
SET app_version = 'v0.4.16g';

-- Safety: any active crop that has not yet had its growth-cycle baseline initialized
-- should start counting from the cycle it was planted in, not from cycle -1.
UPDATE planted_crops pc
JOIN plants p ON p.plant_id = pc.plant_id
JOIN player_state ps ON ps.user_id = pc.user_id
JOIN game_config gc ON 1 = 1
SET pc.last_cycle_index = FLOOR((((TIMESTAMPDIFF(SECOND, ps.started_at, pc.planted_at) / gc.day_length_seconds) * 24) - p.cycle_hour) / 24)
WHERE pc.is_harvested = 0
  AND pc.last_cycle_index = -1;
