# Crystal control — design

Date: 2026-10-24. Builds on `2026-10-24-map-object-categories-design.md` (same branch).

## Goal

Make the three crystal landmarks claimable and give their owner combat bonuses, and bring the static
rule book up to date with map objects and crystals.

## Claiming

- Checked when a unit finishes its own move command (`BattleRules` move, including an ice slide that ends
  it; if the slide pauses on a choice, the check runs when that choice resolves). Pushes, teleports,
  Sky Toss, Mycelium Network and other forced or ability movement never claim.
- For each crystal adjacent (hex distance 1) to the unit's final hex, the unit's player claims it when:
  - the unit is not engaged: no enemy unit adjacent to it within one elevation level (the same test the
    game uses for disengagement strikes), and
  - no enemy of that player is adjacent to the crystal.
- The Void Lord's units never claim crystals.
- A claim takes the crystal from any current owner, an ally included (ownership is per player). Claiming a
  crystal you already own does nothing and logs nothing.
- Ownership lasts for the rest of the game or until another player claims it; it is not lost by moving
  away or by the claiming unit dying.
- Event `crystalClaimed` with `{ seatId, unitId, hex, kind, previousSeatId }`, shown in the battle log
  (e.g. "Red claims the Fire Crystal").

## Bonuses

Bonuses apply to every unit the owning player controls, and stack: each crystal owned adds its bonus, so
two Fire Crystals give +4.

| Crystal | Bonus |
|---|---|
| Fire Crystal | +2 Power when attacking a Forest-biome creature |
| Forest Crystal | +4 Defense when defending against a water attack |
| Ice Crystal | +1 Power when attacking a Lava-biome creature; +2 Defense when defending against a fire attack |

- A **water attack** is any attack by a River-biome creature, or a Water Twister or Aquatic Surge by anyone.
- A **fire attack** is any attack by a Lava-biome creature, any strike already dealing fire damage
  (Fireball, Flame Strike, Dragon Breath), or Inferno, Lava Burst or Dragon's Breath (not yet implemented
  as attacks; they count once they are).
- Only monsters have a biome, so the Forest/Lava creature bonuses only ever apply against Co-op monsters.
- Bonuses are stat sources in `Stats::breakdown` (named after the crystal), so attack popups and stat
  breakdowns show them like other modifiers.

## Data and engine

- Engine board tiles keep the hex's `object` (from `Board::fromScenario`).
- State gains `crystals`: map of crystal hex key → owning seat id (absent = unclaimed). No migration for
  games already in progress.
- New `Crystals` class: `claim(state, unitId, &events)` and `statSources(state, unitId, stat, context)`.
- The map projection hex gains `controlledBy` (seat id or null) for crystal hexes.

## Client

- `HexProjection.ControlledBy`. A claimed crystal is drawn with a ring in the owner's seat colour beneath
  it, and its hex label adds "controlled by <player>".
- The battle log shows `crystalClaimed`.

## Rule book (static `rulebook.html` + `data/gameData.json` `terrainObjects`)

- New "Map Objects" rules section: Obstacles, Landmarks, Nexus Points, Items and what each blocks; one
  object per hex; runes and potions have no effect yet.
- Rewrite the "Terrain Objects" rules section and the Terrain Objects tab text to the claiming rules above
  (adjacent claim, engagement and enemy conditions, Void Lord can't claim, ownership persists, stacking).
- `terrainObjects` data: rename Water Crystal → Ice Crystal (biome Ice), fix benefit descriptions to the
  table above, and point images at `images/landmarks/<kind>-1.png`; the Terrain Objects cards show the image.

## Tests

- PHP: claim when not engaged; no claim when engaged or an enemy is next to the crystal; Void Lord can't
  claim; ally and enemy take-over; re-claim is a no-op; push/teleport don't claim; each bonus applies with
  ownership and not without; stacking; the event and the projection field.
- UI (bUnit): owner ring and hex label; log line.

## Out of scope

Rune and potion effects; scoring for crystals; the in-app rules screen (if any) beyond the static rule book.
