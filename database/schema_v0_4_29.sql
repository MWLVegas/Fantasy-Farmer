-- Fantasy Farmer v0.4.29
-- Fae Market guest cycling, cozy background fades, closed-market background handling,
-- and General Store sell-row UI polish.

UPDATE game_config
SET app_version = 'v0.4.29',
    fae_market_wanderer_image_count = GREATEST(COALESCE(fae_market_wanderer_image_count, 18), 18);
