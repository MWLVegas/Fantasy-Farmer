-- Fantasy Farmer v0.4.2
-- Order pacing, tool replacement, and database cleanup for the v0.4 location/order pivot.
-- Safe to run over v0.4.1.

-- Add player-level order pacing so the board does not instantly refill every empty slot.
SET @sql := IF(
  (SELECT COUNT(*)
   FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'player_state'
     AND COLUMN_NAME = 'next_order_at') = 0,
  'ALTER TABLE player_state ADD COLUMN next_order_at DATETIME DEFAULT NULL AFTER next_shop_refresh_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE player_state ps
SET ps.next_order_at = DATE_ADD(NOW(), INTERVAL FLOOR(3+RAND()*3) MINUTE)
WHERE ps.next_order_at IS NULL
  AND (
    SELECT COUNT(*)
    FROM player_orders po
    WHERE po.user_id = ps.user_id
      AND po.is_fulfilled = 0
      AND po.is_expired = 0
  ) < CASE
    WHEN ps.reputation >= 25 THEN 4
    WHEN ps.reputation >= 10 THEN 3
    ELSE 2
  END;

-- Keep only the best owned tool for each user/tool type. Upgrades replace old tools now.
CREATE TEMPORARY TABLE tmp_best_player_tools AS
SELECT pt.user_id, t.tool_type, MAX(t.level) AS best_level
FROM player_tools pt
JOIN tools t ON t.tool_id = pt.tool_id
GROUP BY pt.user_id, t.tool_type;

DELETE pt
FROM player_tools pt
JOIN tools t ON t.tool_id = pt.tool_id
JOIN tmp_best_player_tools best
  ON best.user_id = pt.user_id
 AND best.tool_type = t.tool_type
WHERE t.level < best.best_level;

DROP TEMPORARY TABLE IF EXISTS tmp_best_player_tools;

-- Remove any accidental duplicate player_tool rows for the exact same tool.
CREATE TEMPORARY TABLE tmp_keep_player_tools AS
SELECT MIN(player_tool_id) AS keep_id
FROM player_tools
GROUP BY user_id, tool_id;

DELETE pt
FROM player_tools pt
LEFT JOIN tmp_keep_player_tools keepers ON keepers.keep_id = pt.player_tool_id
WHERE keepers.keep_id IS NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_keep_player_tools;
