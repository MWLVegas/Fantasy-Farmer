-- Fantasy Farmer v0.4.33
-- Surgical patch only: no image assets are included or overwritten by this patch.
-- Expected starting point: v0.4.32.

ALTER TABLE garden_types
  CHANGE COLUMN background_image day_background_image VARCHAR(255) DEFAULT NULL;

ALTER TABLE garden_types
  ADD COLUMN night_background_image VARCHAR(255) DEFAULT NULL AFTER day_background_image;

UPDATE garden_types
SET day_background_image = COALESCE(day_background_image, CONCAT('assets/gardens/garden_day_', code, '.png')),
    night_background_image = COALESCE(night_background_image, CONCAT('assets/gardens/garden_night_', code, '.png'));

UPDATE garden_types
SET day_background_image = CONCAT('assets/gardens/garden_day_', code, '.png')
WHERE day_background_image = 'assets/map/garden.png' OR day_background_image = '';

ALTER TABLE items
  ADD COLUMN shop_row_icon VARCHAR(255) DEFAULT NULL AFTER icon;

UPDATE items
SET shop_row_icon = COALESCE(shop_row_icon, icon)
WHERE item_type IN ('seed','produce','processed','material','fertilizer','fuel');

UPDATE game_config
SET app_version = 'v0.4.33',
    map_title = '',
    map_day_background_image = COALESCE(map_day_background_image, 'assets/map/map_day.png'),
    map_night_background_image = COALESCE(map_night_background_image, 'assets/map/map_night.png');
