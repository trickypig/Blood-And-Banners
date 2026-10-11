# Vanilla playable match — design

Date: 2026-10-11
Status: approved in conversation; awaiting written-spec review

## Goal

Two people in two browsers can play a complete, simplified Vanilla PvP match of Blood & Banners: d20 initiative, an open draft of turn order / start position / hero / army, automatic unit placement, then alternating turns of move-and-attack with custom dice until one side is annihilated.

Today a match stops at the draft boundary: `startGame` moves the lifecycle to `draft` and the command pipeline only accepts `setReady`.

## Scope

In scope:

- Vanilla PvP only (2 seats).
- Initiative, open draft, automatic placement, activations, movement with elevation, disengagement, ranged/melee attacks with line of sight, dice combat, defeat, annihilation victory.
- One new built-in map, **Proving Field**, sized for full armies.
- Draft screen, playable board, combat log, match-end overlay.

Deferred (not in this slice):

- Hero and unit abilities, specials and essences — every unit fights on stats alone.
- Water, ice (unsteady/slipping), poison, stun, flying.
- Terrain objects / crystals, scoring, respawn.
- Co-op and Battle Royale gameplay (their lobbies still work but cannot start a draft).
- Manual unit placement; board zoom/pan.

## Rules as implemented

Where the rulebook is silent or ambiguous, the choice below is the decision for this slice.

### Initiative and draft

- `startGame` rolls initiative for both seats with the existing `InitiativeResolver` (d20, re-roll ties) inside the same transaction, and emits `initiativeRolled`.
- The initiative winner picks first; picks then alternate.
- The open decisions are:
  - **First player** — one decision for the match: the picker names which seat takes turn 1.
  - **Start position** — one decision for the match: the picker takes start 1 or 2; the opponent gets the other.
  - **Hero** — one per seat. A seat may only claim its own hero.
  - **Army** — one per seat. A seat may only claim its own army.
- On their pick a player makes any one decision that is still open for them. If none is open for them, their pick is skipped and the other player picks again.
- Each hero and each army may be taken by at most one seat.
- When every decision is made, placement runs and the lifecycle becomes `active`.

### Placement

- Each seat's home is the hex with the matching `playerStart` value. The home hex itself is not used for units.
- Units are placed hero first, then army units in catalog order.
- The first 4 go on the walkable, unoccupied neighbours of the home hex, nearest the opponent's home first (hex distance).
- The rest go on walkable, unoccupied hexes adjacent to an already-placed friendly unit, again nearest the opponent's home first.
- Ties break by `q`, then `r`, so placement is deterministic.
- Walkable means: on the board and not `sightBlocker`.
- `startGame` rejects a scenario whose starts cannot each fit 7 units with `scenarioTooSmall`. Training Grounds therefore can no longer start a match; Proving Field becomes the Vanilla default.

### Turns and activations

- Seat order: the first player takes round 1; turns alternate; the round increments when the first player's turn begins again.
- Activation allowance per turn:
  - Hero alive: up to 4 activations, at most 3 of them non-hero units.
  - Hero defeated: any 4 units.
  - Fewer than 4 units remain: each remaining unit once.
- Each unit can be activated at most once per turn.
- A unit's activation begins with its first `moveUnit` or `attackUnit` and consumes one activation.
- Within an activation the unit may move once, then attack once. Attacking without moving is allowed; moving after attacking is not. An attack ends the activation.
- Starting a different unit ends the current activation. `endActivation` ends it explicitly.
- The turn passes automatically when no activations remain and the active unit (if any) has no legal move or attack left. `endTurn` passes it at any time.

### Movement

- A unit moves to a single destination within `Move` steps.
- Each step goes to an adjacent walkable hex whose elevation differs by at most 1.
- Paths may pass through hexes holding friendly units, not enemy units.
- The destination must be unoccupied.
- The server computes the reachable set (breadth-first over step count) and the client chooses from it.

### Disengagement

- Engaged means: adjacent and elevation difference ≤ 1.
- For each enemy engaged with the mover at the start of the move and not adjacent at the destination, the mover takes a disengagement attack after the move completes.
- A disengagement attack rolls 1 attack die; each sword is 1 wound; there is no defense roll.
- If the mover is defeated, it is removed at its destination.

### Attacks

- The target must be an enemy within `Range` hexes (hex distance) and visible per the existing `HexLineOfSight` (units and sight blockers on intermediate hexes block).
- The attacker rolls `Power` dice and counts swords; the defender rolls `Defense` dice and counts shields (existing `CustomDice`).
- Wounds = swords − shields when positive, otherwise 0.
- A unit with wounds ≥ HP is defeated and removed from the board permanently.

### Victory

- When a seat has no units left, the other seat wins with reason `annihilation`. The lifecycle becomes `completed` and all further commands are rejected with `matchCompleted`.

## Server design

### Storage

- New migrations `002_match_state.sqlite.sql` and `002_match_state.mysql.sql` add:
  - `game_states (game_id PK → games.id, version, state_json, updated_at)`.
- `Migrator` runs every `NNN_*.{dialect}.sql` file in numeric order. All migrations stay idempotent (`CREATE TABLE IF NOT EXISTS`).
- `state_json` holds the authoritative match state: initiative result, draft decisions, units (with stats copied in), turn state and result.
- Hero and army stats are copied from `data/gameData.json` into the state at pick time, so later edits to the print data never change an in-progress match.
- `game_snapshots` continues to receive a projection per version, as today.

### Catalog

- `BloodAndBanners\Rules\Vanilla\Catalog` loads heroes and armies from `data/gameData.json`. The path is a constructor argument (default: repo-relative) so tests can supply a fixture.
- Unit IDs are stable within a match: `s{seatIndex}-hero`, `s{seatIndex}-u{1..6}`.

### Engine (`api/src/Rules/Vanilla/`)

Plain PHP with no database access.

| Class | Responsibility |
|---|---|
| `MatchEngine` | `apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array{state, events}`; `legalActions(array $state, string $seatId): array`; `initialState(...)` from the initiative result and memberships. Throws `GameServiceException` with a specific code for illegal commands. |
| `DraftRules` | Whose pick it is, open decisions per seat, skipping, uniqueness. |
| `Placement` | Deterministic placement and the 7-unit capacity check. |
| `Movement` | Reachable hexes, path constraints, disengagement candidates. |
| `Combat` | Range, line of sight, dice resolution, defeat, victory check. |

`GameService` changes:

- Constructor gains an optional `RandomSource` (default `SecureRandomSource`) and an optional `Catalog`.
- `startGame` validates capacity, rolls initiative, writes the initial `game_states` row and emits `initiativeRolled`.
- `submitCommand` keeps its envelope validation, seat-credential check, receipt de-duplication and version check. `setReady` stays as is; every other kind is dispatched to `MatchEngine` with the loaded state. Each command runs in one transaction: state row, version bump, events, snapshot and receipt.
- Projection building (player and spectator) is shared and reads the match state.

### Commands

All go through `POST /api/v1/games/{id}/commands` with `commandId`, `expectedVersion`, `kind` and `payload`.

| Kind | Payload | Phase |
|---|---|---|
| `chooseFirstPlayer` | `{ "seatId": "…" }` | draft |
| `chooseStartPosition` | `{ "start": 1 \| 2 }` | draft |
| `pickHero` | `{ "hero": "Thorgrim Shattershield" }` | draft |
| `pickArmy` | `{ "army": "Blackmaw" }` | draft |
| `moveUnit` | `{ "unitId": "s1-u3", "to": "4,2" }` | active |
| `attackUnit` | `{ "unitId": "s1-u3", "targetUnitId": "s2-hero" }` | active |
| `endActivation` | `{ "unitId": "s1-u3" }` | active |
| `endTurn` | `{}` | active |

### Events

Appended to `game_events` with `focusHexes` set to the hexes involved.

| Kind | Payload |
|---|---|
| `initiativeRolled` | `rolls` (seat → list of d20 results), `order` |
| `draftPicked` | `decision`, `value`, `seatId` |
| `armiesPlaced` | `units` (unitId → hex) |
| `unitMoved` | `unitId`, `from`, `to`, `disengagement` (list of `{byUnitId, faces, wounds}`) |
| `attackResolved` | `unitId`, `targetUnitId`, `attackFaces`, `defenseFaces`, `wounds` |
| `unitDefeated` | `unitId` |
| `turnEnded` | `seatId`, `nextSeatId`, `round` |
| `matchCompleted` | `winningSeatId`, `reason` |

### Error codes

All 422 unless noted. Illegal commands never change state.

- Draft: `notYourPick`, `decisionAlreadyMade`, `heroTaken`, `armyTaken`, `unknownHero`, `unknownArmy`, `invalidStart`, `invalidSeat`.
- Battle: `notYourTurn`, `unknownUnit`, `notYourUnit`, `unitAlreadyActivated`, `noActivationsRemaining`, `heroActivationRequired` (4th non-hero while hero alive), `alreadyMoved`, `alreadyAttacked`, `hexUnreachable`, `targetOutOfRange`, `noLineOfSight`, `matchCompleted`.
- Start: `scenarioTooSmall`; `modeNotPlayable` when the host starts a Co-op or Battle Royale game.
- Unchanged: `versionConflict` (409), seat-credential errors (403), `unsupportedCommand`, `illegalLifecycle`.

## Contracts

All changes are additive. New members on `GameProjection` are `init` properties defaulting to empty/null, matching `Memberships`.

- `GameProjection.Draft` — `DraftProjection?`, null outside the draft:
  - `InitiativeRolls` (seat → `IReadOnlyList<int>`), `PickingSeatId`
  - `FirstSeatId?`, `StartBySeat` (seat → int), `HeroBySeat` (seat → string), `ArmyBySeat` (seat → string)
  - `AvailableHeroes` (`IReadOnlyList<HeroCard>`), `AvailableArmies` (`IReadOnlyList<ArmyCard>`); each card has `Name`, `Image`, stats and a `TakenBySeatId?`; `ArmyCard` adds `Units` (`IReadOnlyList<UnitCard>`).
- `GameProjection.Units` — `IReadOnlyList<UnitProjection>`: `UnitId`, `SeatId`, `IsHero`, `Name`, `Image`, `Move`, `Range`, `Power`, `Defense`, `Hp`, `Wounds`, `Hex?`, `ActivatedThisTurn`.
- `TurnProjection` gains `ActiveUnitId?` (as an `init` property).
- `LegalAction` is unchanged in shape:
  - `moveUnit` — `UnitId` and every reachable hex.
  - `attackUnit` — `UnitId` and the hexes of attackable enemies.
  - `endActivation`, `endTurn` — no targets.
  - Draft kinds — no unit or targets; options come from `Draft`.
- `Board.Hexes[].OccupantId` carries the live unit ID during the match.
- `Result` — `{ Reason: "annihilation", WinningSeatId, FinalScores: {} }`.
- Spectators receive the same `Draft`, `Units` and `Turn`, with empty `LegalActions`.
- Fixtures: `game-projection.json` gains sample `draft` and `units`; new `draft-projection.json` and `unit-projection.json`; all round-trip tested.

## Client UI

- `GameExperience` routes `Draft` to a new `DraftTable`, and `Active`/`Completed` to `GameTable`. Polling continues after the lobby.
- `IGameSessionCoordinator.SubmitCommandAsync(kind, payload)` builds the `CommandEnvelope` from the current projection (new `commandId`, current `expectedVersion`). On `versionConflict` the client refreshes and shows "The battlefield changed — try again."
- The client highlights only what `legalActions` lists; it contains no rules logic.

### DraftTable

- Initiative banner: both rolls including re-rolls, and the winner.
- Status: "Your pick" or "Waiting for the opposing commander".
- Four decision panels: first player, start position, hero, army. Decided panels show the result, locked. Hero and army cards show stats (army cards list their six units); cards taken by the opponent are greyed.
- Controls are enabled only for picks the server lists as legal. One click submits.

### GameTable

- Unit tokens in seat colour with an HP pip bar; the hero carries a banner mark.
- Select one of your units → reachable hexes and attackable enemies highlight in distinct styles. Click a hex to move; highlights refresh. Click an enemy to attack.
- Command rail: selected unit's stat card, **End activation**, **End turn**, activations remaining, both rosters with defeated units struck through.
- Combat log from `/events`, with dice faces, e.g. `Ranger → Grunt: ⚔⚔⚔🛡 vs 🛡⬚ — 2 wounds`; focus hexes pulse briefly.
- Turn ribbon: "Your turn · 3 activations" or "Opponent's turn".
- Match end: victory/defeat overlay with **Return to war room**, which clears the saved session.
- Spectators: same board and log, no controls.
- Images: cards and tokens use the catalog image path when an `ImageBaseUrl` setting is configured; otherwise initials tokens and text cards.
- Layout: existing responsive hex grid; the command rail stacks below the board on narrow screens.

## Proving Field map

- `api/scenarios/proving-field.json`, `modeCompatibility: ["vanillaPvp"]`.
- 11 columns × 9 rows; terrain limited to grass, roads and stone; elevations 0–2.
- Home portals (`playerStart` 1 and 2) on opposite short edges, every neighbour walkable, room for 7 units each.
- A central ridge (elevation 1–2) with a few sight-blocking crags and statues so ranged units must manoeuvre.
- `QuickStart` uses Proving Field for Vanilla duels.

## Testing

- `api/tests/VanillaRulesTest.php` (engine, seeded with a `SequenceRandomSource`):
  - Initiative tie re-roll; open-draft order, skipping, uniqueness and illegal picks.
  - Expected placement on Proving Field; `scenarioTooSmall` on Training Grounds.
  - Movement: climb limit, friendly pass-through, enemy blocking, occupied destination.
  - Disengagement trigger and damage.
  - Range, line of sight, dice → wounds, defeat, annihilation.
  - Activation limits (hero + 3, any 4, all remaining), one activation per unit, auto turn pass.
- `ApiTest.php`: a scripted full match over HTTP with seeded dice (lobby → draft → battle → win); seat-token enforcement and receipt replay on new commands.
- `ScenarioTest.php`: Proving Field loads and both starts fit 7 units.
- Contracts tests: new fixtures round-trip.
- UI tests (bUnit): `DraftTable` legal-pick enabling; `GameTable` highlights and command dispatch; combat-log rendering; match-end overlay; coordinator envelope building and conflict handling.
- README: extend the two-browser walkthrough through a finished match.

Existing tests that start a match on Training Grounds move to Proving Field.
