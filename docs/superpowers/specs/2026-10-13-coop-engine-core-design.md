# Co-op engine core — design

Date: 2026-10-13
Status: approved in conversation; awaiting written-spec review

## Goal

Make Co-op a playable, server-authoritative online match: one human Void Lord against 1–4 hero players on a new purpose-built 25×25 map. This is the first of several Co-op slices. It generalises the Vanilla engine so that sides, setup, turn order and victory are mode-specific while movement, combat, abilities, conditions and terrain stay shared.

## Decomposition

Co-op is delivered as separate slices, each with its own spec, plan and implementation:

1. **Co-op engine core (this spec)** — sides and mode rules, Void Lord role, monster setup, Co-op draft, shared-start deployment, turn schedule, monsters on stats alone, interim victory, new map.
2. **Objectives** — fountain capture, Void Portal, unit respawn, and the capture/control system that terrain objects reuse.
3. **Monster abilities** — 62 distinct abilities (40 Essences), grouped into two or three slices.
4. **Terrain objects and crystals** — built on the slice 2 capture system.

An AI Void Lord is a later slice; this design keeps the Void Lord a seat-controlled side so an AI controller can drive it without engine changes.

## Scope

In scope:

- `Sides` model and replacement of seat-based friend/foe checks.
- `ModeRules` strategy with `VanillaMode` (today's behaviour) and `CoopMode`.
- Void Lord role claim in the lobby; Co-op start requirements.
- Void Lord setup phase: tier-point budget, placement inside scenario spawn zones.
- Monsters as units on catalog stats with no abilities.
- Co-op hero draft (2 heroes + 1 army per hero player) and shared-start auto-deployment.
- Co-op turn schedule and activation allowances.
- Interim Co-op victory.
- New 25×25 Co-op map built by the scenario generator, replacing the Portal Watch stub.
- Projection, contract and client changes for all of the above.

Deferred:

- Fountains, Void Portal, respawn, terrain objects, crystals and scoring (slice 2 / slice 4).
- Monster abilities (slice 3+). Their text is displayed but marked "not yet in play".
- AI Void Lord.
- Battle Royale gameplay.
- Spectator events endpoint.
- Hex map editor support for spawn zones and starts.
- Biome restrictions on which monsters may be placed in which zone.

## Rulings

Decisions made during brainstorming that are not derivable from the rulebook:

- The Void Lord is a human seat now; an AI may take the seat in a later slice.
- The Void Lord places monsters freely within a tier-point budget, only inside scenario spawn zones.
- Slice 1 victory is interim: heroes win by defeating every monster; the Void Lord wins when every hero unit is defeated.
- Any lobby member may claim the Void Lord role; the host may reassign it.
- The map is a new purpose-built 25×25 map authored by the generator script.
- Monsters marked `hidden` in `data/gameData.json` cannot be picked.
- Heroes and armies are unique across hero players.
- The rulebook's advice that the Void Lord should not reactivate last turn's monsters is shown as guidance, not enforced.
- A hero player whose hero dies may spend that activation slot on any unit, by analogy with Vanilla's "hero defeated → activate any 4".

## Engine architecture

### Sides

Each unit gains `side`. The match state gains:

```json
"sides": {
  "heroes": { "kind": "heroes", "controllerSeatIds": ["seat-a", "seat-b"] },
  "void":   { "kind": "void",   "controllerSeatIds": ["seat-vl"] }
}
```

In Vanilla, each seat is its own side (`side = seatId`, kind `player`), so behaviour is unchanged.

`unit.seatId` keeps its meaning: the seat that commands the unit. Hero players command only their own units but are allies of every other `heroes` unit.

A new `Sides` helper answers `Sides::enemies($state, $unitA, $unitB)`, `Sides::allies(...)` and `Sides::sideOfSeat($state, $seatId)`. Every friend/foe test currently written as a `seatId` comparison moves to `Sides`. Known sites:

- `BattleRules` attack-target validity.
- `Targeting` enemy and ally filters.
- `Movement` enemy blocking, engagement and disengagement.
- `IceMovement` collision friend/foe checks.
- `ChainLightning`, `GustOfWind` target filters; `UnbreakableLine` ally check.

Seat-ownership tests (may this seat command this unit; which units' turn-end terrain resolves) stay seat-based.

### Mode rules

`MatchEngine` is constructed with, or resolves per game, a `ModeRules` implementation:

| Responsibility | `VanillaMode` | `CoopMode` |
|---|---|---|
| `start(seats, board, random)` | today's two-seat start | roles, sides, setup phase |
| Pre-battle phases | `draft` (today's `DraftRules`) | `setup` then `draft` |
| Deployment | today's opposing-start placement | shared-start placement |
| `nextSeat(state)` | the other seat | Co-op schedule |
| `allowance(state, seat)` | hero + 3 / any 4 | hero players 5, Void Lord 5 |
| `checkVictory(state)` | annihilation | interim Co-op victory |

`MatchEngine::apply` and `legalActions` dispatch on phase: `setup` and `draft` go to the mode, `active` goes to the shared `BattleRules`, which calls back into the mode for `nextSeat`, allowance and victory.

`MatchEngine::otherSeat()` is removed. Its callers move to `ModeRules::nextSeat()` (turn passing), the mode's draft rules (Vanilla start-position pairing), and the mode's victory check (`Wounds`).

`Turn::nextTurnNumberOf($state, $seatId)`, used for effect and stun expiry, becomes schedule-aware: it walks the mode's schedule from the current turn to the seat's next turn instead of assuming strict alternation. In Co-op the Void Lord acts every second turn.

### Controllers

Commands still arrive as (seat, command). An AI Void Lord later becomes a server-side controller that submits commands for the Void Lord seat through the same path. No engine change is needed for it.

### Saved-state compatibility

`MatchEngine::normalize()` fills `mode = vanillaPvp`, `sides` from `seats`, and `side = seatId` on every unit for states saved before this change, so in-flight Vanilla matches keep playing.

### Refactor gate

The sides/mode refactor is done first and on its own. The entire existing PHP test suite must pass unchanged before any Co-op behaviour is added.

## Lobby and Void Lord role

- The existing `game_memberships.role` column holds `player` or `voidLord`. No migration is needed.
- In a `coop` lobby any member can claim the Void Lord role (`claimVoidLord`) if it is free, or release it (`releaseVoidLord`). The host can assign it to any member (`assignVoidLord {seatId}`).
- Claiming, releasing or reassigning the role clears every member's ready flag.
- Starting requires exactly one Void Lord, 1–4 hero players and all members ready. The existing five-seat lobby maximum already covers this.
- The war room shows the Void Lord badge and readiness messages for a missing Void Lord or missing hero players.
- The `modeNotPlayable` refusal for `coop` is removed. `battleRoyale` keeps it.

## Void Lord setup phase

The Co-op match starts in phase `setup`. Only the Void Lord acts; hero players see a waiting view.

### Budget

- Monster cost by tier: common 1, elite 2, boss 4.
- Budget: `basePoints + pointsPerHeroPlayer × heroPlayerCount`, read from the scenario's new `coop` block. Defaults for the new map: base 4, 8 per hero player (12 points for one hero player, 36 for four). These are tuning values.
- Monsters with `hidden: true` are not in the roster. The same monster may be picked more than once.

### Commands

- `placeMonster {monster, hex}` — the monster must be in the roster, the hex must be in a Void spawn zone, empty and enterable, and the cost must fit the remaining budget.
- `removeMonster {unitId}` — refunds the cost.
- `confirmSetup` — requires at least one monster; afterwards setup is locked and the phase moves to `draft`.

Placements are visible to all seats as they happen.

### Monster units

- Unit ids `v-m1`, `v-m2`, … in placement order, never reused within a match.
- `side = void`, `seatId` = Void Lord seat, `isHero = false`, `tier` from the catalog.
- Catalog stats with `abilities = []`. The projection carries the catalog ability text separately, flagged inactive.
- `Catalog` gains monster loading from `data/gameData.json` (`monsters[].creatures[]` with biome and tier).

## Co-op hero draft

- An initiative roll among hero players sets the draft order and the hero turn order. The Void Lord does not roll.
- Snake draft by `SnakeDraftOrder`. Each hero player makes three picks — two heroes and one army — in any order, one pick per draft turn.
- Heroes and armies are unique across hero players. Five armies cap the game at four hero players, matching the lobby limit.
- No first-player or start-position decision: there is one shared start and hero players act before the Void Lord.
- Draft state: `heroesBySeat {seatId: [hero, hero]}`, `armyBySeat {seatId: army}`.

## Deployment

When the draft completes, every hero player's units (two heroes plus six army units) are auto-placed around the scenario's single `playerStart` hex:

- Uses `Placement`, with the nearest Void spawn-zone hex as the facing direction in place of the opponent home.
- Seats are placed in turn order, interleaved one unit per seat per round, so no army is left wholly at the back.
- Unit ids: `s{n}-hero1`, `s{n}-hero2`, `s{n}-u1` … `s{n}-u6`, where `n` is the seat's position in `state.seats`.
- The scenario guarantees capacity for 32 hero units. `Placement::assertCapacity` checks this at start.

## Turns

### Schedule

`H1 → VL → H2 → VL → … → Hn → VL`, repeating, where `H1…Hn` are hero players in initiative order. A round begins on H1's turn.

A hero player with no units left on the board is skipped, together with the Void Lord turn that would follow it. Turn-end and turn-start processing (effect expiry, poison and lava, conditions) runs for each actual turn by its acting seat, as today.

### Activations

- Hero player: up to 5 activations — both heroes and 3 other units. Non-hero activations are capped at `3 + number of that player's heroes defeated`. With fewer than 5 units left, each can activate once.
- Void Lord: up to 5 activations of any monsters. The UI shows the rulebook's reactivation advice; the server does not enforce it.

### Unchanged behaviour

Moonlight Glare still prompts its owner mid-roll on another seat's turn. Pending choices are still addressed to a seat. Abilities may only be used on their commander's turn.

## Victory (interim)

Checked wherever Vanilla checks annihilation today:

- **Void Lord wins** when every `isHero` unit of every hero player is defeated.
- **Heroes win** when every `void` unit is defeated.

`result` records `{winningSide: "heroes" | "void", reason: "heroesDefeated" | "monstersDefeated"}`. Vanilla results keep `winningSeatId` and `reason: "annihilation"`, and gain `winningSide` equal to the winning seat.

## Map: Void Reaches (working name)

Built by `api/tools/build-terrain-scenarios.php` as `api/scenarios/void-reaches.json`. It is compatible with `coop` only and replaces `portal-watch.json`, which is deleted. It uses existing biome/terrain art.

- **Size:** 25 columns × 25 rows (625 hexes).
- **Hero start (south centre):** plains grass and roads. One `playerStart` hex, `portal: true` (reserved for slice 2's Void Portal). At least 32 neutral, hazard-free, enterable hexes around it. No spawn-zone hex within 6 hexes.
- **The Sundering River:** an east–west band of open water across the centre with three shallow-water fords and one stone-path bridge.
- **Five zones, each containing a `spawnZone: "void"` area:**
  - **Frost Reach (west):** snow with an ice lake.
  - **Ember Wastes (east):** basalt cut by lava channels.
  - **The Hollow (north centre):** darkness and poison pits on high ground. It is the largest spawn zone.
  - **Thornwild (south-west):** jungle and swamp.
  - **Shifting Dunes (south-east):** dunes and desert.
- **Spawn zones:** about 150 hexes total (The Hollow the largest), none on open water, ice, poison or lava.
- **Elevation:** crag ridges and stone outcrops separate zones and break sight lines. Every zone is reachable from the start by at least two disjoint routes.
- **Reserved fountain sites:** one each in Frost Reach, Ember Wastes and The Hollow, kept clear but not flagged `fountain: true` until slice 2.
- **Scenario `coop` block:** `{ "basePoints": 4, "pointsPerHeroPlayer": 8 }`.

The Co-op map picker lists this map.

## Projection and contract

The match projection gains:

- `mode`.
- `sides`.
- Per unit: `side`, `tier`, and `inactiveAbilities` for monsters.
- Per seat: `role`.
- `setup`: `{ budget, spent, roster: [{name, biome, tier, cost, stats, abilities}], spawnZoneHexes, confirmed }` while in or after setup.
- Draft: `heroesBySeat` alongside the existing Vanilla fields.
- Result: `winningSide`.

Existing Vanilla fields are unchanged. `BloodAndBanners.Contracts` records gain matching nullable members so older snapshots still deserialize.

## Client

- **WarRoomLobby:** Claim/Release Void Lord, host assignment, role badge, Co-op map picker, readiness messages.
- **VoidLordSetup (new component):**
  - Roster grouped by biome and tier with art, cost and stats.
  - Budget meter.
  - Spawn-zone highlight on the board.
  - Click a hex to place the selected monster, click a placed monster to remove it, then confirm.
  - Hero players see a waiting banner and monsters appearing live.
- **DraftTable:** Co-op variant with two hero slots per player and N players in snake order.
- **GameTable:**
  - Up to five player colours plus a distinct Void palette.
  - Ally/enemy distinction.
  - Turn banner, e.g. "Void Lord — responding to Player 2".
  - Activation counter for 5.
  - Monster cards with inactive abilities greyed and labelled.
- **CombatLog:** names the acting seat in multi-player turns.

## Error handling

New rule error codes with player-facing messages:

- Lobby: `voidLordRequired`, `heroPlayerRequired`, `voidLordTaken`, `notVoidLord`.
- Setup: `budgetExceeded`, `outsideSpawnZone`, `hexOccupied`, `hexNotEnterable`, `unknownMonster`, `setupConfirmed`, `setupEmpty`.
- Draft: `heroesComplete` (a third hero pick), plus the existing `heroTaken` and `armyTaken`.

Malformed payloads (wrong types, missing fields) produce 4xx rule errors, never 500s.

## Testing

- **Refactor gate:** the full existing PHP suite passes unchanged after the sides/mode refactor.
- **PHP unit tests:**
  - `Sides`.
  - `CoopMode` setup: budget scaling, zone, occupancy, enterability, hidden monsters excluded, remove/refund, confirm rules.
  - Draft order and uniqueness.
  - Deploy capacity and interleaving.
  - Schedule, including skipped players.
  - Schedule-aware `nextTurnNumberOf`.
  - Activation caps, including after hero deaths.
  - Both victory paths.
  - Friend/foe in ice collisions and in ability targeting with allied seats.
- **PHP integration:** a full scripted Co-op match through `GameService`: create, claim role, ready, start, setup, draft, turns, victory.
- **Map tests:**
  - 25×25 size.
  - Start capacity ≥ 32.
  - Spawn-zone distance ≥ 7 from start.
  - No hazard hexes in spawn zones.
  - Two disjoint routes from start to each zone.
  - The three reserved fountain sites are enterable and unoccupied.
- **C#:** contract deserialization for old and new snapshots. Component tests for setup and lobby where the repo already tests components.
- **End to end:** a browser run of a one-Void-Lord, two-hero-player match through setup, draft, several turns and a victory.
