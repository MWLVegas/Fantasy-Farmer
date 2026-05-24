# Farming Game

Working title: TBD.

A slightly idle farming game where players expand gardens, grow crops, process harvests, complete contracts, and eventually automate work with fuel-powered golems.

## v0.1 Goals

- Google login required.
- Create player account on first login.
- One starting garden.
- Garden max size: 5x5.
- Player starts with 4 unlocked plots: A1, A2, B1, B2.
- Manual actions:
  - Till plot
  - Plant crop
  - Water crop
  - Harvest crop
  - Sell inventory item
- Growth steps:
  - Each plant has `growth_steps` and `seconds_per_step`.
  - Full grow time = `growth_steps * seconds_per_step`.
  - Each completed growth tick advances crop growth by 1 step.
- Water:
  - Crops have water level.
  - Growth pauses if water is below the plant's required threshold.
  - No crop death in v0.1.
- Equipment:
  - Machines are global player unlocks, not placed inside gardens.
  - Preserve Bin exists in v0.1.
- Processing:
  - Recipes convert harvested items into processed goods.
  - Example: 8 Strawberries → 1 Strawberry Jam.
- Icons:
  - If icon value contains a `.`, render as image path.
  - If icon value does not contain `.`, render as emoji text.

## Setup

1. Put these files in the web project directory.
2. Keep `db_connect.php` at project root.
3. Confirm `/home/raumhub/envs/farmer.env` contains:
   - `DB_HOST`
   - `DB_USER`
   - `DB_PASS`
   - `DB_NAME`
   - `GOOGLE_CLIENT_ID`
   - `GOOGLE_CLIENT_SECRET`
   - `GOOGLE_REDIRECT_URI`
4. Import `schema.sql`.
5. Visit `/login.php`.

## v0.1 Notes

This version intentionally keeps the loop simple. No seasons, no crop death, no advanced contracts, no golem automation yet. The database is scaffolded for future expansion.

## Future Systems

- Golems with fuel.
- Offline automation.
- Weeds and pests.
- Contracts/orders.
- Additional garden types: Farm, Water, Mountain, Mystic.
- Magic water for water gardens.
- Tool upgrades.
- Multiple gardens.
- More machine types: Fermenter, Dehydrator, Mill, Press.
- Equipment upgrades and queues.
# Fantasy-Farmer
