# Runes and potions — design

Date: 2026-10-25. Builds on `2026-10-24-map-object-categories-design.md` and `2026-10-24-crystal-control-design.md`.

## Goal

Give the Nexus Points (runes) and Items (potions) already placeable on maps their rules effects: runes are
environmental auras any unit can use, potions are one-shot pickups for player units.

## Rename

- `rune-of-magic` becomes `rune-of-focus` ("Rune of Focus") everywhere: `Board::NEXUS_POINTS`,
  `MapObjectArt`, image file `images/nexus/rune-of-focus-1.png` (same art), tests, rule book.
- Saved maps may still hold `rune-of-magic`; `Board::fromScenario` and the client's map loading read it as
  `rune-of-focus`. The editor writes only the new id.
- The Rune Smith's own "Rune of Warding" ability keeps its name; the rule book notes it is unrelated to the
  Rune of Warding nexus point.

## Rune radius (data and map editor)

- Scenario hexes gain an optional integer `radius` (1–4). It is meaningful only on a rune hex; absent means 1.
  `MapValidator` accepts null or 1–4. `ScenarioHex` gains `int? Radius`.
- The area of a rune with radius `n` is every hex at hex distance ≤ `n − 1` from it (radius 1 = the rune's
  hex only, 2 = plus the adjacent ring, up to 4). Elevation is ignored. Hexes outside the map are not in it.
- Map editor: with a rune tool selected, clicking a hex that already holds that same rune cycles its radius
  1 → 2 → 3 → 4 → 1. Placing anything else on the hex (another rune included) sets radius back to 1 / clears
  it. Erasing clears it.
- The editor shows the radius as a small number on rune hexes with radius > 1 and tints the rune's area.

## Rune effects

Any unit standing in a rune's area gets its bonus while it is there — any side, Void Lord monsters included.

| Rune | Bonus |
|---|---|
| Rune of Strength | +1 Power on regular attacks (any attack not made with a special-attack ability) |
| Rune of Focus | +1 Power on special-ability attacks (`context['ability']` set — the attacks the game treats as spells) |
| Rune of Warding | +1 Defense |

- Same kind does not stack: a unit inside the areas of two Runes of Strength gets +1. Different kinds combine.
- Power bonuses apply to the attacking unit's position; Warding applies to the defending unit's position.
- New `Runes` class: `statSources(state, unitId, stat, context)` returning sources named after the rune
  (e.g. "Rune of Strength", +1), called from `Stats::breakdown` next to `Crystals::statSources`, so the attack
  popup and stat breakdowns show them.
- Engine board tiles keep `radius` (from `Board::fromScenario`, default 1, `rune-of-magic` normalised).

## Potions

- A unit drinks a potion when it comes to rest on the potion's hex: at the end of its own move command (the
  points where `Crystals::claim` runs today, including a resolved ice-slide choice) and at the end of any
  forced or ability movement (push, teleport, ice slide, Sky Toss, etc.). Passing through a potion hex
  mid-path does not drink it.
- Void Lord units never drink; the potion stays. (The Void Lord places potions as rewards his monsters guard.)
- Drinking clears the potion from the board (`$state['board'][hex]['object'] = null`) and emits
  `potionDrunk` `{ unitId, seatId, hex, kind }`. The battle log shows e.g. "Red's Archer drinks a Speed Potion".
- Potions never respawn.

| Potion | Effect |
|---|---|
| Power Potion | +2 Power for 3 of the drinker's player's turns |
| Speed Potion | +2 Move for 3 of the drinker's player's turns |
| Health Potion | Removes all wounds (sets `wounds` to 0). Drunk even at full health (wasted). |

- Turn counting: the turn it is drunk counts as the first; the effect expires at the end of that player's
  third turn (drunk on turn 1 → lasts through the end of their turn 3).
- `Effects::add` gains an optional turn count: an effect with `expires.at = turnEnd` and `turns = N` is
  decremented at each end of its seat's turn and removed when it reaches 0 (emitting `effectExpired` as now).
  Effects without `turns` behave exactly as today.
- Drinking the same potion again while its effect is active replaces it (existing `Effects::add` behaviour),
  resetting to 3 turns. Power and Speed potions are separate effects and both can be active.
- Potion effects are named "Power Potion" / "Speed Potion", so they show as stat sources in breakdowns.

## Projection

- The map projection's `object` for each hex comes from the live state board when a game is in progress,
  so a drunk potion disappears for every viewer.
- The hex projection gains `radius` (int, rune hexes only; null otherwise).
- The unit projection exposes active timed effects with turns remaining (if not already exposed) so the
  unit card can show "Speed Potion +2 Move (2 turns)".

## Client

- Game board: each rune's area gets a soft tint in the rune's colour (beneath units, above terrain).
- Unit card / stat breakdown: potion effects with turns remaining.
- Battle log: `potionDrunk` line.
- Map editor: click-to-cycle radius, radius number, area tint.

## Rule book (static `rulebook.html`)

- Rewrite the Nexus Points bullet: runes, radius, bonuses, any unit including monsters, no same-kind stacking.
- Rewrite the Items bullet: drink on stopping there, Void Lord can't, effects and durations, no respawn.
- Note the Rune Smith "Rune of Warding" ability is unrelated.

## Tests

- PHP: rune area by radius 1–4 (and elevation ignored); any side benefits; same-kind no stack, different
  kinds combine; Strength applies to regular attacks only, Focus to special attacks only; Warding on defense;
  `rune-of-magic` read as Focus; validator accepts/rejects radius. Potion drunk at the end of a move, after a
  push and after a teleport; not drunk passing through; Void Lord does not drink; potion removed from board
  and projection; Power/Speed last exactly 3 of the drinker's turns; re-drinking resets; Health clears all
  wounds; `potionDrunk` event.
- UI (bUnit): editor radius cycling (1→4→1, reset on other placement); rune area tint; log line; unit card
  shows potion effect with turns remaining.

## Out of scope

Carried potions / inventory; respawning; scoring for runes or potions; APK rebuild and deploy.
