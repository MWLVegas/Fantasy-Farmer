-- Fantasy Farmer v0.4.16q
-- Locked map locations should keep their configured icon and render blacked out.

UPDATE game_config
SET app_version = 'v0.4.16q';

UPDATE map_location_config
SET map_icon = CASE location_key
  WHEN 'orders' THEN 'assets/map/orders.png'
  WHEN 'helpers' THEN 'assets/map/fairy_folk.png'
  WHEN 'forest_folk' THEN 'assets/map/fairy_folk.png'
  WHEN 'shop' THEN 'assets/map/store.png'
  WHEN 'store' THEN 'assets/map/store.png'
  WHEN 'general_store' THEN 'assets/map/store.png'
  WHEN 'garden' THEN 'assets/map/garden.png'
  WHEN 'shed' THEN 'assets/map/shed.png'
  WHEN 'bone_brine' THEN 'assets/map/bone_brine.png'
  WHEN 'market' THEN 'assets/map/market.png'
  WHEN 'fae_market' THEN 'assets/map/market.png'
  WHEN 'caravan' THEN COALESCE(NULLIF(inactive_map_icon, ''), 'assets/map/caravan_empty.png')
  ELSE map_icon
END
WHERE location_key IN (
  'orders','helpers','forest_folk','shop','store','general_store','garden','shed',
  'bone_brine','market','fae_market','caravan'
);
