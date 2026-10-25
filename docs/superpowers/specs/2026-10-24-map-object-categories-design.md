# Map object categories — design

Date: 2026-10-24

## Goal

Split map objects into four categories with distinct movement/sight rules, each placeable from its own
map-editor button, with art from the 6000FantasyIcons pack. This slice places and shows Nexus Points and
Items only; their gameplay effects (rune bonuses, pickup, potion use) come in a later slice.

| Category | Movement (flyers too) | Line of sight | Unit may stand there | Kinds |
|---|---|---|---|---|
| Obstacle | blocked | blocked | no | tree, rock |
| Landmark | blocked | open | no | shrine, fire-crystal, forest-crystal, ice-crystal |
| Nexus Point | open | open | yes | rune-of-magic, rune-of-strength, rune-of-warding |
| Item | open | open | yes (stays on the map for now) | power-potion, speed-potion, health-potion |

## Rulings

- A hex holds at most one thing: a start position, an obstacle, a landmark, a nexus point or an item are
  mutually exclusive. Placing any of them replaces whatever was there.
- Shrines move from Obstacle to Landmark and stop blocking sight.
- No migration or compatibility handling for old maps or payloads; there shouldn't be any to deal with.
- Unknown object strings behave as nothing (no blocking), as today.

## Data model

Unchanged on disk: each scenario hex keeps one `object` string. A catalog maps kind → category.

## Rules (PHP)

- `Board` gains the category catalog (`OBSTACLES`, `LANDMARKS`, `NEXUS_POINTS`, `ITEMS`) plus
  `Board::blocksSight(hex)` (obstacle kind or `sightBlocker` flag) and `Board::impassable(hex)`
  (blocksSight or a landmark kind).
- Each board hex built by `Board::fromScenario` (and the projection built in `GameService`) carries both
  `sightBlocker` (sight only) and a new `impassable` flag.
- Movement/occupancy checks switch from `sightBlocker` to `impassable`: `Terrain`, `Forced`, `Placement`,
  `CoopSetup`, `SkyToss`, `MyceliumNetwork`, `TentacleRegeneration` (and any others found by grep).
  Line of sight (`HexLineOfSight`, `Combat`'s LOS board) keeps `sightBlocker`.
- The board/projection also exposes `object` so the client can draw it (already done for the projection).
- Tests: walker and flyer cannot enter a landmark hex; LOS passes over a landmark but not an obstacle;
  a unit can move onto and end on a nexus or item hex; a shrine no longer blocks LOS.

## Contracts and client

- `HexProjection` gains `Impassable` (bool init property).
  `GameTable`'s forced-move preview uses it instead of `SightBlocker`.

## Art

- Copy and downscale (to 128px) the icons:
  - `images/landmarks/fire-crystal-1.png` ← Res_27, `forest-crystal-1.png` ← Res_29_stoneg,
    `ice-crystal-1.png` ← Res_75_crystalS; move `images/obstacles/shrine-1.png` to `images/landmarks/`.
  - `images/nexus/rune-of-magic-1.png` ← Loot_86_runestone, `rune-of-strength-1.png` ← Loot_198_rune,
    `rune-of-warding-1.png` ← Enchantment_58_runecrystal.
  - `images/items/power-potion-1.png` ← Res_102, `speed-potion-1.png` ← Res_39_colbGreen,
    `health-potion-1.png` ← Res_98.
- `ObstacleArt` becomes `MapObjectArt`: per kind its display name, category, image count and folder;
  `Variant` and `ImagePath` as today.
- `HexObstacle` becomes `HexMapObject`: obstacles and landmarks stand on the hex like a unit figure;
  nexus runes sit flat and centred on the hex face with a soft glow; items are drawn at 0.8× the hex radius (~35px), centred just below the hex centre on a soft shadow, low on the
  face. Nexus and item art is drawn in the layer beneath units so a unit standing there still reads clearly.

## Editor

- The "Map objects" group has Start, Obstacle, Landmark, Nexus, Item and Erase buttons. Each category
  button cycles its kinds like the current obstacle button and shows the selected kind's image.
- `MapEditorDocument.SetObject(q, r, kind)` validates the kind against the catalog and clears the start
  and any other object; `SetStart` clears any object; `Erase` clears everything. The editor keeps setting
  `sightBlocker` only for obstacles (cleared for other categories).
- The selected-hex status line shows "Category: Kind", e.g. "Landmark: Fire Crystal".
- Tests: placing each category, cycling within each, mutual exclusion with starts and other objects,
  erase, and the status label.

## Out of scope

Rune effects, item pickup and potion use; multiple objects per hex; APK rebuild and deploy (separate step).
