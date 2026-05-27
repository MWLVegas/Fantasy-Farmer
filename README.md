# Fantasy Farmer

## Current Version

v0.3.5

## v0.3.5

- Active/new orders are capped to 30–60 minutes.
- Existing overlong active orders are clamped automatically on state load.
- Added instant custom tooltips; removed browser title tooltip usage.
- Tool cards no longer show Power/Radius text directly; that info lives in tooltip content.
- Completing an order closes the modal and sends a coin reward animation to the coin display.
- Harvesting sends a collection animation toward Inventory.
- Pouch pickup keeps the server message visible and animates toward seed tools.
- Shed recipes are hidden unless the player owns the required equipment.
- Canvas changes scene by tab: Garden, Shop, Shed, Workers, Inventory placeholders.
- Added shop refresh timer framework, defaulting to one real-life hour.
- Added seed-hybrid database scaffolding.

Notes:
- Shop/shed/worker canvases are visual placeholders for now; the right-side menus still perform the actual clicks.
- This is a rebuild-safe RC update. Import `schema.sql` fresh if you want the new shop/hybrid tables immediately.

## v0.3.6

### Added
- Added an admin/debug panel for the configured admin user.
- Added admin action to grant 1000 coins.
- Added env-driven admin support via `ADMIN_USER`.
- Added current coins to the garden panel header.
- Added an icon-based canvas inventory/backpack view.
- Added HTML-capable custom tooltips.
- Added fallback/default browser cursor behavior when a tab/scene does not define a custom cursor.

### Changed
- Removed the Refresh button from the panel header.
- Tool Power/Radius moved into multi-line HTML tooltips.
- Tooltip positioning now flips/anchors at screen edges instead of shrinking.
- Non-garden canvas scenes now use the browser cursor instead of hiding the cursor.
- Market/shop canvas now uses a default cursor unless a specific shop cursor is later defined.
- README.md is restored as a running changelog and must not be overwritten by future releases.

### Fixed
- Fixed missing cursor on market/shop canvas scenes.
- Fixed tooltip edge behavior where tooltips became skinny at screen edges.
- Reduced server reconciliation clobbering by deferring background sync while actions are actively pending.
- Fixed inventory canvas being text-only.

## v0.3.7

### Added
- Added canvas-first shop scene with three rows:
  - common seeds
  - tools and equipment
  - today’s special placeholder
- Added clickable shop canvas items for seed and equipment purchases.
- Added shop canvas hover tooltips with HTML.
- Added order countdown beside the orders button.
- Added urgent order styling when an order has five minutes or less remaining.
- Added cancelled-order tooltip copy when the visible order timer reaches zero.

### Changed
- Inventory tab now uses regular browser cursor instead of the selected tool cursor.
- Inventory canvas now renders items at full opacity.
- Inventory side panel is hidden because the canvas is the inventory view.
- Shop side panel is hidden because the canvas is the market stall view.
- Shop item affordability is shown visually:
  - affordable items are normal
  - unaffordable items dim slightly and show red price text
- Today's Special is shown as a placeholder box for now.
- README.md continues as an append-only changelog.

### Fixed
- Fixed tool cursor leaking into the inventory canvas.
- Fixed inventory canvas items appearing oddly grayed out.

## v0.3.8

### Fixed
- Fixed a v0.3.7 JavaScript crash caused by canvas-first Shop/Inventory panels removing DOM elements that old render functions still expected.
- Added safe null checks around optional right-panel elements.
- Garden tools/seeds now render again after state loads.
- Shop/Inventory canvas scenes no longer require the old right-panel lists to exist.

### Database
- No database changes.
- No schema or migration file required.

## v0.3.9

### Fixed
- Fixed active orders showing extremely long remaining times by clamping existing active order expirations to 30–60 minutes.
- Fixed newly generated order duration handling to stay in the 30–60 minute range.
- Fixed `ordersModal` aria warning by using `inert` while closed instead of keeping focusable content inside `aria-hidden`.
- Fixed pouch hover behavior:
  - tooltip now says “Pick up pouch”
  - seed ghost/tool noise is suppressed while hovering over a pouch
  - holding/active tool actions are cancelled when hovering over a pouch
- Fixed shop canvas purchases not firing.
- Fixed duplicate browser/native tooltip behavior by removing `title` attributes and using only the custom HTML tooltip.
- Fixed duplicate custom tooltip overlap by ensuring only one tooltip system is active.
- Fixed old coin pill duplication by moving the existing coin pill into the panel header and hiding/removing the top player coin display.
- Fixed no-order tooltip copy.

### Changed
- Market tab keeps sell controls in the right panel while purchases remain on the canvas.
- Tooltips remain HTML capable and edge-flip without resizing.
- Tools now use action cooldowns client-side so early tools do not spam-click at late-game speed.

### Database
- No schema changes.
- No schema or migration file required.

## v0.3.10

### Fixed
- Fixed Orders modal close button after the inert/aria cleanup.
- Added robust modal close handling for close button, outside click, and Escape.
- Added visible shop purchase feedback on the canvas: bought items float upward as `+1`.
- Added quantity badges to Sell Goods entries.
- Added immediate sell-side quantity updates after selling one item.

### Database
- No database changes.
- No schema or migration file required.

## v0.3.11

### Fixed
- Fixed Orders modal cursor/click layering so the modal owns pointer events while open.
- Canvas cursor now falls back to the browser pointer whenever any modal is open.
- Orders button timer now renders beside the order icon.
- Orders button tooltip now updates whether an order exists or not.
- Selling goods now shows a floating coin gain animation from the panel coin display.
- Order fulfillment also shows a floating coin gain animation from the panel coin display.

### Database
- No database changes.
- No schema or migration file required.

## v0.3.12

### Fixed
- Fixed existing active orders still showing multi-hour timers by clamping bad active `expires_at` values in `api/state.php` before order data is returned.
- Added client-side timer safety cap so a bad order cannot display more than 60 minutes.
- Forced the order timer to appear on the Orders button, not only in the tooltip/modal.
- Forced modal layer above the canvas/right panel with a higher z-index and modal isolation rules.
- Disabled canvas pointer events and custom canvas cursor while a modal is open.
- Prevented canvas tool cursor drawing while the Orders modal is open.

### Database
- No schema changes.
- No schema or migration file required.

## v0.3.13

### Fixed
- Replaced the messy accumulated `farm.js` with a clean single-path version.
- Removed duplicate/legacy order modal functions that were overriding newer fixes.
- Fixed Orders modal click/cursor layering.
- Fixed Orders modal close button.
- Fixed Orders button timer display.
- Fixed order timer display so bad old rows cannot show more than 60 minutes.
- Fixed image icons being drawn as raw file paths on canvas.
- Fixed oversized image icons by constraining rendered icon sizes.
- Fixed canvas custom cursor leaking over modals.

### Changed
- Orders modal no longer uses `aria-hidden`/`inert`; it is simply removed from layout with `display:none` when closed.
- Orders use a single modal open/close path.
- The frontend now prefers server-provided `time_remaining_seconds` for order timers.
- Canvas icon drawing now supports both emoji and image paths.
- No schema changes in this emergency patch.

### Database
- No schema changes.
- No schema/migration file required.

## v0.3.14

### Fixed
- Fixed Orders modal content being rebuilt every second.
- Order modal countdown now updates only the timer text instead of replacing the whole modal DOM.
- Fulfill/close buttons are stable and no longer dodge clicks during timer updates.
- Fixed pouch pickup/status messages printing image file paths.
- Status messages now render image-path icons as small inline images.
- Tooltip/status icon rendering now follows the project rule:
  - values with a dot are image paths
  - values without a dot are printed as emoji/text

### Database
- No database changes.
- No schema or migration file required.

## v0.3.15

### Added
- Added fertilizer scaffolding.
- Added fertilizer item support to the `items.item_type` enum.
- Added system item support to the `items.item_type` enum.
- Added base fertilizer item records:
  - Speed Fertilizer
  - Hearty Fertilizer
  - Weedward Fertilizer
  - Bugbane Fertilizer
- Added `fertilizer_definitions` for future effect logic.
- Added `crop_fertilizers` for future applied fertilizer tracking.
- Added stage-zero planted soil as a system item: `garden_planted_soil`.
- Added fertilizer overlay rendering scaffolding for crops that have active fertilizer rows.

### Changed
- Icon ownership is now centralized on `items.icon`.
- `plants.seed_icon` and `plants.mature_icon` are no longer used by the app.
- Seed icons now come from the seed item.
- Mature/harvest icons now come from the harvest/produce item.
- Stage 0 crop icon now comes from the `garden_planted_soil` item.
- Sell Goods now shows item icons with quantity badges instead of text-only item rows.
- Shop seed cards now use item icons instead of plant-level duplicate icons.

### Database
- Requires `schema_v0_3_15.sql`.
- This migration uses `ALTER TABLE`, `CREATE TABLE IF NOT EXISTS`, and `INSERT ... ON DUPLICATE KEY UPDATE`.
- It does not drop content tables.
- It does not wipe users, items, plants, game config, garden types, order name parts, or seed hybrid recipes.

## v0.4.0

### Added
- Added the first architecture pass for world/location screens instead of simple tabs.
- Added an Overhead Map canvas screen with unlocked/locked location buttons.
- Added location scaffolding for:
  - Garden
  - General Store
  - Orders Board
  - Workroom / Shed
  - Farmer's Market
  - Caravan Camp
  - Bone & Brine
  - Forest Folk
- Added player progression stats:
  - Reputation: local trust, currently earned from completed orders.
  - Recognition: larger-world progress, currently earned from relic/helper milestones.
- Added `player_unlocks` for explicit story/progression flags instead of relying only on level numbers.
- Added multi-order board scaffolding. Players can now have multiple active orders.
- Added order categories:
  - rush
  - standard
  - patient
- Added reputation rewards to order completion.
- Added 30–120 minute order timers for the multi-order board.
- Added Farmer's Market unlock scaffolding at 10 reputation.
- Added relic scaffolding through `player_relics`.
- Added first-relic trigger scaffolding: the first full till with the Wooden Hoe can unearth a relic and unlock Madam Rune / caravan scaffolding.
- Added Forest Folk helper scaffolding:
  - helper species definitions
  - helper equipment definitions
  - player helper records
- Added first fairy summon scaffolding:
  - Fairy Bell is consumed by ringing it.
  - A first water fairy is summoned.
  - Aqua Amulet is granted/equipped as water-magic automation scaffolding.
- Added future helper species scaffolds:
  - Fairy
  - Brownie
  - Mushling
  - Spriggan
- Added future helper equipment scaffolds:
  - Aqua Amulet
  - Root Charm
  - Harvest Charm
  - Weedward Charm
  - Bugbane Charm
- Added Wooden/Iron versions of hoe, watering can, and shovel to the fresh schema/migration.
- Added a canvas-side Weekly Special path for the Wooden Hoe.

### Changed
- The UI now defaults to the Overhead Map as the world hub.
- The old Workers/Goblins concept has been renamed toward Forest Folk / Helpers.
- Orders are now treated as a board/list rather than a single active request.
- Order completion now awards reputation in addition to coins.
- The right-side nav now represents locations/screens rather than old-style tabs.
- Shop special/tool purchase scaffolding now supports buying tools directly.

### Scaffolded / Not Fully Implemented Yet
- Farmer's Market exists as an unlockable location, but its unique weekend inventory is not fully implemented yet.
- Caravan Camp exists as a relic-triggered location, but recurring two-week caravan visits are not fully implemented yet.
- Bone & Brine exists as a future permanent location, but its second-relic event/trade loop is not implemented yet.
- Helper automation logic is scaffolded, but helpers do not yet perform timed watering/tilling/harvesting actions.
- Fertilizer remains scaffolded from v0.3.15 and is preserved for future plot/crop effects.
- Relics generate and unlock first-caravan scaffolding, but the full relic trading economy is not implemented yet.

### Database
- Requires `schema_v0_4_0.sql` when upgrading from v0.3.15.
- Fresh rebuilds can use `schema.sql` directly.
- This release may also be safely tested with a database wipe/rebuild because current persistent content is not production-critical.
- The migration adds progression/unlock/relic/helper scaffolding and order reward columns.

## v0.4.1

- Hidden/locked map locations now render as `???` with a `?` icon so future places are not spoiled before unlock.
- Removed the countdown from the top Orders button; it now acts as an Orders Board shortcut and shows a badge count instead.
- Orders Board flow now lives on the canvas: click the Orders button to view the board, then click an order card to review/fulfill that specific order.
- Regular orders no longer display the word `standard`; rush jobs are called out with a visible `Rush Job` label and a warmer tinted card/background.
- Reduced duplicate side navigation; the map canvas is the primary travel UI, with only Back to Map retained as explicit navigation.
- Fixed garden tool/cursor bleed into non-garden locations. Leaving the garden now clears garden interaction state, hides the custom tool cursor, stops repeat tool actions, and prevents clicks from watering/tilling/using plots behind other canvases.


## v0.4.2

- Restored an Inventory button so the backpack canvas remains reachable without reintroducing the old full navigation stack.
- Orders Board button now shows `Orders: current/max` instead of a timer.
- Orders now arrive gradually every 3–5 real-life minutes when a slot is free instead of instantly refilling every slot.
- Order details now refresh item ownership counts after inventory changes, including harvests.
- Reputation and recognition now have shared icons and floating gain feedback.
- Tool upgrades now replace older tools of the same type instead of keeping broken/old versions.
- Added `schema_v0_4_2.sql` for order pacing and tool cleanup.


## v0.4.3 - First Relic and Madam Rune Intro

### Added
- First Wooden Hoe full-till now spawns a visible relic pickup on the garden canvas instead of silently unlocking caravan scaffolding.
- Added the `Strange Buried Relic` inventory item as the first story relic.
- Added a relic pickup modal with the `Take the Relic` button.
- Added Madam Rune's three-page intro event, scheduled for noon on the next in-game day after collecting the first relic.
- Madam Rune now trades the first relic for a `Fairy Bell` and `Aqua Amulet`, both added through the ghost inventory feedback.
- The Fairy Bell can now be used from inventory to summon the first water fairy.
- Added `schema_v0_4_3.sql` for relic pickup/story scheduling columns and repair of premature v0.4.0-v0.4.2 first-relic unlocks.

### Changed
- The first relic no longer immediately unlocks Madam Rune or the caravan location when tilled up; that now happens through the story event.
- Fairy summoning now consumes the actual `Fairy Bell` inventory item.

### Notes
- Later relics remain scaffolded for future generic relic drops and caravan trades.

## v0.4.4 — Story Event Engine

- Added database-backed story event scaffolding: `events`, `event_steps`, `event_triggers`, and `player_event_state`.
- Moved Madam Rune's intro/trade sequence into database event steps.
- Moved Fairy Bell summoning into a real multi-step event instead of a one-click toast/teleport.
- Added generic event advancement API so future story beats can be written as data instead of hardcoded JavaScript.
- Event step effects now support inventory changes, unlock flags, recognition changes, relic trades, location unlocks, and helper summoning.
- Added `schema_v0_4_4.sql` migration.

## v0.4.5

- SQL patch files now live in `/database/` instead of the project root.
- Renamed the database table `inventory` to `player_inventory` and updated PHP/schema references for consistency.
- Reworked the Orders Board into two sections: Confirmed Orders and Available Orders.
- Available orders now sit on the board for a short time before expiring off the board.
- Available orders are accepted manually; accepting an order starts its fulfillment timer.
- Confirmed order slots are separate from available-board capacity.
- Confirmed orders can be completed late instead of disappearing; late completion gives reduced coins and no reputation.
- Added cancel behavior for confirmed orders: canceling costs reputation down to a floor of 0.
- Added configurable order pacing and limits to `game_config`.
- Updated order detail actions: `Confirm Order`, `Complete`, and `Cancel (-1 ⭐)`.

## v0.4.6
- Cleaned up Orders Board wording and reward display.
- Available orders now show the board timer on the right instead of “Leaves board” text.
- Available orders show requested quantities only; confirmed orders show owned/required progress.
- Confirmed orders now show `Order due` language, with Rush Order callout when applicable.
- Rush orders use a database-configured +20% rush fee.
- Late rush orders lose the rush fee and then take the normal late fee from the base value.
- App version now comes from `game_config.app_version` instead of being rendered from a hardcoded index line.
- Consolidated duplicate order/modal CSS rules while keeping story modal styles intact.

## v0.4.7

- Fixed order reward tooltip markup leaking into Orders Board and Order Details text.
- Available order details now show the accepted deadline as `Deadline: X minutes` with a rush marker when applicable.
- Order board reward text now renders as plain `🪙 amount · ⭐ amount` on canvas while preserving safe tooltip behavior in modal HTML.
- Bumped database-backed app version to `v0.4.7`.


## v0.4.8

- Fixed the shared tooltip element so `.ff-tooltip.hidden` actually hides it.
- Restored fixed-position tooltip placement for canvas and DOM hover targets.
- Hardened tooltip mounting so it works whether the script loads before or after DOM ready.
- Bumped database-backed app version to v0.4.8.


## v0.4.9

- Fixed DOM icon rendering for clock icons: values containing a file extension now render as images, while plain values render as emoji/text.
- Restored the top HUD coin pill styling and kept stat/coin pills consolidated instead of adding duplicate CSS layers.
- Added map background scaffolding through `game_config.map_background_image`.
- Added map button coordinate scaffolding through `game_config.map_button_positions_json` so future illustrated maps can place location buttons by pixel position.
- Bumped database-backed app version to v0.4.9.
