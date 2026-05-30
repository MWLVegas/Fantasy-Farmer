-- Fantasy Farmer v0.4.28
-- Numeric version bump; fixes map location rendering fallback so locked locations
-- use configured image art instead of emoji/question-mark placeholders.

UPDATE game_config
SET app_version = 'v0.4.28';

-- No map_location_config icon rewrite is required here.
-- Existing configured map_icon values are the source of truth.
-- The code fix is in getLocationsForPlayer() and the client map renderer.
