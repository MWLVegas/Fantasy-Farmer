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

## v0.4.11

- Added readable per-location map marker config rows in `map_location_config`.
- Map markers now support pixel `map_x` / `map_y` coordinates and per-location PNG marker icons.
- Current marker paths use `/assets/map/`: `caravan_empty`, `fairy_folk`, `garden`, `orders`, and `store`, with placeholders for future shed/market/Bone & Brine art.
- Unlocked map locations now render as clickable image markers with a subtle pulsing glow and hover-grow effect.
- Locked map locations render their configured image as a black silhouette and show `???` until unlocked.
- Updated day-orb icon sizing to `2rem`, allowed day-track overflow, and added image/text shadows so sun/moon icons remain legible on the track.
- Bumped database-backed app version to v0.4.11.

## v0.4.11

- Removed boxed map-location cards so markers sit directly on the map artwork.
- Map location icons now smoothly grow on hover.
- Unlocked map markers get a subtle dark pulsing glow to indicate they are clickable.
- Locked map markers use the configured location icon as a black silhouette and show `???`.
- Map labels now render as small readable pills below the image instead of large cards.
- Removed dead `.map-location-card` CSS from the old boxed-map approach.
- Bumped database-backed app version to v0.4.11.


## v0.4.11a

- Added `game_config.map_title` for the map title.
- Changed the default map title from `Overhead Map` to `Town`.
- Canvas and side-panel map title now read from database-backed state.
- Bumped app version to `v0.4.11a`.


## v0.4.12a

- Added the first pass of the Workroom / Shed placement system.
- Buying a Preserves Bin now unlocks the Shed location and backfills existing owners.
- Added `shed_zones` for separate wall and floor placement grids.
- Added `placeable_defs` for placeable object size, zone, category, icon, and future rotation support.
- Added `player_shed_objects` for per-player shed layouts.
- The Shed canvas now renders wall/floor grid overlays in edit mode.
- The Preserves Bin appears as a 2×2 floor machine and can be dragged to valid grid cells.
- Shed placement prevents overlapping objects and saves movement through `move_shed_object`.
- Version pill now stays fixed to the page-load version and warns if the server version changes during polling.
- Bumped database-backed app version to `v0.4.12a`.

## v0.4.12a

- Pivoted Shed functional machines from draggable floor objects to fixed clickable stations.
- Added station scaffolding for Preserves Bin, Drying Rack, Compost Bin, Seed Bin, and Workbench.
- Buying machines still unlocks the Shed; owned machine quantity now powers the station modal capacity.
- Preserves Bin station opens a machine modal showing owned slots, active jobs, ready jobs, and available recipes.
- Added slot enforcement so one owned machine unit can only run one active/uncollected processing job.
- Kept wall-decoration placement scaffolding separate from functional floor stations.
- Added `shed_station_config` table.
- Added map marker `icon_size` and `glow_color` controls.
- Changed map marker glow default to a warmer yellow click cue.
- Added `database/schema_v0_4_12a.sql`.

## v0.4.12b

### Added
- Added Forest Folk helper scaffolding with named helpers, race display, accessory slots, helper movement positions, speed/effectiveness stats, and future potion boost columns.
- Added Forest Folk accessory equipment records for Water, Till, Plant, Harvest, future Farmer's Market auto-sell, and future order automation.
- Added client-side Forest Folk equipment controls so accessories can be equipped/unassigned from the Forest Folk screen.
- Added a Land Claim Note item and locked-plot unlock flow.
- Added a crop/plot Info tool to inspect crop name, quantity, full grow time, approximate remaining cycles, boosts, water, and projected yield range.
- Added save-status indicator scaffolding for instant-feeling garden actions.
- Added audio manager scaffolding for SFX/BGM that unlocks after first user interaction.
- Added fairy-world calendar formatting: `Year X, Day Y` using a 300-day year.

### Changed
- General Store machines are now limited to the Preserves Bin; other machines are inactive for the store and reserved for later special/market/event sources.
- Preserves Bin price increased for early pacing.
- Order refill now schedules every 1 minute while available orders are below the board cap.
- Order reward text now says price modifiers are included instead of showing ambiguous extra fee text.
- First fairy summon no longer auto-equips the Aqua Amulet; Puddlewink arrives as a named Fairy with an empty accessory slot.

### Database
- Added `database/schema_v0_4_12b.sql`.
- Bumped database-backed app version to `v0.4.12b`.

## v0.4.12c

- Expanded Puddlewink's Fairy Bell introduction into two steps and moved the helper unlock to the end of the scene.
- Added real Forest Folk helper automation processing for Water, Till, Plant, and Harvest tasks.
- Added `player_helpers.last_action_at` so helpers act on a paced cooldown instead of constantly firing.
- Helper actions are intentionally weaker/slower than manual play: watering and tilling are small increments, and automated harvesting uses the low end of the crop yield.
- Forest Folk accessories now only appear in the equip dropdown if the player owns them or already has them equipped.
- Removed the old active Harvest Charm path in favor of the single Harvest Basket accessory.
- Removed player-facing “future feature” copy from the Forest Folk screen and accessory descriptions.
- Fixed the Inspect tool cursor so it shows the magnifier while using the garden info tool.
- Disabled Complete on orders when the player does not have the required items, and moved the coin float to only appear after a successful server response.
- Reworked canvas sizing for high-DPI displays so order board text is rendered against a DPR-scaled backing store instead of being browser-blown into soup.
- Added `database/schema_v0_4_12c.sql`.
- Bumped database-backed app version to `v0.4.12c`.

## v0.4.13

### Added
- Added database-driven system icon records for coins, reputation, and recognition using the existing `/assets/icons/global-*.png` graphics.
- Added a Ctrl-hover map coordinate helper. While hovering over the map canvas, holding Control shows the current canvas pixel `x, y` for updating map locations in the database.

### Changed
- Replaced hardcoded money, reputation, and recognition emoji usage in the main UI and order modal with icons loaded from `state.system_icons`.
- Kept emoji fallbacks for canvas-only text and early-load states so the UI does not break if icons are missing.
- Removed the remaining player-facing Forest Folk “Future Task Plan” heading from the side panel.

### Database
- Added `database/schema_v0_4_13.sql`.
- Bumped database-backed app version to `v0.4.13`.


## v0.4.14

- Converted the Orders Board display from canvas text to a DOM-rendered board surface for sharper, crisper text.
- Available order requirements now turn green when the player already owns enough of the requested item.
- Accepted order cancellation now asks for confirmation and warns about the reputation loss.
- Retuned Forest Folk watering automation so a water helper acts more often and bases water output on the player's current watering can while remaining weaker than manual watering.
- Added `database/schema_v0_4_14.sql`.
- Bumped database-backed app version to `v0.4.14`.


## v0.4.15

- Added the `#gardenCanvas[hidden] { display: none !important; }` CSS fix so DOM-rendered boards do not get pushed below the hidden canvas.
- Garden actions now trust the client for the fast loop: tilling, watering, planting, harvesting, and digging update locally first and save in the background.
- Background garden saves are queued sequentially so rapid actions stay responsive without stacking visible delays.
- Watering now renders immediately after local water changes instead of waiting for the server refresh.
- Added client-side watering helper automation so equipped water helpers periodically water crops while the garden is open.
- Trusted watering/tilling writes can persist exact client-side values without re-running the full tool validation path.
- Plant actions now return the real planted crop id so local optimistic crops can reconcile after background save.
- Added `database/schema_v0_4_15.sql`.
- Bumped database-backed app version to `v0.4.15`.


## v0.4.16

- Helper workers now move smoothly toward their target crop instead of snapping/teleporting when acting.
- Water helpers now continue targeting the driest eligible crop and only water after reaching it.
- Mature crops are excluded from watering automation.
- Fully grown crops no longer display a water bar and inspect as not needing water.
- Manual watering now refuses mature crops instead of wasting a water action.
- Preserved the `#gardenCanvas[hidden]` CSS fix from the prior hotfix.
- Added `database/schema_v0_4_16.sql`.
- Bumped database-backed app version to `v0.4.16`.


## v0.4.16a

Hotfix release.

- Fixed the garden canvas crash caused by missing `cropIsMature()`.
- Added the missing shared `cropNeedsWater()` helper used by fairy watering automation.
- Bumped the farm.js cache key to `v0.4.16a` so the browser stops requesting the old `v0.4.15` URL.
- Added `database/schema_v0_4_16a.sql`.
- Bumped database-backed app version to `v0.4.16a`.


## v0.4.16c

UI/UX hotfix release.

- Orders Board DOM rendering now uses a structure key and only updates timer/reward text during the one-second clock tick instead of rebuilding the entire board every second.
- Added small fade/slide screen transitions and modal pop-in animation so screen changes feel less abrupt.
- Added card fade-in animation for order cards/details.
- Watering helper float text now shows only the water gain text instead of reusing the fairy icon.
- Idle Forest Folk now wander around the outer garden canvas instead of sitting perfectly still in the work area.
- Seed pouch glow now uses image shadow/glow instead of drawing a circular glow behind the location.
- Map location icons now fall back to `/assets/map/<location>.png`, including the garden icon, instead of showing a question mark when marker data is missing.
- Moved money, reputation, and recognition out of the panel header and into the left side of the field actions row.
- Forest Folk helper rows now render image icons instead of printing asset paths.
- Cleaned Forest Folk helper copy to be more direct and less silly.
- Moved Forest Folk accessory assignment onto the canvas with draggable owned accessories and helper equipment slots.
- Added `database/schema_v0_4_16c.sql`.
- Bumped database-backed app version to `v0.4.16c`.

## v0.4.16d

- Calmed the Orders Board updates: timers update in place, modal/card stable keys no longer include ticking seconds, and default order-card animations no longer replay on every refresh.
- Confirming an available order now moves it locally first, then saves in the background; failed saves reconcile on the next state fetch.
- Added coin tooltip, changed navigation labels to `Map` and `Backpack`, and made save status display as dots with tooltip text instead of words.
- Added database-backed system icons for `nav_map`, `nav_backpack`, `nav_orders`, and `quest_available`.
- Added location-driven event marker scaffolding with a configurable pulsing/shaking quest marker over map locations.
- Changed the Farmer's Market unlock flow so reputation no longer silently unlocks it; the shop now shows a location event marker when the invite is available.
- Added the database-driven `market_shopkeeper_invite` event, which unlocks the Farmer's Market when completed.
- Added map sidebar timer content for shop refresh, future market open/close, and caravan scaffolding.
- Added `side_menu_html` support on `map_location_config`, with order-board help text supplied from the database when present.
- Added `database/schema_v0_4_16d.sql`.
- Bumped app/cache keys to `v0.4.16d`.


## v0.4.16e

- Bumped the database-backed app version to `v0.4.16e`.
- Fixed Farmer's Market visibility so reputation only creates the General Store invite event marker; the market itself stays locked until the event grants `location_market`.
- Added a cleanup migration that removes accidental non-event Farmer's Market unlocks while preserving legitimate `event_effect` unlocks.


## v0.4.16f

- Added the missing `market_shopkeeper_invite` event data as a three-step Fae Market invitation.
- Location event markers now fail closed: the shop quest marker only appears if the event exists, is active, and has a first step.
- Changed the quest marker from red alert styling to a warmer golden glow.
- Added weekend market gating. The Farmer's Market is visible after invitation, but cannot be entered while closed; tooltip explains it is closed.
- Added server-side Fae Market status with weekend 7:00–18:00 hours and map sidebar timer support.
- Added database scaffolding for shop daily buy limits with `shop_buy_limits` and `player_shop_sales`.
- Shop sales now enforce daily limits and only buy configured basic crops; the Fae Market path is scaffolded to buy any crop while open.
- Land Claim Notes now increase in shop price and stop after three total General Store purchases.
- Added `database/schema_v0_4_16f.sql`.
- Bumped app/cache/database version to `v0.4.16f`.


## v0.4.16g

- Fixed growth-cycle baseline for newly planted crops. Crops now store their current cycle index when planted, so planting carrots after 6am no longer causes them to immediately catch up through every previous 6am cycle.
- Added `database/schema_v0_4_16g.sql` to bump the database version and initialize any still-unprocessed active crops with the correct planted-time cycle baseline.
- Bumped app/cache/database version to `v0.4.16g`.


## v0.4.16h
- Made the update/version mismatch warning persistent while the loaded client is out of date.
- Reduced garden resync jumpbacks by skipping automatic state refreshes while client-trusted garden writes are pending.
- Renamed Farmer's Market to Fae Market in UI and config defaults.
- Changed Fae Market hours to open continuously from Saturday 06:00 through Sunday 18:00 in game time.
- Added Fae Market day/night background scaffolding through `map_location_config.day_background_image` and `map_location_config.night_background_image`.
- Added `fae_market_inventory` scaffolding so market offerings can later vary by `day`, `night`, or `both` phases.
- Added `database/schema_v0_4_16h.sql` and bumped app/cache/database version to `v0.4.16h`.

## v0.4.16i

- Scaled garden helper draw size up as the visible field grows, so helpers stay readable when plots shrink.
- Adjusted idle helper movement to start and wander along the garden outskirts instead of camping in the middle of the last plot.
- Added more movement/bob while helpers are actively working.
- Added `work_sprite` scaffolding for helper accessories/items, with emoji fallbacks used as working pop effects.
- Added crop/seed backpack summary to the Orders Board side menu.
- Added a grab cursor over seed pouches.
- Added Fae Market crop selling: any produce item can be sold there while the market is open, with no daily cap.
- Added market day/night background transition support while the player is inside the Fae Market.
- Added background scaffolding for shop, shed, garden types, caravan, and Fae Market day/night images.
- Added multiple-garden/garden-type scaffolding, including unlocked garden types and empty-garden-only type changes.
- Added seed/crop garden-type restrictions using `plants.allowed_garden_types_json`, where `[0]` means all garden types.
- Added `database/schema_v0_4_16i.sql` and bumped app/cache/database version to `v0.4.16i`.

## v0.4.16j

- Helper work sprites now only appear while a helper is actually working a valid crop target.
- Idle helpers now meander around the outside border of the garden instead of parking inside plots.
- Working helpers move a bit more around their active target.
- Accepting an order now reopens/refreshes the order modal on the newly confirmed order.
- Added DB-backed calendar icon support through the `nav_calendar` system item.
- Added a clickable calendar display with human weekday names and Fae day names after the Fae Market is unlocked.

## v0.4.16k
- Fixed nav/global icons not appearing for Backpack, Orders, Map, and Calendar by adding alias fallback lookups and refreshing the Orders button markup from the DB-backed icon state.
- Calendar label now renders image icons instead of falling back to the emoji placeholder.
- Harvest and Inspect tool cards now use DB-backed `tool_harvest` and `tool_inspect` system icons.
- Added `database/schema_v0_4_16k.sql` and bumped app/cache/database version to `v0.4.16k`.


## v0.4.16l
- Fixed Fae Market schedule to match the visible calendar: Saturday/Day of Leaves 06:00 through Sunday 18:00, continuously open overnight.
- Corrected crop visuals so stage 0 uses `assets/icons/garden-planted-soil.png`, then stage 1+ uses `assets/icons/crops/<plant>_<stage>.png`.
- Added a green harvest-ready glow for mature crops.
- Added pests/weeds scaffolding with `crop_problems`, garden-type weed/pest icon JSON, harvest-to-clear behavior, inventory rewards, and market selling support.
- Stopped water helpers from watering crops blocked by weeds or pests.
- Expanded Fae Market selling beyond crops to sellable backpack items such as seeds, weeds, and bugs.
- Added Fae Market buyable seed bundle scaffolding.
- Removed text labels drawn over simple canvas location scenes.
- Added `database/schema_v0_4_16l.sql` and bumped app/cache/database version to `v0.4.16l`.

## v0.4.16m
- Fixed DOM-backed Orders Board so `map_location_config.day_background_image` / `night_background_image` actually render behind the board.
- Fixed background argument usage for Shop and Garden canvas scenes so location backgrounds are used as backgrounds, not accidentally treated as title icons.
- Added `database/schema_v0_4_16m.sql` and bumped app/cache/database version to `v0.4.16m`.


## v0.4.16n

- Fixed scene background handling so all map-location canvas scenes can use `map_location_config.day_background_image` / `night_background_image`.
- Forest Folk now uses its configured location background.
- Caravan, Bone & Brine, Shed, Shop, Garden, Market, Orders, and Helpers now share the same location background lookup pattern.
- If a night background is missing, the client falls back to the day background instead of showing no background.
- Added `database/schema_v0_4_16n.sql` and bumped app/cache/database version to `v0.4.16n`.

## v0.4.16o

- Fixed DOM background CSS URLs so DB paths like `assets/map/orders_day.png` resolve from the app root instead of `assets/css/assets/...`.
- Moved Fae Market buyables out of the side menu and into the market canvas as a clickable grid of market finds.
- Added optimistic market buy/sell updates so coins and inventory counts change immediately while the database save happens in the background.
- Added Fae Market wanderer scaffolding using `assets/market/fae/fae#.png`, with configurable wanderer count and image count in `game_config`.
- Added randomized straight-line and meandering movement for Fae Market wanderers, including hue-shift variety.
- Darkened Forest Folk helper cards so helper text remains readable over illustrated backgrounds.
- Added `database/schema_v0_4_16o.sql` and bumped app/cache/database version to `v0.4.16o`.

## v0.4.16p

- Fixed General Store scene background lookup to support `shop`, `store`, and `general_store` location keys.
- Prevented the General Store from using its night/secret background until the Fae Market has been introduced/unlocked.
- Added caravan schedule scaffolding: the caravan arrives every other week from Tuesday through Thursday.
- Added DB-backed caravan active/inactive map icons via `map_location_config.active_map_icon` and `inactive_map_icon`.
- Added `caravan_status` to state and map sidebar timers.
- Added Bone & Brine caravan event scaffolding that appears on the next active caravan after Strange Relic #2 is flagged as collected.
- Added `database/schema_v0_4_16p.sql` and bumped app/cache/database version to `v0.4.16p`.

## v0.4.16q
- Fixed locked map locations showing `???`/question marks instead of their configured location art.
- Location definitions now inherit `map_location_config.map_icon` before unlock state is applied, so locked places render as the same icon blacked out.
- Added a defensive client fallback so locked locations prefer configured map icons even if older state payloads still include emoji fallbacks.
- Added `database/schema_v0_4_16q.sql` and bumped app/cache/database version to `v0.4.16q`.

## v0.4.28

- Switched back to numeric patch versioning for normal builds.
- Fixed locked map-location rendering at the code level instead of rewriting already-correct `map_location_config.map_icon` values.
- Shed and Bone & Brine location definitions now use image fallbacks instead of emoji placeholders.
- Map marker lookup now supports aliases consistently (`helpers`/`forest_folk`, `shop`/`store`/`general_store`, `market`/`fae_market`, etc.).
- Locked locations now keep their configured art and apply the blackout effect client-side.
- Added `database/schema_v0_4_28.sql`.


## v0.4.29

- Fae Market wanderers now recycle into newly randomized guests after they leave the screen or linger long enough, instead of reusing the same few sprites forever.
- Fae Market wanderers stop rendering while the market is closed.
- The Fae Market now fades to `assets/market/closed.png` when it closes, regardless of prior day/night phase.
- Canvas scene backgrounds now crossfade when changing day/night phases or moving between locations, avoiding hard background swaps.
- General Store sell rows now include subtle item-art row backgrounds.
- Changed the General Store sell limit copy from “Shop wants # more today” to “Limit: #”.
- Increased the default Fae Market wanderer image count to 18 so all current fae sprites are used.
- Added `database/schema_v0_4_29.sql` and bumped app/cache/database version to `v0.4.29`.

## v0.4.30

- Added browser-side image preloading for map and location background assets.
- The town map background stays warmed in the image cache.
- While on the map, the currently relevant day/night background for each location is preloaded so location changes can start cleanly.
- While inside a location, that location's day/night backgrounds are preloaded; the Fae Market also preloads its closed background and wanderer sprites.
- Background crossfades now use a longer ~2.1 second transition instead of the previous shorter fade.
- Added `database/schema_v0_4_30.sql` and bumped app/cache/database version to `v0.4.30`.


## v0.4.31
- Fae Market wanderers now enter from the bottom, wander, and exit out the bottom before recycling into a new visitor.
- Added Fae Market wanderer config values for size, transparency, and hue shifting.
- Location-to-location background crossfades now use a faster 1 second transition; day/night and market closed transitions keep the longer 2.1 second fade.
- Forest Folk canvas layout tightened into two-column helper cards with a scrollable helper area and a framed Owned Accessories shelf.
- Helper portraits now ease into a larger hover preview instead of popping instantly.
- Forest Folk sidebar no longer shows accessory dropdowns; it now shows usable helper summon items when present.
- Added `database/schema_v0_4_31.sql` and bumped app/cache/database version to `v0.4.31`.

## v0.4.32
- Fixed Caravan Camp map icon selection so the active caravan icon is used when the caravan is present.
- Added active/inactive caravan background support, including `caravan_full_day/night` and `caravan_empty_day/night` paths.
- Added overhead map day/night background support via `map_day_background_image` and `map_night_background_image`; `assets/map/map_day.png` and `assets/map/map_night.png` now resolve.
- Made locked garden plots more transparent so they sit more naturally over the garden background.
- Added configurable locked plot icon support through `game_config.locked_plot_icon`.
- Added `database/schema_v0_4_32.sql` and bumped app/cache/database version to `v0.4.32`.

## v0.4.33
- Patch package is changed files only; no image assets are included.
- Garden backgrounds now come from `garden_types.day_background_image` and `garden_types.night_background_image`.
- Legacy `garden_types.background_image` is migrated away and dropped.
- Default garden background paths use `assets/gardens/garden_day_(type).png` and `assets/gardens/garden_night_(type).png`.
- Removed the map title/subtitle text from the overhead map canvas.
- Field stats now use the regular cursor instead of text-selection cursor.
- Night map location glow shifts from yellow to bluish purple, with a subtle night filter on map icons.
- General Store sell rows now use editable `items.shop_row_icon` for their row background art and are easier to see.
- Added `database/schema_v0_4_33.sql` and bumped app/cache/database version to `v0.4.33`.

## v0.4.34

Hotfix release. This patch removes the remaining server-side reference path that could still query `garden_types.background_image` after the v0.4.33 migration, and removes the obsolete `goblin_icon` read from the time/config payload. It also includes an idempotent schema hotfix to ensure `garden_types.day_background_image` and `garden_types.night_background_image` exist, normalize their default paths, drop `game_config.goblin_icon` when present, and bump the app version.

No image assets are included in this patch.

## v0.4.35

- Moved garden click/use pop effect icons out of hardcoded JavaScript and into editable `items` system rows.
- Added editable system rows for `fx_water`, `fx_till`, `fx_plant`, `fx_harvest`, `fx_dig`, `fx_pouch`, and `fx_relic`.
- Added editable system rows for `tool_harvest` and `tool_inspect` cursor icons.
- Updated garden cursor/effect rendering to use DB-backed system icons instead of hardcoded emoji graphics.
- Added `database/schema_v0_4_35.sql`.
