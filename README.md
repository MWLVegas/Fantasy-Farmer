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
