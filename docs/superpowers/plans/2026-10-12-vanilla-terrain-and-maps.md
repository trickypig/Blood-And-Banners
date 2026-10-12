# Vanilla Terrain and Maps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make water, ice, poison, and lava fully server-authoritative in Vanilla PvP; make Flying and Lava Walker playable; add selectable Four Corners, Proving Field, and Home Map battlefields; and make large maps comfortable to inspect and command.

**Architecture:** `Board` persists painted terrain in match state. A pure `Terrain` policy answers classification, movement, ending, and stat questions; `Movement` publishes one deterministic canonical path per reachable destination; `TerrainResolver` applies entry and turn-end effects through the existing `Wounds` and condition pipelines; and `IceMovement` owns resumable sliding and its server-owned collision choice. Forced movement and teleport call the same terrain entry resolver. Additive projections expose exact movement options and generic pending-choice data. The Blazor client consumes those projections for map selection, path preview and confirmation, ice choices, terrain feedback, and a pure viewport reducer backed by pointer/wheel event wiring.

**Tech Stack:** PHP 8.3 with the dependency-free rules engine and plain-PHP test runner; JSON scenario assets; .NET 10 Blazor, C# records, xUnit and bUnit; a small JavaScript module only for pointer capture and element bounds.

**Spec:** `docs/superpowers/specs/2026-10-12-vanilla-terrain-and-maps-design.md`

## Global Constraints

- Vanilla PvP only. Co-op and Battle Royale keep their current scenario defaults and do not gain terrain gameplay in this slice.
- PHP files start with `declare(strict_types=1);`; JSON property names are camelCase; contract changes are additive with safe defaults.
- The server is authoritative for path selection, entry order, random rolls, condition state, choices, victory, and every stop. The client sends only a path or a server-projected choice.
- Painted subtype controls rules: only `open-water`, `ice`, `poison-pits`, and `lava` are hazardous. `shallow-water`, `snow`, `darkness`, and `basalt` are neutral.
- Every terrain wound uses `Wounds::apply`; resolution stops immediately if the moving unit is defeated or the match completes.
- Every stochastic assertion uses `SequenceRandom`; never assert probabilistically.
- Movement planning is bounded by effective Move, not map dimensions. A destination has one canonical option ordered by `(cost, guaranteedDamageDice, path hex-key sequence)`.
- Submitted paths exclude the origin and include every entered hex, including projected forced ice continuation. Repeated hexes, non-adjacent steps, stale paths, or a path different from the projected canonical option are rejected.
- Destination-only movement remains compatible only when the recomputed canonical option is terrain-neutral and has no hazard or forced continuation.
- Terrain entry order is validate step, move, apply entry damage/conditions, stop on defeat/victory, apply voluntary stop, then continue push or slip.
- Turn-end order is existing end-of-turn effects, Poisoned hooks in unit-ID order, lava occupants in unit-ID order, match-completion stop, condition expiry, next turn/event, next-turn effect expiry, turn-start hooks.
- `data/Home_Map.json` is user-owned source input. Do not edit, move, delete, or commit it. Generate and commit `api/scenarios/home-map.json` from it.
- New errors are exactly `movementPathRequired`, `invalidMovementPath`, `invalidTerrainDestination`, and `invalidSlipChoice`.
- New events are exactly `terrainEntered`, `movementStopped`, `terrainDamage`, `unitSlid`, `poisonRolled`, and `terrainIgnored`, alongside existing condition/wound/choice events.
- Preserve old saved matches: `MatchEngine::normalize` supplies `biome: plains` and `terrain: grass` for board hexes missing either field.
- Keep each task green before committing. Do not fold unrelated user changes into a task commit.

## Review Focus

1. **A submitted path containing a loop or repeated hazardous hex.** It must be rejected before any dice roll so a client cannot manufacture extra lava/water damage or evade canonical tie-breaking. Task 4 adds the explicit repeated-hex/tampered-path test.
2. **The mover dies before later projected path steps.** No later terrain entry, slide, disengagement, or movement event may execute, and annihilation must remain final. Tasks 5 and 7 pin lethal lava and lethal ice-fall cases.
3. **More than one poisoned/lava unit resolves at turn end and an early one ends the match.** Unit-ID ordering must be stable and later units must not roll after completion. Task 6 tests the mixed poison/lava ordering and early stop.
4. **A friendly ice choice is saved, JSON-round-tripped, and the board changes before resolution.** Reconnect must show the same server choice, but an exit that is no longer legal must fail with `invalidSlipChoice` without mutating state. Task 8 covers persistence and stale resolution.
5. **A drag/pinch gesture ends over a commandable hex.** Movement or attack must not fire after the gesture crosses the drag threshold; keyboard hex activation must still work. Task 12 tests the reducer and component suppression behavior.

---

## File Structure

PHP rules and scenarios:

| File | Responsibility |
|---|---|
| `api/scenarios/four-corners.json` | New 13x11 terrain-heavy default Vanilla arena. |
| `api/scenarios/home-map.json` | Checked-in conversion of the user legacy map with two starts. |
| `api/tools/build-terrain-scenarios.php` | Deterministically generate the two scenario assets without editing the source map. |
| `api/src/Rules/Vanilla/Terrain.php` | Pure subtype classification, traits, step cost/legality, ending, stat and attack-type policy. |
| `api/src/Rules/Vanilla/TerrainResolver.php` | Mutating entry and turn-end damage/condition pipeline. |
| `api/src/Rules/Vanilla/IceMovement.php` | Straight-line slipping, collisions, suspend/resume, and activation-priority resolution. |
| `api/src/Rules/Vanilla/Conditions/Unsteady.php`, `Slipping.php`, `Poisoned.php` | Terrain condition hooks and metadata. |
| `api/src/Rules/Vanilla/Abilities/Flying.php`, `LavaWalker.php` | The final two Vanilla ability handlers. |
| `api/src/Rules/Vanilla/Board.php`, `MatchEngine.php`, `Movement.php`, `Forced.php`, `Stats.php`, `BattleRules.php`, `StepRunner.php`, `UnitConditions.php`, `Wounds.php` | Integrate terrain into existing rules. |
| `api/src/Game/GameService.php` | Project movement options, generic choices, conditions, terrain board data. |
| `api/tests/VanillaTerrainTest.php`, `VanillaIceTest.php` | Deterministic terrain and ice rule tests. |

.NET client:

| File | Responsibility |
|---|---|
| `src/BloodAndBanners.Contracts/GameProjection.cs`, `MatchProjection.cs` | Add movement option/hazard data and generic ice-choice fields. |
| `src/BloodAndBanners.UI/Services/GameSetupSelection.cs` | Mode plus selected scenario for Quick Start creation. |
| `src/BloodAndBanners.UI/Services/BoardViewport.cs` | Pure fit/zoom/pan/focus/gesture reducer. |
| `src/BloodAndBanners.UI/Services/ConditionText.cs`, `CombatLogFormatter.cs` | Terrain condition/stat explanations and event narration. |
| `src/BloodAndBanners.UI/Components/QuickStart.razor` (+ CSS) | Two-stage Vanilla battlefield picker. |
| `src/BloodAndBanners.UI/Components/GameExperience.razor` | Pass chosen scenario to the existing session coordinator. |
| `src/BloodAndBanners.UI/Components/GameTable.razor` (+ CSS) | Path previews, confirmations, ice choices, markers, accessible labels, viewport controls. |
| `src/BloodAndBanners.UI/wwwroot/js/boardViewport.js` | Pointer capture and board bounds interop. |

Run commands used throughout:

- PHP: `php api/tests/run.php`
- Contracts: `dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj`
- UI: `dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj`
- Web build: `dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj`
- MAUI Windows build: `dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -f net10.0-windows10.0.19041.0`

---

### Task 1: Generate and validate Four Corners and Home Map

**Files:**
- Create: `api/tools/build-terrain-scenarios.php`
- Create/generated: `api/scenarios/four-corners.json`, `api/scenarios/home-map.json`
- Modify: `api/tests/ScenarioTest.php`

**Interfaces:**
- `build-terrain-scenarios.php <home-source> <scenario-dir>` writes deterministic pretty-printed schema-v1 JSON.
- Four Corners is `name: Four Corners`, `modeCompatibility: [vanillaPvp]`, 13x11, 143 hexes, starts on safe neutral terrain.
- Home Map is `name: Home Map`, `modeCompatibility: [vanillaPvp]`, 32x30, 960 hexes, starts exactly `3,3` and `28,26`; every other source terrain/elevation value is unchanged.

- [ ] **Step 1: Add failing scenario tests.** Extend `ScenarioTest.php` with helpers that load a scenario by file name, count connected traversable hexes, and verify seven-cell deployment capacity using `Board::homes` plus `Placement`. Change the built-in count from 4 to 6 and add tests named:
  - `four corners has four hazard regions and two safe seven unit deployments`
  - `home map conversion preserves every source hex and adds only the approved homes`
  - `large built in maps remain connected`

  The fidelity assertion must compare each source tuple `(col,row,biome,terrain,height)` to generated `(q,r,biome,terrain,elevation)` and separately assert only `3,3` and `28,26` have `playerStart`.
- [ ] **Step 2: Run `php api/tests/run.php`.** Expect the built-in count and missing scenario assertions to fail.
- [ ] **Step 3: Implement the generator.** Use `LegacyMapImporter` for Home Map, then replace the name and compatibility, clear every imported `playerStart`, and set the two approved homes. Generate Four Corners by iterating every q/r pair and assigning the approved corner regions, central neutral seam/deployment belt, central stone ridge, elevations, blockers, and starts. Sort hexes by `(r,q)` and encode with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` plus a trailing newline.
- [ ] **Step 4: Run the generator without modifying its source input:**

  `php api/tools/build-terrain-scenarios.php data/Home_Map.json api/scenarios`

- [ ] **Step 5: Run `php api/tests/run.php`.** Expect all scenario tests to pass, including exact 13x11 and 32x30 dimensions, all four subtype counts greater than zero, neutral connectivity, and both deployment-capacity checks.
- [ ] **Step 6: Inspect `git diff -- data/Home_Map.json` and `git status --short`.** Expect no source-map diff and only the generator, two generated scenarios, and test edit in this task.
- [ ] **Step 7: Commit:** `git add api/tools/build-terrain-scenarios.php api/scenarios/four-corners.json api/scenarios/home-map.json api/tests/ScenarioTest.php && git commit -m "Add Four Corners and Home Map scenarios"`

---

### Task 2: Persist terrain and activate terrain ability traits

**Files:**
- Create: `api/src/Rules/Vanilla/Terrain.php`
- Create: `api/src/Rules/Vanilla/Abilities/Flying.php`, `api/src/Rules/Vanilla/Abilities/LavaWalker.php`
- Modify: `api/src/Rules/Vanilla/Board.php`, `MatchEngine.php`, `Abilities/Ability.php`, `Abilities/AbilityRegistry.php`, `Stats.php`
- Create: `api/tests/VanillaTerrainTest.php`
- Modify: `api/tests/run.php`, `VanillaTestSupport.php`

**Interfaces:**
- Board entries gain required `biome` and `terrain` strings.
- `Ability` gains no-op `ignoresTerrain(string $terrain, string $movementKind): bool` and `defenseDeltaForDamageType(string $damageType): int` hooks.
- `Terrain::type(array $hex): ?string`, `hasTrait(array $state, string $unitId, string $ability): bool`, and `statDelta(array $state, string $unitId, string $stat, array $context = []): int`.
- `vanilla_board` accepts optional `$terrains` keyed by hex and defaults to `['biome'=>'plains','terrain'=>'grass']`.

- [ ] **Step 1: Register `VanillaTerrainTest.php` and write failing tests** for Board copying biome/terrain, old-state normalization, subtype classification, shallow-water/snow/basalt/darkness neutrality, open-water `-1 Power`, and Lava Walker `+2/-2 Defense` only when `Stats::effective(..., ['damageType'=>'fire'|'water'])` supplies the attack type. Unsteady's condition delta belongs to Task 7.
- [ ] **Step 2: Run `php api/tests/run.php`.** Expect missing `Terrain`, missing traits, and missing board fields.
- [ ] **Step 3: Implement pure policy.** `Terrain::type` returns one of `water`, `ice`, `poison`, `lava`, or null from the exact painted terrain strings. `Terrain::statDelta` returns open-water/Unsteady Power penalties and asks the unit's registered abilities for contextual defense deltas. Keep `Stats::effective` as the single floor-at-zero aggregation point.
- [ ] **Step 4: Implement and register abilities.** Flying ignores `water` and `ice` for movement; Lava Walker ignores `lava`, identifies its open-water weakness for the resolver, and returns fire/water defense deltas. Both now project as playable because they have registered handlers. The movement-kind argument must make Flying ignore crossed voluntary lava but not push/teleport lava.
- [ ] **Step 5: Normalize old boards.** In `MatchEngine::normalize`, map each board entry through defaults without overwriting present values.
- [ ] **Step 6: Run `php api/tests/run.php`.** Expect the new tests and all prior ability/forced-movement tests to pass.
- [ ] **Step 7: Commit:** `git add api/src/Rules/Vanilla api/tests && git commit -m "Add terrain policy and movement traits"`

---

### Task 3: Type fire and water attacks without changing splash damage

**Files:**
- Modify: `api/src/Rules/Vanilla/StepRunner.php`
- Modify: `api/src/Rules/Vanilla/Abilities/FixedStrike.php`, `AbilityRegistry.php`, `Fireball.php`
- Test: `api/tests/VanillaTerrainTest.php`, `VanillaAbilityTest.php`

**Interfaces:**
- `StepRunner::strike(..., ?string $damageType = null)` persists `damageType` in the step/context and passes it to defender `Stats::effective`.
- Flame Strike and Fireball primary strikes use `damageType: fire`; ordinary attacks use null. The Fireball `splash` op remains no-defense and untyped.
- The engine accepts `water` in the same strike context even though no current Vanilla card emits it.

- [ ] **Step 1: Add failing deterministic combat tests.** A Lava Walker with Defense 1 rolls three defense dice against a fire strike and zero against a water strike after flooring; an ordinary defender is unchanged. Add a Fireball regression proving only its primary defender gets the +2 Defense and splash still rolls no defense.
- [ ] **Step 2: Run `php api/tests/run.php`.** Expect the new defense-face counts to fail.
- [ ] **Step 3: Thread `damageType` through strike construction, saved pending steps, and strike execution.** Include it in `attackResolved` only when non-null so older event fixtures remain valid.
- [ ] **Step 4: Mark Flame Strike and Fireball primary strike factories as `fire`.** Extend `FixedStrike` with an optional damage type and pass `fire` only in the registry's `new FixedStrike('Flame Strike', 3, 5, 'fire')`; Bolt of Annihilation remains untyped. Do not alter Fireball splash.
- [ ] **Step 5: Run `php api/tests/run.php`.** Expect all ability tests, including Moonlight Glare suspend/resume, to pass.
- [ ] **Step 6: Commit:** `git add api/src/Rules/Vanilla api/tests && git commit -m "Apply elemental defense to typed attacks"`

---

### Task 4: Publish and validate canonical movement paths

**Files:**
- Modify: `api/src/Rules/Vanilla/Movement.php`, `BattleRules.php`
- Test: `api/tests/VanillaTerrainTest.php`, `VanillaBattleTest.php`

**Interfaces:**
- `Movement::options(array $state, string $unitId, int $budget): array` returns sorted list entries `{destination,path,cost,hazards}`.
- `Movement::canonical(array $state, string $unitId, string $destination, int $budget): ?array` returns the published option.
- `Terrain::stepCost(...)`, `Terrain::canEnter(...)`, and `Terrain::canEnd(...)` are pure planner/runtime policy shared by this task and later resolvers.
- A hazard entry is `{kind, terrain, hex, dice, forced, stops}`; `dice` is guaranteed no-defense dice, not expected wounds.
- `moveUnit` accepts `{unitId,path}`; legacy `{unitId,to}` is accepted only for a neutral canonical option.

- [ ] **Step 1: Write failing planner tests** for open-water entry stop, two-point exit cost, effective +1 climb into water, Flying water/ice and climb-up-two exceptions, Flying unlimited descent, poison stop, Flying lava crossing/no lava destination, multi-lava hazard accumulation, canonical tie-breaking, forced straight ice continuation in the returned path/hazards, and budget-bounded search on Home Map.
- [ ] **Step 2: Add command validation tests** for exact path acceptance, terrain route without path (`movementPathRequired`), altered/non-adjacent/stale/repeated paths (`invalidMovementPath`), Flying lava destination (`invalidTerrainDestination`), and destination-only neutral Proving Field compatibility. The repeated lava path test must also assert the random source was not consumed and state/events are unchanged.
- [ ] **Step 3: Run `php api/tests/run.php`.** Expect movement option and error assertions to fail.
- [ ] **Step 4: Replace destination BFS with a bounded path-state search.** Track current hex, cost, path, hazard-dice total, forced-direction metadata, and visited hexes. Project ice continuation here as pure path simulation; Task 7 will execute the same policy and add conditions/choices at runtime. Apply a stable priority queue ordering of cost, guaranteed dice, then joined path keys. Keep at most the best path-state per equivalent planner state, not merely per destination, so water exit costs and ice direction are correct.
- [ ] **Step 5: Change legal actions.** A `moveUnit` action retains `targetHexes` and adds `movementOptions`; derive target hexes from option destinations. Centralize payload validation in `BattleRules::movementOption` before mutation.
- [ ] **Step 6: Preserve current disengagement semantics but use the validated exact path.** Each enemy can make at most one parting strike, at the first step leaving its adjacency; a lethal strike stops before terrain processing for that step.
- [ ] **Step 7: Run `php api/tests/run.php`.** Expect all planner/command tests and prior neutral movement tests to pass.
- [ ] **Step 8: Commit:** `git add api/src/Rules/Vanilla/Movement.php api/src/Rules/Vanilla/BattleRules.php api/tests && git commit -m "Make Vanilla movement path authoritative"`

---

### Task 5: Resolve water, lava, poison entry, push, and teleport consistently

**Files:**
- Create: `api/src/Rules/Vanilla/TerrainResolver.php`, `Conditions/Poisoned.php`
- Modify: `api/src/Rules/Vanilla/Conditions/ConditionRegistry.php`, `BattleRules.php`, `Forced.php`, `StepRunner.php`, `Wounds.php`
- Test: `api/tests/VanillaTerrainTest.php`, `VanillaForcedTest.php`

**Interfaces:**
- `TerrainResolver::enter(array $state, string $unitId, string $from, string $to, string $movementKind, ?array $direction, RandomSource $random, array &$events): array` returns `{state,stop}`.
- Movement kinds are exactly `voluntary`, `push`, `teleport`, and `slip`.
- Entry emits `terrainEntered`, then zero or one `terrainDamage`/condition events, and optional `movementStopped` in deterministic order.

- [ ] **Step 1: Add failing entry tests.** Cover shallow water neutrality; open-water voluntary stop; forced water continuation; Lava Walker one die on every open-water entry for voluntary movement, each push step, and teleport arrival; five lava dice per entered hex; ordinary/Flying/Lava-Walker differences; poison apply/refresh; poison voluntary stop and forced continuation.
- [ ] **Step 2: Add the lethal mid-path review test.** Entering the first of two lava hexes defeats the last moving unit: assert no second `terrainEntered`, no final `unitMoved`, match completed once, pending null, and no further random reads.
- [ ] **Step 3: Run `php api/tests/run.php`.** Expect missing resolver and integration failures.
- [ ] **Step 4: Implement `TerrainResolver::enter` and register a passive `Poisoned` condition.** Move the unit before effects, emit the authoritative entry, roll with `CustomDice`, use `Wounds::apply`, and determine stop only after checking defeat/result. Apply/refresh Poisoned on poison entry; Task 6 adds its turn-end hook. Emit `terrainIgnored` only for a real Flying/Lava Walker prevention.
- [ ] **Step 5: Route voluntary movement through the resolver one validated path step at a time.** Emit one final `unitMoved` with the traversed path and existing disengagement details only when resolution remains active.
- [ ] **Step 6: Integrate forced movement and teleport.** `Forced::push` calls the resolver after each successful step and stops only on defeat/result or its own distance/block; `StepRunner::teleportStep` calls it after setting the arrival and skips remaining attack steps if the result completes.
- [ ] **Step 7: Run `php api/tests/run.php`.** Expect all terrain, force, teleport, and old combat tests to pass.
- [ ] **Step 8: Commit:** `git add api/src/Rules/Vanilla api/tests && git commit -m "Resolve terrain on every unit entry"`

---

### Task 6: Resolve poison and lava at the owning player's turn end

**Files:**
- Modify: `api/src/Rules/Vanilla/Conditions/Poisoned.php`, `UnitConditions.php`, `TerrainResolver.php`, `BattleRules.php`
- Test: `api/tests/VanillaTerrainTest.php`, `VanillaForcedTest.php`

**Interfaces:**
- `Poisoned` expires `untilCleared`; `UnitConditions::clear` removes one named condition with the existing expiry event.
- `TerrainResolver::atTurnEnd(array $state, string $seatId, RandomSource $random, array &$events): array` resolves poison through the condition hook and lava after it.
- `poisonRolled` contains `{unitId,hex,faces,wounds,cleared}`; lava uses `terrainDamage` with `cause: turnEnd`, `dice: 5`.

- [ ] **Step 1: Write failing poison tests.** Sword wounds without defense; shield clears only off poison; shield in a pit does not clear; blank does neither; defeated units lose conditions; multiple poisoned units resolve in stable unit-ID order.
- [ ] **Step 2: Write failing lava tests.** Every surviving ending-seat unit on lava rolls exactly five dice; Lava Walker emits `terrainIgnored` and takes none; a Flying unit is not exempt; other seat units do not roll.
- [ ] **Step 3: Add the mixed-order review test.** Set `a1` poisoned off-pit and `a2` on lava with lethal remaining health. Assert poison event(s) precede lava, `a2` completes the match, a later `a3` never rolls, no `turnEnded` emits, and the active turn does not advance.
- [ ] **Step 4: Run `php api/tests/run.php`.** Expect turn-end terrain assertions to fail.
- [ ] **Step 5: Give `Poisoned` its turn-end hook and implement the exact pipeline in `BattleRules::passTurn`, changing it to accept `RandomSource` and events.** Preserve existing effect and Stunned ordering around the new hooks. Return early on match completion.
- [ ] **Step 6: Run `php api/tests/run.php`.** Expect terrain tests and the prior Stunned expiry tests to pass with stable event order.
- [ ] **Step 7: Commit:** `git add api/src/Rules/Vanilla api/tests && git commit -m "Resolve poison and lava at turn end"`

---

### Task 7: Implement ice conditions and uninterrupted sliding

**Files:**
- Create: `api/src/Rules/Vanilla/Conditions/Unsteady.php`, `Slipping.php`
- Create: `api/src/Rules/Vanilla/IceMovement.php`
- Modify: `api/src/Rules/Vanilla/Conditions/ConditionRegistry.php`, `UnitConditions.php`, `TerrainResolver.php`, `Movement.php`, `BattleRules.php`, `Forced.php`
- Create: `api/tests/VanillaIceTest.php`
- Modify: `api/tests/run.php`

**Interfaces:**
- `Slipping` stores `data.direction: [dq,dr]`; both ice conditions use the approved expiry markers.
- `IceMovement::continue(array $state, string $unitId, int $remainingMove, RandomSource $random, array &$events): array` returns `{state,path,remainingMove,pending}`.
- `IceMovement::beforeActivation(...)` resolves saved Slipping before attacks/abilities become legal.
- `unitSlid` contains `{unitId,from,to,path,direction,stopped}`.

- [ ] **Step 1: Write failing condition/entry tests.** Direction comes from first ice entry; re-entry refreshes rather than stacks; teleport applies Unsteady only; push applies both with push direction but does not spend Move; Flying applies neither; both Unsteady and Slipping suppress disengagement strikes.
- [ ] **Step 2: Write failing continuation tests.** Continue straight through level ice; prohibit higher ice; exit onto level or +1 non-ice and clear both; stop before +2 climb/wall/edge and clear Slipping only; roll one die per descended level and continue only when the landing is ice.
- [ ] **Step 3: Add activation lifecycle tests.** An unactivated slider survives turn changes; activation resolves slide before attack/ability legal actions; Stunned still slides but remains unable to move afterward; push-created Slipping resumes only on activation.
- [ ] **Step 4: Add lethal fall test.** A slide drops two levels, first fall die defeats the last unit, and no later path/event/random roll occurs.
- [ ] **Step 5: Run `php api/tests/run.php`.** Expect missing ice classes and behavior.
- [ ] **Step 6: Implement conditions and ice engine.** Reuse `TerrainResolver::enter(..., 'slip', direction, ...)`; use the same elevation and occupancy policy as planning; consume remaining effective Move by actual step cost; clear/retain conditions based on the final painted hex.
- [ ] **Step 7: Integrate activation priority.** `activate` may return a resolved state or pending choice; only after sliding completes may it mark the unit active and expose attacks/abilities. Ensure legal actions for a Slipping unactivated unit offer activation but not direct commands.
- [ ] **Step 8: Run `php api/tests/run.php`.** Expect all ice, Stunned, movement, and forced movement tests to pass.
- [ ] **Step 9: Commit:** `git add api/src/Rules/Vanilla api/tests && git commit -m "Add ice conditions and straight-line sliding"`

---

### Task 8: Suspend and resume ice collisions through server-owned choices

**Files:**
- Modify: `api/src/Rules/Vanilla/IceMovement.php`, `BattleRules.php`, `MatchEngine.php`
- Test: `api/tests/VanillaIceTest.php`, `VanillaMatchServiceTest.php`

**Interfaces:**
- Pending ice state is `{choice,context}` where choice is `{choiceId,seatId,kind:'iceCollision',unitId,options,targetHexes,openedAt}` and context contains current hex, direction, remaining Move, traversed path, and blocker unit ID.
- Options are `continue`, `stop`, and `swing:<hex>`; targetHexes lists the legal swing exits. The client later groups `swing:*` into a second targeting phase.
- `IceMovement::resolveChoice(..., string $option, ...)` revalidates the entire continuation against current state and throws `invalidSlipChoice` without mutation when stale/illegal.

- [ ] **Step 1: Write failing enemy collision tests.** Stop before enemy; one no-defense die for mover and occupant; clear Slipping; retain Unsteady on ice; stop immediately if either wound completes the match.
- [ ] **Step 2: Write failing friendly-choice tests.** Offer Continue only when the forward exit after the ally is legal/empty; Stop always; offer one `swing:<hex>` per legal adjacent exit excluding origin/blocked/higher-ice options; resolve each valid branch and preserve remaining Move/direction correctly.
- [ ] **Step 3: Write invalid tests.** Wrong seat, unknown choice, forged swing exit, and no-longer-legal continue/swing produce existing choice errors or `invalidSlipChoice` and leave state byte-for-byte unchanged.
- [ ] **Step 4: Add persistence review test.** JSON-round-trip the pending state, assert legal actions remain only `resolveChoice`, then occupy a formerly valid exit and assert resolution rejects without clearing pending.
- [ ] **Step 5: Run `php api/tests/run.php`.** Expect choice/collision failures.
- [ ] **Step 6: Implement suspend/resume in `IceMovement`, not `StepRunner`.** Keep attack choices under `StepRunner`; branch `BattleRules::resolveChoice` by pending choice kind. Emit standard `choiceOpened` and `choiceResolved` plus the eventual `unitSlid`.
- [ ] **Step 7: Normalize pending ice context additively.** Do not rewrite or discard existing Moonlight Glare `{choice,steps}` pending state.
- [ ] **Step 8: Run `php api/tests/run.php`.** Expect ice, Glare, service serialization, and command idempotency tests all green.
- [ ] **Step 9: Commit:** `git add api/src/Rules/Vanilla api/tests && git commit -m "Resume ice collisions from pending choices"`

---

### Task 9: Project movement paths, hazards, and generic pending choices

**Files:**
- Modify: `api/src/Game/GameService.php`
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs`, `MatchProjection.cs`
- Modify: `tests/fixtures/game-projection.json`
- Test: `api/tests/VanillaMatchServiceTest.php`, `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`

**Interfaces:**
- Add `MovementHazardProjection(string Kind,string Terrain,string Hex,int Dice,bool Forced,bool Stops)`.
- Add `MovementOptionProjection(string Destination,IReadOnlyList<string> Path,int Cost,IReadOnlyList<MovementHazardProjection> Hazards)`.
- Add `LegalAction.MovementOptions { get; init; } = []`.
- Add `PendingChoiceProjection.TargetHexes { get; init; } = []`.
- Make `PendingChoiceSummary.Kind` explicit and `KeepableAt` nullable so ice choices do not masquerade as timed Glare choices.
- Conditions project their `data`, including slip direction, through additive `ConditionProjection.Data`.

- [ ] **Step 1: Add failing PHP projection tests.** Assert player projections include exact canonical movement paths/hazards; spectator and wrong-seat projections do not leak command actions; owning ice player gets full options/targets/context-safe fields; opponent gets a non-actionable summary with kind and null keepableAt.
- [ ] **Step 2: Update the JSON fixture first** with one neutral option, one five-die lava hazard option, `Slipping` direction data, and an ice pending-choice sample. Add C# deserialization assertions for every new field and for an old fixture omitting all new fields.
- [ ] **Step 3: Run PHP and contract tests.** Expect missing projection members/shape failures.
- [ ] **Step 4: Implement additive service projection.** Stop assuming every choice has `side`, `faces`, or `openedAt`; only expose timed opponent keep for `moonlightGlare`. Preserve legal-action extra keys rather than rebuilding a narrow shape.
- [ ] **Step 5: Implement C# records/defaults.** Use `JsonElement?` or a typed `ConditionDataProjection` with optional direction; prefer the typed record so GameTable does not parse JSON.
- [ ] **Step 6: Run `php api/tests/run.php` and the contracts test project.** Expect both green.
- [ ] **Step 7: Commit:** `git add api/src/Game/GameService.php api/tests src/BloodAndBanners.Contracts tests/fixtures tests/BloodAndBanners.Contracts.Tests && git commit -m "Project terrain movement and ice choices"`

---

### Task 10: Add the two-stage Vanilla battlefield picker

**Files:**
- Create: `src/BloodAndBanners.UI/Services/GameSetupSelection.cs`
- Modify: `src/BloodAndBanners.UI/Components/QuickStart.razor`, `QuickStart.razor.css`, `GameExperience.razor`
- Test: `tests/BloodAndBanners.UI.Tests/GameExperienceTests.cs`

**Interfaces:**
- `public sealed record GameSetupSelection(GameMode Mode, string ScenarioName);`
- `QuickStart.OnCreate` becomes `EventCallback<GameSetupSelection>`.
- Vanilla choices are fixed ordered records: Four Corners (13x11, default), Proving Field (11x9), Home Map (32x30). Coop/Battle Royale immediately emit their existing defaults.

- [ ] **Step 1: Add failing bUnit tests** proving Vanilla first opens the picker without calling the coordinator, Four Corners is selected by default, selecting each card changes the submitted scenario, Back returns to mode selection without creation, and Coop/Battle Royale still create directly with current scenarios.
- [ ] **Step 2: Extend `FakeSessionCoordinator`** to capture `(deviceId, mode, scenarioName)` and return a lobby projection from `CreateGuestGameAsync`.
- [ ] **Step 3: Run the UI test project.** Expect callback/type and picker assertions to fail.
- [ ] **Step 4: Implement the two-stage component.** Cards show name, dimensions, role copy, and tags; use radio semantics with visible selected state; focus the picker heading after transition; Back restores focus to Vanilla Duel. Disable every action while busy.
- [ ] **Step 5: Change `GameExperience.CreateGame`** to accept the selection and pass its scenario unchanged. Retain `DefaultScenario` only for direct non-Vanilla creation or remove it if now unused.
- [ ] **Step 6: Run UI tests.** Expect all creation/join/reconnect tests green.
- [ ] **Step 7: Commit:** `git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests && git commit -m "Add Vanilla battlefield selection"`

---

### Task 11: Render path previews, hazard confirmation, and ice choices

**Files:**
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Modify: `src/BloodAndBanners.UI/Services/ConditionText.cs`
- Test: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`

**Interfaces:**
- GameTable selects movement from `LegalAction.MovementOptions`; it never reconstructs paths.
- Safe destination sends immediately on activation; a path with `Dice > 0`, `Forced`, or `Stops` requires a preview then explicit Confirm.
- `moveUnit` payload is `{unitId,path}`.
- An ice choice renders Continue/Stop directly and a Swing button that enters targeting over server-projected `TargetHexes`; selecting an exit sends `option: swing:<hex>`.

- [ ] **Step 1: Add failing bUnit tests** for mouse hover and keyboard focus path preview, mobile first-tap preview, numbered/connected path markers, distinct forced-ice styling, hazard text/dice total, safe direct send, hazardous confirmation/cancel, and exact path array in the command payload.
- [ ] **Step 2: Add pending-choice tests.** Continue/Stop send correct options; Swing exposes only projected exits; a swing exit sends `swing:q,r`; reconnect with pending ice choice renders the same prompt and locks all other commands.
- [ ] **Step 3: Add accessibility tests.** Hex labels include painted terrain and current projected hazard; Slipping label includes direction; all confirmation/choice controls are keyboard buttons with status/dialog labeling.
- [ ] **Step 4: Run UI tests.** Expect preview and choice assertions to fail.
- [ ] **Step 5: Implement component state** for `_previewedMovement`, `_confirmingMovement`, and `_swingChoice`; clear it on projection version, selected unit, command, cancel, or targeting-mode change. Use option destination lookup, never client geometry, for movement paths.
- [ ] **Step 6: Implement SVG/CSS markers.** Use stable `data-path-step`, `data-hazard`, and `forced-slide` selectors so tests and visual styling remain decoupled from glyph choice.
- [ ] **Step 7: Extend `ConditionText`** with Unsteady, Slipping, and Poisoned summaries/expiry and stat delta. Replace the single generic condition glyph with distinct labeled markers while retaining card text.
- [ ] **Step 8: Run UI tests and contracts tests.** Expect green.
- [ ] **Step 9: Commit:** `git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests && git commit -m "Preview terrain paths and resolve ice choices"`

---

### Task 12: Add fit, centered zoom, drag pan, and pinch gestures

**Files:**
- Create: `src/BloodAndBanners.UI/Services/BoardViewport.cs`
- Create: `src/BloodAndBanners.UI/wwwroot/js/boardViewport.js`
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Test: `tests/BloodAndBanners.UI.Tests/BoardViewportTests.cs`, `GameTableTests.cs`

**Interfaces:**
- `BoardViewport` stores world center, scale, container size, board bounds, active pointer(s), and `SuppressActivation`.
- Pure methods: `Fit`, `ZoomAt`, `BeginPointer`, `MovePointer`, `EndPointer`, `PanBy`, `EnsureVisible`, `ResetForGame`.
- Scale clamps to approved min/max; drag threshold is 6 CSS pixels; pinch zoom uses touch midpoint and initial distance.
- JS exports only `capturePointer(element,pointerId)`, `releasePointer(...)`, and `bounds(element)`.

- [ ] **Step 1: Write pure reducer tests.** Fit contains all board bounds with padding; wheel zoom preserves the world point under the cursor; clamp works; pan converts screen delta by scale; pinch midpoint stays fixed; focus moves only when every focused hex is outside; a new game ID resets to Fit.
- [ ] **Step 2: Add the gesture review tests.** Pointer movement below 6px still allows hex activation; movement at/above 6px sets suppression until pointer-up and prevents the following click command; pinch always suppresses activation; keyboard Enter still selects/sends normally.
- [ ] **Step 3: Run UI tests.** Expect missing reducer and Fit/gesture controls.
- [ ] **Step 4: Implement the pure service first.** Represent the SVG by a changing `viewBox`; keep rendering size responsive to the panel. Avoid reading the DOM in reducer code.
- [ ] **Step 5: Add the tiny JS module and initialize/dispose it through `IJSRuntime`.** Use pointer capture only; all view math remains testable C#.
- [ ] **Step 6: Wire wheel/pointer/touch and controls.** Rename Reset to Fit, retain `+`/`-`, add accessible pressed/status text, use `touch-action: none` only on the board interaction surface, and pan only when starting on empty board space.
- [ ] **Step 7: Recenter for followed events only when all affected hex centers are outside the current visible world rectangle.** Manual pan invokes the existing follow-events pause callback.
- [ ] **Step 8: Run UI tests and Web build.** Expect green and no JS asset/build errors.
- [ ] **Step 9: Commit:** `git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests && git commit -m "Add board zoom and pan viewport"`

---

### Task 13: Narrate terrain events and complete end-to-end verification

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`
- Test: `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`
- Modify: `README.md`

**Interfaces:**
- Formatter lines cover terrain entry, stop, per-entry/turn-end damage, slide/fall/collision summary, poison wound/recovery, and terrain immunity. Condition apply/expire remains the sole condition narration.
- Malformed or partial terrain event payloads return null, matching existing formatter behavior.

- [ ] **Step 1: Add table-driven formatter tests** for all six new event kinds and each relevant cause/reason, including 0/1/many wounds, poison cleared/not-cleared, Lava Walker/Flying immunity, and malformed payloads.
- [ ] **Step 2: Run UI tests.** Expect null or missing terrain narration.
- [ ] **Step 3: Implement concise, non-duplicative lines.** Resolve unit names through the projection and use existing face/wound helpers. `terrainEntered` may be silent when the immediately following damage/condition event already tells the full story; document that choice in the switch tests.
- [ ] **Step 4: Update README** with the three Vanilla maps, four painted terrain rules, path confirmation, ice choices, and mouse/touch/keyboard viewport controls.
- [ ] **Step 5: Run the complete automated suite:**

  ```text
  php api/tests/run.php
  dotnet test tests/BloodAndBanners.Contracts.Tests/BloodAndBanners.Contracts.Tests.csproj
  dotnet test tests/BloodAndBanners.UI.Tests/BloodAndBanners.UI.Tests.csproj
  dotnet build src/BloodAndBanners.Web/BloodAndBanners.Web.csproj
  dotnet build src/BloodAndBanners.Maui/BloodAndBanners.Maui.csproj -f net10.0-windows10.0.19041.0
  ```

  Expect zero failures, zero build errors, and no newly introduced warnings.
- [ ] **Step 6: Run focused manual verification in two browsers.** Create each map; verify selected name in war room; play across every hazard; refresh during a friendly ice choice; verify the same choice resumes; exercise mouse wheel/drag, keyboard Fit/zoom/hex activation, and touch-style pan/pinch on Home Map.
- [ ] **Step 7: Inspect compatibility.** Load an old neutral saved match/fixture, issue destination-only movement on Proving Field, and verify it remains playable. Confirm `git diff -- data/Home_Map.json` is empty and `git status --short` contains no generated or accidental artifacts.
- [ ] **Step 8: Commit:** `git add src/BloodAndBanners.UI/Services/CombatLogFormatter.cs tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs README.md && git commit -m "Document and narrate terrain gameplay"`

---

## Final Review Checklist

- [ ] Every approved terrain subtype rule has at least one deterministic PHP test and every exception has a counter-test.
- [ ] Every random rule asserts exact dice count, face consumption, no-defense behavior, event payload, and early-stop behavior.
- [ ] Planner output, command input, service projection, C# contracts, and UI payload all agree that `path` excludes origin and includes forced continuation.
- [ ] Moonlight Glare and ice choices both survive serialization and remain distinct in projection/UI behavior.
- [ ] Four Corners is the default but no game is created until battlefield confirmation; direct non-Vanilla creation is unchanged.
- [ ] Home Map source is untouched; generated conversion is deterministic and placement-capable.
- [ ] The board is usable with mouse, touch, and keyboard, and gestures cannot leak into game commands.
- [ ] Full test/build evidence is recorded before declaring the slice complete.
