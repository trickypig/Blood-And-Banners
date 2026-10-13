# Vanilla terrain and maps — design

Date: 2026-10-12
Status: approved in conversation; awaiting written-spec review

## Goal

Make terrain a complete, server-authoritative part of Vanilla PvP. Water, ice, poison, and lava affect movement and combat; Flying and Lava Walker become playable; and players can choose among Proving Field, a new Four Corners arena, and the existing Home Map.

The slice builds on the existing movement, forced-movement, conditions, ability, wound, and pending-choice systems.

## Scope

In scope:

- Open-water movement and Power penalties; shallow water remains ordinary terrain.
- Ice conditions, straight-line slipping, elevation effects, falls, and unit collisions.
- Poison entry and turn-end damage/recovery.
- Lava entry and turn-end damage.
- Flying and Lava Walker, including fire/water attack typing.
- Exact movement-path projection and server validation.
- A three-map Vanilla picker with Four Corners as the default.
- A new 13×11 Four Corners arena.
- Conversion of `data/Home_Map.json` into a 32×30 built-in Vanilla scenario.
- Mouse, touch, and keyboard-accessible board zoom and pan.
- Client previews, condition display, choice prompts, and combat-log lines.

Deferred:

- Terrain objects, crystals, their bonuses, and terrain-object scoring.
- Co-op and Battle Royale gameplay.
- Non-Vanilla creature abilities, even when they mention poison, fire, water, or flying.
- A general scenario browser or scenario-management UI; this slice exposes three fixed Vanilla choices.

## Product flow

Choosing **Vanilla duel** opens a battlefield picker before the game is created. It presents:

1. **Four Corners** — 13×11, terrain-heavy, selected by default.
2. **Proving Field** — 11×9, compact and introductory.
3. **Home Map** — 32×30, large-format and intended for zoom/pan play.

The selected scenario name is sent through the existing game-creation request and remains visible in the war room. Co-op and Battle Royale retain their current direct scenario selection.

## Maps

### Four Corners

`api/scenarios/four-corners.json` is compatible with `vanillaPvp` only.

- Size: 13 columns × 11 rows (143 hexes).
- Four large terrain regions occupy the corners: open water, ice, lava, and poison pits.
- A narrow neutral vertical seam and three-row neutral deployment belt form a cross through the center.
- A small central stone ridge breaks sight lines without closing the neutral route.
- Player starts sit near the middle of the left and right edges on neutral ground.
- Each start has capacity for a seven-unit army, with no unit initially placed on a hazard.
- Elevation and blockers preserve at least one neutral route between deployments and multiple routes into every terrain region.

### Proving Field

Proving Field is unchanged and remains the low-complexity Vanilla option.

### Home Map

> Coordinate representation in this section is superseded by `2026-10-13-canonical-map-coordinates-design.md`.

`data/Home_Map.json` is user-provided legacy editor data and remains unchanged as source input. A converted, checked-in built-in scenario is added at `api/scenarios/home-map.json`:

- Name: `Home Map`.
- Compatibility: `vanillaPvp` only.
- Size and terrain: preserve all 32×30 legacy hexes exactly.
- Coordinate conversion: legacy `col` → `q`, `row` → `r`, `height` → `elevation`.
- Player 1 home: `3,3`, in the safe sand region near the top-left.
- Player 2 home: `28,26`, in the safe plains region near the bottom-right.
- Both homes must pass the existing seven-unit placement-capacity check.
- Existing terrain remains unchanged; the conversion adds only scenario metadata and the two player starts.

## Terrain classification

Rules attach to painted terrain, not to the broader biome:

- `shallow-water`: no rules effect.
- `open-water`: open-water rules.
- `snow`: no rules effect.
- `ice`: ice rules.
- `basalt`: no rules effect.
- `lava`: lava rules.
- `darkness`: no rules effect.
- `poison-pits`: poison rules.

## Terrain rules

### Open water

- Entering open water ends voluntary movement after the unit enters the hex.
- Leaving open water costs two movement points: one normal step plus one additional step.
- For climb legality only, the destination is treated as one additional level higher. A normal unit cannot move directly from open water onto a hex already one level higher.
- A unit standing in open water has −1 effective Power.
- Forced movement is not stopped by open water.
- Flying ignores every open-water effect.
- Lava Walker rolls one damage die with no Defense for every open-water hex entered, including entry by a push or teleport. Shallow water never triggers this weakness.

### Ice, Unsteady, and Slipping

Entering an ice hex applies or refreshes:

- `Unsteady {expires: whileOnTerrain}`.
- `Slipping {expires: untilCleared, data: {direction: [dq, dr]}}`.

The direction is the step used to enter the first ice hex. A Slipping unit cannot voluntarily change it.

- Unsteady gives −1 Power.
- A stationary Unsteady unit makes no disengagement strike when a stable unit leaves it. A Slipping unit likewise makes no disengagement strikes.
- After entering ice, the unit continues in the locked direction until its remaining effective Move is spent or a stop rule applies.
- A push onto ice applies both conditions using the push direction. The push finishes only its own forced distance; it does not also spend the unit's Move. Slipping resumes when that unit is next activated.
- A teleport landing has no travel direction, so it applies Unsteady but not Slipping.
- A unit already Slipping resolves its straight-line movement before it may attack or use an activated ability when that unit is chosen to activate.
- A player may leave a Slipping unit unactivated; it remains Slipping into the next turn.
- Slipping is forced movement. It still resolves for a Stunned unit, although Stunned continues to block ordinary movement and apply its other effects.
- Moving onto higher ice is prohibited.
- Moving up one level onto non-ice is allowed and ends the slide on that destination.
- A drop rolls one no-Defense damage die per level descended. The slide continues in the same direction when the landing hex is ice. A non-ice landing ends the movement and clears Unsteady and Slipping.
- Moving from ice to level non-ice ends the movement and clears both ice conditions.
- A wall, sight blocker, board edge, prohibited climb, or defeat stops movement before the blocked step. Slipping clears; Unsteady remains only while the unit remains on ice.

Enemy collision:

- Stop before the occupied hex.
- The moving unit and occupant each roll one damage die with no Defense.
- Slipping clears. Unsteady remains while the mover is still on ice.

Friendly collision opens a server-owned choice:

- **Continue** — pass through the friendly unit if the next hex in the locked direction is legal and empty.
- **Stop** — stop before the occupied hex without collision damage; Slipping clears.
- **Swing** — pass through the friendly unit and leave it through a selected legal adjacent hex, which becomes the new locked direction.

Choosing Swing creates a second targeting phase listing valid exits. Choice resolution resumes the suspended movement pipeline. If Continue or Swing has no legal continuation, that option is not offered.

Flying ignores ice entry, movement, elevation, condition, and collision rules.

### Poison

- Entering poison pits applies or refreshes `Poisoned {expires: untilCleared}`.
- Poison entry ends voluntary movement after entry; forced movement continues.
- At the end of each owning-player turn, every surviving Poisoned unit rolls one poison die in stable unit-ID order.
- Each sword causes one Wound with no Defense.
- A shield clears Poisoned only if the unit is not standing in poison pits.
- A unit standing in poison pits remains Poisoned even when its roll shows a shield.

### Lava

- Every lava hex entered rolls five damage dice with no Defense. Moving across multiple lava hexes rolls separately for every hex.
- Push and teleport arrival trigger the same entry roll.
- At the end of each owning-player turn, every surviving unit still on lava rolls five more damage dice with no Defense, in stable unit-ID order.
- Lava Walker ignores lava entry and turn-end damage.
- Flying ignores lava hexes crossed during voluntary flight but cannot voluntarily end its move on lava.
- A Flying unit forced or teleported onto lava takes entry damage. If it remains there at the end of its owning player's turn, it takes turn-end damage; this never prevents the turn from ending.
- Ordinary units may enter and end on lava if they survive.

All terrain damage uses the existing Wounds pipeline. Defeat removes conditions, and defeat or victory stops the remaining movement and terrain resolution immediately.

## Ability rules

### Flying

For Thalion Windrider, Flying:

- Ignores open-water and ice rules.
- May move up two elevation levels in one step.
- May move down any number of elevation levels without fall damage.
- May cross lava without damage during voluntary flight but may not voluntarily end on lava.
- Does not ignore poison.
- Does not change combat elevation, range, line of sight, or engagement. The card's “Height 4” text has no additional digital rule in this slice.

### Lava Walker

For Ignar Duskfire, Lava Walker:

- Ignores lava entry and turn-end damage.
- Rolls one no-Defense damage die for every open-water hex entered by any movement type.
- Gains +2 Defense against fire attacks.
- Suffers −2 Defense against water attacks, floored through the existing effective-stat rules.

Flame Strike and Fireball's primary target attack have damage type `fire`. Fireball splash remains a no-Defense damage step and receives no Defense modifier. The engine supports `water` attack typing, but no current Vanilla attack uses it.

## Server design

### Board state

`Board::fromScenario` carries `biome` and `terrain` into match state alongside elevation, blockers, and player starts. `MatchEngine::normalize` supplies neutral `plains` / `grass` values for older saved board entries that lack those fields.

### Terrain module

A focused terrain module under `api/src/Rules/Vanilla/` owns pure classification and shared resolution:

- Terrain lookup and subtype predicates.
- Step cost and elevation legality for a unit.
- Entry and exit rules with movement context: `voluntary`, `push`, `teleport`, or `slip`.
- Voluntary-ending legality.
- Terrain-derived stat deltas.
- Turn-end poison and lava resolution.
- Ability exceptions through the existing ability registry.

Movement, Forced, Teleport, Stats, and turn transitions call this module rather than duplicate terrain checks.

### Path-aware movement

Movement changes from destination-only breadth-first search to canonical path options. A movement option contains:

```text
{ destination, path[], cost, hazards[] }
```

- `path` contains every entered hex, excluding the origin.
- `cost` includes open-water exit cost.
- `hazards` summarizes guaranteed entry dice, conditions, forced continuation, and stops for client display.
- The planner searches only within the unit's effective Move budget, so Home Map size does not expand work beyond the local movement radius.
- For multiple legal paths to one destination, the canonical path uses lowest movement cost, then lowest guaranteed damage dice, then stable hex-key ordering.
- Forced ice continuation is included in the projected path even when it extends beyond the initially selected ice hex.

`LegalAction` gains optional movement options while retaining target hexes for existing clients. The client sends `moveUnit {unitId, path}`. The server recomputes the canonical option and requires an exact path match.

Destination-only `moveUnit` remains accepted for terrain-neutral routes, allowing an already-open client to continue on Proving Field. A destination-only command that would interact with terrain is rejected with `movementPathRequired`.

### Entry pipeline

For every step:

1. Validate adjacency, occupancy, blocker, elevation, terrain, and ability exceptions.
2. Move the unit into the hex.
3. Apply entry damage and conditions.
4. Stop immediately on defeat or match completion.
5. Apply voluntary-stop rules.
6. Continue push or slip movement when allowed.

Ice choices store the remaining movement context in the existing `pending` state and resume through `resolveChoice`.

### Turn-end pipeline

Before the active seat's turn record changes:

1. Expire effects marked `untilEndOfTurn`, as today.
2. Run condition turn-end hooks; the Poisoned hook resolves units for the ending seat in unit-ID order.
3. Resolve units standing on lava for the ending seat in unit-ID order.
4. Stop immediately on match completion.
5. Expire `afterTurn` and `turnEnd` conditions, preserving the current Stunned lifecycle.
6. Create the next turn, emit `turnEnded`, expire `untilNextTurn` effects, and run turn-start condition handling as today.

### Events

New event payloads provide stable client narration:

- `terrainEntered {unitId, terrain, hex, movementKind}`.
- `movementStopped {unitId, hex, reason}`.
- `terrainDamage {unitId, terrain, cause, dice, faces, wounds, hex}`.
- `unitSlid {unitId, from, to, path, direction, stopped}`.
- `poisonRolled {unitId, hex, faces, wounds, cleared}`.
- `terrainIgnored {unitId, terrain, ability, hex}` only when an otherwise applicable terrain effect is prevented.

Existing `conditionApplied`, `conditionExpired`, `choiceOpened`, `choiceResolved`, wound, defeat, and victory events remain authoritative for their existing concerns.

### Errors

New command errors:

- `movementPathRequired` — a terrain route arrived without a path.
- `invalidMovementPath` — a submitted path is stale, altered, non-adjacent, or not a projected option.
- `invalidTerrainDestination` — the unit or ability cannot voluntarily end on the chosen terrain.
- `invalidSlipChoice` — the selected ice-collision option or swing exit is not currently legal.

Version conflicts retain the existing refresh-and-retry behavior.

## Client design

### Map picker

Quick Start becomes a two-stage flow for Vanilla only: choose mode, then choose battlefield. The approved card layout shows name, size, role, and concise tags. Four Corners is preselected. Back returns to game modes without creating an identity or game.

The create callback carries both `GameMode` and scenario name to `GameExperience`, which passes the chosen name to the existing session coordinator.

### Movement preview and confirmation

- Reachable destinations remain highlighted.
- Hover, keyboard focus, or the first mobile tap previews the exact projected path.
- Connected or numbered markers show every entered hex; forced ice continuation is visually distinct.
- Hazard markers summarize dice, conditions, and forced stops.
- Safe movement retains the current quick interaction.
- A route with guaranteed damage or forced sliding requires explicit confirmation before submission.
- The command sends the projected path unchanged.

### Ice choices

The existing pending-choice presentation shows Continue, Stop, and Swing. Choosing Swing changes the board to an adjacent-exit targeting state. Other commands remain locked until the server resolves the choice, including after reconnect.

### Zoom and pan

The SVG board gains a client-side viewport transform:

- Wheel and pinch zoom around the pointer or touch midpoint.
- Dragging empty board space pans.
- `+`, `−`, and **Fit** controls support keyboard and touch use.
- Pointer movement beyond the drag threshold suppresses hex activation.
- Event focus recenters only when every focused hex lies outside the current viewport.
- View state is client-only and resets when the game changes.

### Conditions, stats, and log

- Unsteady, Slipping, and Poisoned have distinct token markers and card explanations.
- Accessible hex labels name terrain, conditions, locked slip direction, and projected hazards.
- Effective Power and Defense explanations include open water, Unsteady, and Lava Walker modifiers.
- Combat-log lines cover terrain entry, movement stops, per-hex and turn-end damage, slipping, falls, collisions, poison recovery, and ability immunity without duplicating condition events.

## Testing

### PHP

- Board terrain persistence and old-state neutral normalization.
- Shallow water neutrality; every open-water rule and Flying exception.
- Lava Walker damage on each open-water entry by voluntary movement, push, and teleport.
- Ice entry direction, immediate continuation, persisted Slipping, activation priority, unactivated persistence, Stunned sliding, elevation stops, falls, exits, walls, edges, and defeat.
- Enemy collision and all valid/invalid friendly Continue, Stop, and Swing cases, including suspend/resume and reconnect state.
- Poison entry, forced traversal, turn-end wounds, shield recovery, and staying poisoned in a pit.
- Lava entry per hex, forced and teleported entry, turn-end damage, Flying behavior, Lava Walker immunity, and match completion during movement or turn end.
- Fire and water damage-type Defense modifiers.
- Canonical movement options, deterministic tie-breaking, hazard summaries, path tampering, stale paths, and destination-only compatibility.
- Four Corners dimensions, terrain regions, connectivity, and two seven-unit deployments.
- Home Map conversion fidelity, exact homes, connectivity, and two seven-unit deployments.
- Bounded movement planning on the 32×30 board.

### .NET

- Map-picker selection, default, back behavior, and game-creation payload.
- Movement path projection, mouse/keyboard/mobile preview, hazard confirmation, and command path.
- Forced ice path styling and Swing exit targeting.
- Terrain and condition markers, accessible labels, stat reasons, and combat-log lines.
- Zoom reducer math, wheel/pinch centering, pan threshold, Fit, event focus, and reset on game change.
- Contract fixtures for movement options and new events.

### Full verification

- `php api/tests/run.php`.
- Contracts and UI test projects.
- Web build.
- MAUI Windows build.
- Two-browser manual matches on all three maps.
- Manual mouse, keyboard, and touch-style navigation on Home Map.
- Refresh while an ice choice is pending and confirm both clients resume the same server-owned state.
