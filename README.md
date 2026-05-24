# Fantasy Farmer

A slightly idle farming game for Andy, starting with basic crops and gradually expanding into magical gardens, strange produce, golems, and fantasy processing.

## Current Version

v0.2.1

## v0.2 Changes

- Renamed game to **Fantasy Farmer**.
- Garden play field now uses an HTML canvas.
- Added bottom-right version pill.
- Reworked UI into tabs:
  - Garden
  - Shop
  - Shed
  - Inventory
- Removed free starting Preserve Bin.
- Starter setup:
  - A few Carrot Seeds
  - Broken Hoe
  - Leaky Watering Can
  - Bent Shovel
  - Hopes and dreams
- Inventory now displays as a visual grid.
- Plots no longer show text like “dirt” or “tilled” in the play field.
- Canvas supports:
  - dirt/till/water visuals
  - crop rendering
  - click detection
  - future golem animation
- Database may be dropped/rebuilt during RC development.

## Setup

1. Upload files into your project folder, such as `/public_html/farmer/`.
2. Confirm `db_connect.php` points to `/home/raumhub/envs/farmer.env`.
3. Confirm env has:
   - `DB_HOST`
   - `DB_USER`
   - `DB_PASS`
   - `DB_NAME`
   - `GOOGLE_CLIENT_ID`
   - `GOOGLE_CLIENT_SECRET`
   - `GOOGLE_REDIRECT_URI`
4. Import `schema.sql`.
5. Visit `login.php`.

## Path Style

All app paths are project-relative so the game can run from a subdirectory.

## Notes

For v0.2, the Preserve Bin is available in the shop/shed progression but is not given for free.


## v0.2 Canvas Fix

Fixed the canvas render loop. The first v0.2 build started the animation loop before farm state loaded, then stopped immediately. The canvas now continuously renders and shows a loading field before state arrives.


## v0.2.1

- Bumped visible game version to `v0.2.1`.
- Added CSS/JS cache-busting query strings.
- Made canvas visibly styled even before drawing.
- Replaced direct `ctx.roundRect()` usage with a custom rounded-rectangle path helper.
- Added a console marker: `Fantasy Farmer JS loaded: v0.2.1`.
