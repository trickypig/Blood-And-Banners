# AI Void Lord Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The host of a Co-op war room can seat one of four AI personalities as the Void Lord. The AI builds a themed horde, then plays every Void Lord turn step by step, at a pace the host sets.

**Architecture:** A new engine-side package `api/src/Ai/` holds pure functions over the existing state arrays:
- personalities (data);
- a horde builder that sees only the board, the legal placements and the budget;
- an evaluator;
- a Monte-Carlo simulator over `MatchEngine::apply`;
- a turn planner that returns the next single command.

`GameService` stores the AI seat as an ordinary membership with `ai_personality` set. It builds the horde at Start, and it advances the AI by one command whenever any client polls once the host's pause has elapsed. The client gains an AI picker in the war room, a pace control in the ☰ menu, and faster polling while the AI acts.

**Tech Stack:** PHP 8.1+ (dependency-free, `api/tests/run.php` harness), SQLite/MySQL migrations, .NET 10 Blazor (Contracts + UI, bUnit tests in `tests/BloodAndBanners.UI.Tests`).

**Spec:** `docs/superpowers/specs/2026-10-29-ai-void-lord-design.md`

## Global Constraints

- The AI knows only what a human Void Lord knows. During prep it never reads hero draft picks. It acts **only** through commands in `legalActions` / `legalPreBattle` for its seat, via `MatchEngine::apply`.
- Same budget (`CoopMode::budget`), spawn zones, placement rules and allowance (`CoopMode::allowance`, ≤5 activations) as a human.
- Four personalities ship together: `ravager` (The Ravager, easy), `warden` (The Warden, medium), `huntress` (The Huntress, hard), `hollowKing` (The Hollow King, hardest).
- Paces: `slow` = 2000 ms, `normal` = 1000 ms (default), `fast` = 300 ms between AI steps. The host sets the pace in the war room and in the ☰ menu. Others only see it.
- One AI command per step. No background workers: a step runs only inside a client poll.
- AI thinking per step: soft target ~1000 ms, hard cap `TurnPlanner::STEP_BUDGET_MS = 1500`. On timeout it uses the best option found so far.
- Hex biome → monster biome is a strong preference, not a rule ("doesn't have to be strict"). Mapping: ice→Ice; water→River; lava→Lava; jungle→Forest (swamp terrain→Swamp); darkness→Void (poison-pits terrain→Swamp); sand→Wasteland; stone→Hills, Mountain; plains→Hills, Mountain.
- Must work on built-in **and** player-made Co-op maps that pass `MapModeCheck`.
- Old APKs/PWAs must not break: the AI seat must look like a normal named Void Lord membership to them.
- Anti-goals: personalities feeling the same; monsters wandering or making silly decisions.
- PHP tests: `php api/tests/run.php`. New test files are `require`d in `api/tests/run.php` and their `*_tests()` merged into `$tests`. Test style: `'sentence name' => static function (): void { … }` with `expect_true` / `expect_same` / `expect_game_error`.

## Review Focus

1. **Host leaves a lobby with an AI seated.** The AI must never inherit host (`vacateLobbySeat` picks `$remaining[0]`). A room left with only the AI is deleted. Test in Task 6.
2. **Concurrent or rapid polls.** Never two AI steps inside one pause. A failed or conflicting AI step never turns a poll into an error response. Test in Task 7.
3. **The planner throws or produces a rejected command mid-activation.** The service falls back to `endActivation`, then `endTurn`, so the match never stalls on the AI. Test in Task 7.
4. **A pending choice addressed to the AI seat during a hero's turn** (e.g. `iceCollision` after a hero pushes a monster onto ice, `itemPickup`). The AI resolves it on the next due poll. Tests in Task 4 (planner) and Task 7 (service).
5. **A custom map with a tiny spawn zone, or a budget the zone can't absorb.** The horde is still non-empty and confirm succeeds; leftover points are simply unspent. Test in Task 3.

---

### Task 1: Personalities and the biome map

**Files:**
- Create: `api/src/Ai/Personality.php`, `api/src/Ai/Personalities.php`, `api/src/Ai/BiomeMap.php`, `api/src/Ai/HordePackages.php`
- Test: `api/tests/AiPersonalityTest.php` (+ register in `api/tests/run.php`)

**Interfaces:**
- Produces:
  - `final class Personality` (readonly props): `string $id, string $name, string $difficulty, string $pitch, array $weights, array $horde, array $sharpness, array $stance`.
  - `Personalities::all(): array<string, Personality>` (keys in order ravager, warden, huntress, hollowKing); `Personalities::get(string $id): ?Personality`; `Personalities::catalogue(): list<array{id,name,difficulty,pitch}>` for the projection.
  - `BiomeMap::monsterBiomes(string $hexBiome, string $terrain): list<string>` (ordered preference; `[]` for unknown).
  - `HordePackages::all(): list<array{name:string, biome:string, tags:list<string>, creatures:list<string>}>`. `creatures` lists names, repeats allowed, and the first entry is the package's core.

Starting values (the builder tunes them in Task 5; the tests pin only shape and ordering):

| key | ravager | warden | huntress | hollowKing |
|---|---|---|---|---|
| difficulty | easy | medium | hard | hardest |
| weights.damage | 1.0 | 0.8 | 1.0 | 1.0 |
| weights.kill | 3 | 3 | 4 | 4 |
| weights.loss (per tier point lost) | 0.4 | 1.2 | 1.0 | 1.0 |
| weights.portalGuard | 0 | 1.5 | 0.5 | 0.8 |
| weights.advance | 1.0 | 0.1 | 0.3 | 0.5 |
| weights.cohesion | 0.2 | 0.8 | 0.3 | 0.6 |
| weights.cover | 0 | 0.2 | 1.2 | 0.5 |
| weights.threat | 0.2 | 1.0 | 1.0 | 1.2 |
| weights.focus | 0.5 | 0.5 | 1.5 | 1.0 |
| horde.shares (common/elite/boss) | .75/.25/0 | .25/.5/.25 | .4/.45/.15 | .3/.4/.3 |
| horde.placement | forward | portal | cover | combo |
| horde.tag | swarm | fortress | ambush | combo |
| horde.offBiome | 0.1 | 0.1 | 0.2 | 0.4 |
| sharpness.candidates | 8 | 16 | 24 | 40 |
| sharpness.samples | 1 | 2 | 3 | 4 |
| sharpness.blunder | 0.25 | 0.10 | 0.05 | 0 |
| sharpness.pairs (two-unit focus-fire lookahead) | false | false | true | true |
| stance | always attack | hold until a hero is within 6 hexes of any monster, or round > 3 | hold until a hero is within reach of a monster's move+range, or round > 2 | patient until void is ahead (void HP share > hero HP share) or round > 3, then attack with advance ×3, portalGuard ×0.5 |

Pitches: use the spec's "Feel" column, shortened to one line each.

Packages (starting set; exact creature names from `data/gameData.json`):
- **Ice:** Wolf pack [swarm, ambush] = Dire Wolf, Ice Wolf ×3. Storm front [fortress] = Storm Giant, Greater Ice Elemental, Lesser Ice Elemental ×2.
- **River:** The deep [ambush] = Kraken, Kraken Tentacle (Greater), Kraken Tentacle (Lesser) ×2. Tide court [combo] = Ewashia, Mistress of Tides, Mermaid, Merman ×2.
- **Swamp:** Mycelium [combo, fortress] = Myconid, Shroom Kicker ×2, Spore Sprout, Fungal Archer. Hydra mire [fortress] = Marsh Hydra, Swamp Troll, Bog Crawler ×2.
- **Lava:** Firestorm [combo] = Magma Golem, Pyromancer, Lesser Fire Elemental ×2. Inferno [swarm] = Greater Fire Elemental, Lesser Fire Elemental ×3.
- **Forest:** Wild hunt [ambush, swarm] = Satyr Warrior, Satyr Archer ×2, Forest Sprite. Old growth [fortress] = Treant, Krampus, Forest Sprite ×2.
- **Wasteland:** Dune lords [combo] = Dune Wyvern, Basilisk, Sand Scorpion ×2. Hollow host [swarm] = Stray ×2, Husk ×2, Slatescale Worm.
- **Hills:** Stone wall [fortress] = Stoneking, Stonekin Boulderfist, Stonekin Brute ×2. Giant's step [fortress, combo] = Hill Giant, Earth Elemental, Rock Elemental ×2.
- **Mountain:** Dragon's roost [combo] = Ancient Dragon, Stone Drake. Ogre band [swarm] = Ogre Chief, Ogre ×3, Crystal Golem.
- **Void:** Shadow host [ambush, swarm] = Dark Spectre, Shadow Wraith ×3. Void wyrm [combo] = Void Dragon, Void Hydra.

- [ ] **Step 1: Write the failing tests** in `api/tests/AiPersonalityTest.php` as `ai_personality_tests()`:
  - `'four personalities ship in difficulty order'`: `array_keys(Personalities::all()) === ['ravager','warden','huntress','hollowKing']`; difficulties `['easy','medium','hard','hardest']`; every `name`/`pitch` non-empty.
  - `'personalities differ in what they value'`: the pairwise `weights` arrays are all different, and the `horde.tag` values are four distinct strings.
  - `'the hex biome map follows the approved table'`: `monsterBiomes('ice','snow') === ['Ice']`, `('water','open-water') === ['River']`, `('jungle','jungle') === ['Forest']`, `('jungle','swamp') === ['Swamp']`, `('darkness','darkness') === ['Void']`, `('darkness','poison-pits') === ['Swamp']`, `('sand','dunes') === ['Wasteland']`, `('stone','crags') === ['Hills','Mountain']`, `('plains','grass') === ['Hills','Mountain']`, `('lava','basalt') === ['Lava']`, `('nowhere','x') === []`.
  - `'every package names real creatures of its biome'`: for each package, each creature exists in `(new Catalog())->monster($name)`, and its `biome` (`Catalog::monster()['biome']`) equals the package `biome`. Every one of the 9 monster biomes has ≥1 package, and every personality tag appears on ≥2 packages.
- [ ] **Step 2: Run** `php api/tests/run.php`. Expected: the four new tests FAIL (class not found).
- [ ] **Step 3: Implement** the four classes with the values above. `Catalog::monster($name)['biome']` already carries the creature's biome (`Catalog.php:47`). Use it for the test and the HordeBuilder.
- [ ] **Step 4: Run** `php api/tests/run.php`. Expected: all PASS, 0 failures.
- [ ] **Step 5: Commit** `feat(ai): add Void Lord personalities, biome map and horde packages`.

### Task 2: Seeded random, simulator and evaluator

**Files:**
- Create: `api/src/Ai/SeededRandom.php`, `api/src/Ai/Simulator.php`, `api/src/Ai/Evaluator.php`
- Test: `api/tests/AiEvaluatorTest.php`

**Interfaces:**
- Consumes: `Personality` (Task 1).
- Produces:
  - `final class SeededRandom implements RandomSource` — `__construct(int $seed)`. `roll(int $sides): int` is deterministic per seed (xorshift32; never 0 state).
  - `final class Simulator` — `__construct(MatchEngine $engine, int $now)`. `outcomes(array $state, string $seatId, string $kind, array $payload, int $samples, int $seed): list<array>`. It returns the resulting states, one per sample using `SeededRandom($seed + $i)`. It returns `[]` when the engine throws `GameServiceException` (an illegal candidate).
  - `final class Evaluator` — `__construct(Personality $p)`. `score(array $state, string $side, array $weights): float`. Higher is better for `$side` (`'void'` or `'heroes'`). `$weights` defaults to the personality's weights; the stance (Task 4) may pass adjusted ones.

Features (each normalised to roughly 0..1, then weighted). "Ours" means units whose side is `$side`; HP is `hp - wounds` as `Wounds` stores it:
- `damage`: total wounds on enemies.
- `kill`: number of enemies with `hex === null`.
- `loss`: tier points of our units off the board (`CoopSetup::cost`; heroes count 4).
- `portalGuard` (void only): our units within 3 hexes of the portal, minus enemies within 3.
- `advance`: negative mean distance from our units to their nearest enemy.
- `cohesion`: our units with an ally adjacent.
- `cover`: our units no enemy can see (`Combat::visible` from every enemy hex).
- `threat`: negative count of our units an enemy could attack next turn. An enemy threatens a unit when `Movement::reachable` budget + range covers its hex.
- `focus`: the largest wound share on any single enemy.
- A terminal state dominates: `result.winningSide === $side` → `+1e6`, a loss → `-1e6`.

- [ ] **Step 1: Write the failing tests** `ai_evaluator_tests()`, on `coop_battle([...])` boards from `CoopTestSupport.php`:
  - `'seeded random repeats per seed'`: two `SeededRandom(7)` produce identical 20-roll sequences in 1..6; `SeededRandom(8)` differs.
  - `'an illegal candidate has no outcomes'`: `outcomes($state,'void-lord','attackUnit',['unitId'=>'v-m1','targetUnitId'=>'nobody'],2,1) === []`.
  - `'wounding a hero scores higher for the void'`: the same board with hero `h1` at 0 vs 2 wounds. `score(wounded,'void') > score(fresh,'void')`, and the reverse holds for `'heroes'`.
  - `'a void win dominates'`: a state with `result.winningSide = 'void'` scores `> 1e5` for void and `< -1e5` for heroes.
  - `'the huntress values hiding more than the ravager'`: a board with a sight blocker. Monster behind cover vs in the open: the score gap is larger for `huntress` than for `ravager`.
- [ ] **Step 2: Run** `php api/tests/run.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement** the three classes. Keep `Evaluator` free of engine mutation: it reads state and calls the static helpers only.
- [ ] **Step 4: Run** `php api/tests/run.php`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat(ai): add simulator and position evaluator`.

### Task 3: Horde builder

**Files:**
- Create: `api/src/Ai/HordeBuilder.php`
- Test: `api/tests/AiHordeTest.php`

**Interfaces:**
- Consumes: `Personalities`, `BiomeMap`, `HordePackages`, `Catalog::monster()['biome']`, `CoopSetup::cost`.
- Produces: `final class HordeBuilder` — `__construct(Catalog $catalog)`. `plan(array $board, array $placeable, int $budget, int $heroPlayers, Personality $p, int $seed): list<array{monster:string, hex:string}>`.
  - `$placeable` is `monster name => list<hex>`, built from the `placeMonster` entries of `MatchEngine::legalActions($state, $aiSeat)` in prep.
  - The signature takes no game state on purpose: the builder *cannot* see draft picks (fair play).

Algorithm (decided):
1. Group the spawn hexes in `$placeable` into **regions**: connected components of spawn hexes sharing their first `BiomeMap` biome.
2. Rank the regions by `horde.placement`:
   - `forward`: nearest the hero start (`playerStart` hexes);
   - `portal`: nearest the hex with `voidPortal`/`portal`;
   - `cover`: most hexes adjacent to sight blockers, water or impassable objects;
   - `combo`: alternate front and portal regions.
3. Walk the regions in rank order, wrapping around. For each, choose a package whose `biome` is in the region's mapped biomes, preferring the personality's tag. Break ties with `SeededRandom($seed)`. With probability `horde.offBiome`, or when no package fits, take a package from another biome.
4. Scale the package to the remaining budget by dropping its trailing creatures. Bias the remainder toward `horde.shares`.
5. Place the core on the region hex farthest from the heroes, then the rest outward toward the heroes. Ranged creatures (`range > 1`) go behind melee ones. Use only hexes in `$placeable[$monster]` that this plan hasn't already taken.
6. Stop when no creature fits the remaining budget on any free hex. Fill the remainder with the cheapest placeable creatures of any region's biome.
7. Always return ≥1 placement when any creature fits.

- [ ] **Step 1: Write the failing tests** `ai_horde_tests()`. Use a helper `ai_prep_state(string $scenario, int $heroes): array` built with `MatchEngine(new Catalog())->start(...)` on `Board::fromScenario` of the scenario JSON. The real budget is 12 + 30 per hero.
  - `'each personality fields a legal horde on every built-in co-op map'`: for every `api/scenarios/*.json` with coop compatibility, every personality and heroes ∈ {1, 4}, apply the plan via `placeMonster`, then `confirmSetup` succeeds; spent ≤ budget and spent ≥ budget − 4.
  - `'awkward custom maps still get a horde'`: synthetic boards on `coop_board()`: (a) one spawn hex; (b) all plains, no water, no blockers; (c) a single biome. The plan is non-empty and applies and confirms cleanly.
  - `'water-bound creatures land only on water'`: on `incursion-lakeheart`, every planned Kraken or Kraken Tentacle hex has `terrain` open-water or shallow-water.
  - `'the same game seed gives the same horde, another seed varies it'`: `plan(..., seed 1)` twice gives identical lists. Over seeds 1..5, ≥2 distinct hordes for `hollowKing`.
  - `'personalities build recognisably different hordes'` (Void Reaches, 2 heroes): ravager's mean cost per creature < warden's; warden's mean distance to the portal < ravager's; huntress has the highest share of hexes adjacent to cover; hollowKing uses ≥3 monster biomes.
  - `'the horde ignores hero picks'` (fair play): build `$placeable` from two prep states that differ only in `draft.heroesBySeat`. The plans are identical.
  - `'mostly on theme'`: for ravager on Void Reaches, ≥80% of creatures have a biome in their hex's `BiomeMap` list.
- [ ] **Step 2: Run** `php api/tests/run.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement** `HordeBuilder::plan` per the algorithm.
- [ ] **Step 4: Run** `php api/tests/run.php`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat(ai): build themed hordes per personality`.

### Task 4: Turn planner

**Files:**
- Create: `api/src/Ai/Stance.php`, `api/src/Ai/CommandCandidates.php`, `api/src/Ai/TurnPlanner.php`
- Test: `api/tests/AiPlannerTest.php`

**Interfaces:**
- Consumes: `Simulator`, `Evaluator`, `Personality`.
- Produces:
  - `Stance::weights(array $state, string $seatId, Personality $p): array`. It returns the weights for this moment (hold vs attack, per the table in Task 1) and is derived from state alone, with no memory.
  - `CommandCandidates::for(array $state, string $seatId, array $legalActions): list<array{kind:string, payload:array}>`. It expands each legal action into concrete payloads:
    - `activateUnit` {unitId};
    - `moveUnit` {unitId, to, path} per `movementOptions`;
    - `moveAbility` {unitId, ability, toHex, path};
    - `attackUnit` {unitId, targetUnitId} per target hex occupant;
    - `specialAttack` {unitId, ability, targetUnitIds, toHex?}: single targets, self, each `targetHexes` entry as `toHex`, and up to `maxTargets` nearest combos from `targetSelection`;
    - `useAbility` {unitId, ability, targetUnitId};
    - `pickUpItem` / `useItem` {unitId, item};
    - `resolveChoice` {choiceId, option} per pending option;
    - `endActivation` {unitId}, `endTurn` {}.

    The payload shapes mirror what `GameTable.razor` sends; the engine rejects any bad expansion, so Simulator drops it.
  - `final class TurnPlanner` — `const STEP_BUDGET_MS = 1500`. `__construct(MatchEngine $engine, Personality $p, int $nowSeconds, int $deadlineMs, ?\Closure $clockMs = null)`. `next(array $state, string $seatId, int $seed): ?array{kind:string,payload:array}`. It returns `null` when the seat owes nothing (not its turn, no pending choice for it, game over).

Decision procedure (decided):
1. **Pending choice for `$seatId`:** score each `resolveChoice` option; pick the best.
2. **Active unit set:** plan the rest of its activation. Candidates are each movement destination (including staying), combined with the best follow-up attack, special attack or ability from there. Search depth is ≤2 commands. Score = mean `Evaluator` over `samples` outcomes. Return the first command of the best plan. Return `endActivation` when no command beats the current score.
3. **No active unit:** for each activatable unit, take the score of its best plan (step 2) minus the score of doing nothing. Activate the unit with the largest gain (`activateUnit`, or its first command directly). Prune to the `sharpness.candidates` best units and destinations by a cheap heuristic (distance to the nearest enemy, attack available). When `sharpness.pairs` is on, rerank the top 3 by the best pair of plans (this unit, then another), so it can focus fire.
4. **Turn end:** return `endTurn` when every remaining gain ≤ 0 or the allowance is spent.
5. **Hard rule against silly play:** a unit with an attack available against an adjacent or in-range enemy never ends its activation without attacking, unless the stance is hold and attacking would leave its post. Holding is allowed only for units within 2 hexes of their spawn region.
6. **Blunder:** with probability `sharpness.blunder` (seeded), take the second-best option instead of the best.
7. **Deadline:** check `clockMs() >= deadlineMs` between candidates. On timeout, return the best so far, or `endActivation` / `endTurn` when nothing has been scored.

- [ ] **Step 1: Write the failing tests** `ai_planner_tests()`, with `coop_battle` boards and the real catalog where creatures matter:
  - `'it attacks a hero standing in reach'`: a monster adjacent to a 1-HP hero, on the AI's turn with the unit active. `next` returns `attackUnit` targeting that hero (all personalities).
  - `'it never submits an illegal command'`: from 20 seeded random mid-battle states (Void Reaches, the horde from Task 3, heroes scattered), every `next` result applies via `MatchEngine::apply` without throwing.
  - `'it ends the turn when nothing is left'`: allowance spent. `next` returns `endTurn`.
  - `'it owes nothing on a hero turn'`: `next(...,'void-lord')` on hero-a's turn with no pending choice returns `null`.
  - `'it answers its own pending choice even on a hero turn'`: craft `state.pending.choice` with `seatId => 'void-lord'`, kind `itemPickup`, on hero-a's turn. `next` returns `resolveChoice` with that `choiceId` and an option from `options`.
  - `'the warden holds while heroes are far, the ravager charges'`: a monster at the portal with heroes 10 hexes away. Warden's move ends within 2 hexes of its start. Ravager's ends closer to the heroes.
  - `'it respects the deadline'`: an injected clock that advances 1000 ms per call, `deadlineMs = now + 1500`. `next` still returns a legal command.
- [ ] **Step 2: Run** `php api/tests/run.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement** `Stance`, `CommandCandidates` and `TurnPlanner`.
- [ ] **Step 4: Run** `php api/tests/run.php`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat(ai): plan Void Lord turns one command at a time`.

### Task 5: Full-battle soak and personality tuning

**Files:**
- Create: `api/tests/AiSoakTest.php`
- Modify: `api/src/Ai/Personalities.php`, `api/src/Ai/HordePackages.php` (tuning only)

**Interfaces:**
- Consumes: everything from Tasks 1–4. The hero bot is `TurnPlanner` with `Personalities::get('ravager')` on the hero seats; the planner is side-agnostic.

- [ ] **Step 1: Write the soak tests** `ai_soak_tests()`. Use a helper `ai_soak(string $scenario, string $personality, int $heroes, string $heroMode, int $roundCap): array{result:?array, rounds:int, illegal:int, aiAttacks:int, firstContactRound:?int}`. It:
  - starts on the real catalog;
  - plans the horde and confirms;
  - drafts by taking the first legal pick;
  - loops `next` for whichever seat owes a command.

  `$heroMode` is `'bot'` or `'passive'` (heroes `endTurn` at once).

  Tests:
  - `'every personality beats passive heroes without stalling'`: Void Reaches, 2 heroes, `'passive'`, cap 40 rounds. `result.winningSide === 'void'` and `illegal === 0` for all four.
  - `'bot battles finish legally'`: each personality vs `'bot'` heroes on Void Reaches and `incursion-lakeheart`, cap 60. `illegal === 0`. The match either ends or reaches the cap with ≥1 AI attack per round after first contact.
  - `'personalities feel different on the board'`: ravager's `firstContactRound` < warden's, on the same map and seed.
  - `'a planner step stays inside its budget'`: Void Reaches, 4 heroes, a full horde. The slowest single `next` < 3000 ms. This is loose on purpose to avoid CI flake; it logs the timing.
- [ ] **Step 2: Run** `php api/tests/run.php`. Expected: some FAIL at first; record which.
- [ ] **Step 3: Tune** the values in `Personalities` / `HordePackages` (not the algorithms) until all soak tests pass, keeping the Task 1 ordering tests green. If an algorithm change is truly needed, make it in the Task 4 files with a planner test that pins it.
- [ ] **Step 4: Run** `php api/tests/run.php`. Expected: all PASS.
- [ ] **Step 5: Commit** `test(ai): soak every personality through full battles`.

### Task 6: AI seat in the war room and at Start

**Files:**
- Create: `api/migrations/007_ai_void_lord.mysql.sql`, `api/migrations/007_ai_void_lord.sqlite.sql`
- Modify: `api/src/Game/GameService.php` (lobby commands, `memberships()`, `buildProjection`, `startGame`, `vacateLobbySeat`, `skipsVersionCheck`)
- Test: `api/tests/AiSeatServiceTest.php`

**Interfaces:**
- Consumes: `Personalities`, `HordeBuilder`.
- Produces (wire, used by Task 8):
  - The migration adds `game_memberships.ai_personality VARCHAR(32) NULL`, `games.ai_pace VARCHAR(16) NULL` and `games.ai_stepped_at BIGINT NULL` (ms). SQLite uses `TEXT` / `INTEGER`.
  - Command `seatAiVoidLord` {personality}: host only, Co-op lobby only. Errors: `hostRequired`, `illegalLifecycle`, `unknownPersonality`, `gameFull`.
    - If an AI seat exists, it changes the personality and the name.
    - Otherwise: clear every role to `player`, `ready = 0`; delete `game_states` (the human horde is discarded); insert a membership with `identity_id = 'ai' . bin2hex(random_bytes(15))`, the lowest free `seat_index`, `role = voidLord`, `ready = 1`, `is_host = 0`, the hash of an unreturned random token, `commander_name` = the personality name, and `ai_personality` = its id. Set `games.ai_pace = 'normal'` if null.
    - Then `syncLobbySetup` and an event `voidLordChanged` {seatId, aiPersonality}.
  - `assignVoidLord` / `claimVoidLord` while an AI seat exists: delete the AI membership first, then the existing flow. `claimVoidLord` stays unavailable while the AI holds the role; only the host's `assignVoidLord` or `removeSeat` displaces it. After any role reset, the AI row (if kept) is `ready = 1` again.
  - Command `setAiPace` {pace ∈ slow|normal|fast}: host only, any lifecycle but `completed`, requires an AI seat (`noAiSeat`), errors `invalidPace`. Bumps the version and emits no event. It is added to `skipsVersionCheck`. The client must be allowed to send it from the draft and battle screens.
  - The projection adds `memberships[].aiPersonality` (string|null), a top-level `aiPace` (string|null), and `aiPersonalities` = `Personalities::catalogue()` when mode is coop and lifecycle is lobby (else `[]`). The host's `lobbyActions` include `seatAiVoidLord` and, when an AI is seated, `setAiPace`.
  - `startGame` with an AI Void Lord, after `engine->start` and in the same transaction: build `$placeable` from `legalActions($state, $aiSeat)`. Call `HordeBuilder::plan($state['board'], $placeable, $state['setup']['budget'], heroCount, $p, crc32($gameId))`. Apply each `placeMonster`, then `confirmSetup`, via `$this->engine->apply`. Save the state and append the events with `visible_side = 'void'` (`setupConfirmed` stays public per `PUBLIC_PREP_EVENTS`).
  - `vacateLobbySeat`: the heir is the first remaining seat with `ai_personality IS NULL`. If none remain, delete the game.

- [ ] **Step 1: Write the failing tests** `ai_seat_service_tests()`, using `coop_service_match()` from `CoopServiceTest.php` with a real-catalog `MatchEngine`:
  - `'the host seats an ai void lord and becomes a hero'`: after `seatAiVoidLord {personality:'warden'}`, the memberships include one with `role voidLord`, `aiPersonality 'warden'`, `name 'The Warden'`, `ready true`. The host is `player`; `aiPace === 'normal'`.
  - `'one hero can start against the ai'`: the host alone + AI; the host readies; `startGame` succeeds. The lifecycle is `draft`, the setup is confirmed, the AI's `spent` is ≥1, and the hero's projection shows `setup.redacted === true`.
  - `'seating the ai discards a human horde'`: the host (VL by default) places a monster in the lobby, then seats the AI. The lobby state holds no units except `v-portal`.
  - `'assigning a human removes the ai'`: `assignVoidLord {seatId: hero1}` → no membership has `aiPersonality`, and hero1 is `voidLord`.
  - `'only the host changes the pace and only to a known pace'`: hero1 `setAiPace` → `hostRequired`; the host `'turbo'` → `invalidPace`; the host `'fast'` → `aiPace 'fast'`, including during draft.
  - `'the ai never inherits the host'` (Review Focus 1): host + hero1 + AI, the host leaves → hero1 `isHost`. Then hero1 leaves → `gameRow` throws not-found.
  - `'the ai horde ignores the draft'` (fair play): two identical games with fixed ids differ only in draft order. The AI units after Start are identical (same seed = crc32 of the game id; use a deterministic id seam or compare the plan directly via `HordeBuilder` with the same placeable set).
- [ ] **Step 2: Run** `php api/tests/run.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement** the migration and the `GameService` changes. Add `ai_personality` to the `memberships()` SELECT.
- [ ] **Step 4: Run** `php api/tests/run.php`. Expected: all PASS, and the existing Co-op service tests are still green.
- [ ] **Step 5: Commit** `feat(ai): seat an AI Void Lord in the war room and build its horde at start`.

### Task 7: Advancing the AI on polls

**Files:**
- Modify: `api/src/Game/GameService.php` (constructor clock, `advanceAi`, call sites)
- Test: `api/tests/AiTickServiceTest.php`

**Interfaces:**
- Consumes: `TurnPlanner`, Task 6 columns.
- Produces:
  - `GameService::__construct(..., ?\Closure $clockMs = null)`; it defaults to `(int)(microtime(true) * 1000)`.
  - `private function advanceAi(string $gameId): void`, called first thing in `getEvents`, `getWatchedEvents`, `getGame`, `reconnect`, `getSpectatorGame` and `getWatchedGame`. Behaviour:
    1. Return unless mode is coop, the lifecycle is `draft` or `active`, and an AI membership exists.
    2. In `draft`, if the AI's setup is unconfirmed, run the Task 6 horde routine (a safety net for legacy states).
    3. In `active`: return unless `clockMs() - max(ai_stepped_at, updated_at in ms) >= PACE_MS[ai_pace]` (`PACE_MS = ['slow'=>2000,'normal'=>1000,'fast'=>300]`).
    4. Load the state. `TurnPlanner(engine, personality, time(), clockMs() + STEP_BUDGET_MS)->next(state, aiSeat, version)`. Return on `null`.
    5. In a transaction: `applyMatchCommand(game, aiSeat, aiIdentity, ['kind'=>…, 'payload'=>…], version + 1)`, set `ai_stepped_at = clockMs()`, `writeSnapshot` with `spectatorProjection`, commit.
    6. On `GameServiceException` with code `versionConflict`: roll back and return silently (another poll won).
    7. On any other `GameServiceException` from the AI's command: roll back, then retry once with `endActivation` (if a unit is active) or else `endTurn` (Review Focus 3).
    8. Any `Throwable` from `advanceAi` is logged with `error_log` and swallowed, so the poll still answers (Review Focus 2).

- [ ] **Step 1: Write the failing tests** `ai_tick_service_tests()`. Use a mutable clock (`$now = 0; $clock = fn() => $now;`) and a started 1-hero vs `ravager` game, with the draft completed by the hero's picks:
  - `'the ai acts one step per due poll'`: at the start of the AI's turn, with the clock not advanced → `getEvents` adds no AI event. Advance 1000 ms → exactly one new version. Polling again at the same clock adds nothing.
  - `'the pace sets the pause'`: `setAiPace 'slow'`. At +1000 ms there's no step; at +2000 ms there's one.
  - `'the ai finishes its turn and hands back'`: keep advancing the clock and polling. The active seat returns to the hero within ≤ 5 activations × 4 commands + 1 steps.
  - `'a rejected ai command falls back to ending'` (Review Focus 3): inject a stub planner that returns `attackUnit` on a missing unit. The poll still answers, and the AI's turn advances (`endActivation`/`endTurn` applied). The seam is a protected factory `makePlanner(...)` or an optional constructor closure.
  - `'two polls in one pause step once'` (Review Focus 2): two `getEvents` calls at the same due clock → version +1 total.
  - `'the ai answers its pending choice during a hero turn'` (Review Focus 4): put the game in a state where `pending.choice.seatId` is the AI seat on the hero's turn (save it via the service's `saveState` path in-test). After a due poll, `pending` is null.
  - `'spectators drive the ai too'`: `getWatchedEvents` at a due clock adds one AI step.
- [ ] **Step 2: Run** `php api/tests/run.php`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement** the clock, `advanceAi` and its call sites.
- [ ] **Step 4: Run** `php api/tests/run.php`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat(ai): advance the AI Void Lord one paced step per poll`.

### Task 8: Client — contracts, war room picker, pace and polling

**Files:**
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs` (`MembershipProjection.AiPersonality`, `GameProjection.AiPace`, `GameProjection.AiPersonalities`, new `AiPersonalityProjection`)
- Modify: `tests/fixtures/*` golden projection fixture(s) that the Contracts tests compare (add the new fields)
- Modify: `src/BloodAndBanners.UI/Components/WarRoomLobby.razor` (+ `.razor.css`), `MatchMenu.razor`, `GameTable.razor`, `DraftTable.razor`, `GameExperience.razor`
- Modify: `src/BloodAndBanners.UI/Services/PollingCadence.cs`
- Test: `tests/BloodAndBanners.UI.Tests/WarRoomLobbyTests.cs`, `MatchMenuTests.cs`, a new `PollingCadenceTests.cs` (or extend the existing cadence tests in `UnitTest1.cs` if they live there), and `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs` for the fixture round-trip

**Interfaces:**
- Consumes: the Task 6 wire fields and commands `seatAiVoidLord {personality}` and `setAiPace {pace}`.
- Produces:
  - `public sealed record AiPersonalityProjection(string Id, string Name, string Difficulty, string Pitch);`
  - `MembershipProjection { public string? AiPersonality { get; init; } }`
  - `GameProjection { public string? AiPace { get; init; } public IReadOnlyList<AiPersonalityProjection> AiPersonalities { get; init; } = []; }`
  - `PollingCadence.AiInterval(string? pace): TimeSpan`: slow 2 s, normal 1 s, fast 400 ms, null → 1 s. `PollingCadence.Select(..., TimeSpan? aiActing = null)`: when there are no failures and it isn't backgrounded, a non-null `aiActing` returns `Min(aiActing, 2 s)`.
  - `MatchMenu` parameters: `string? AiPace`, `bool CanSetAiPace`, `EventCallback<string> OnSetAiPace`.

UI (decided):
- **War room, host, Co-op:** an "AI Void Lord" panel (`data-testid="ai-picker"`) lists the four personalities from `AiPersonalities`, each with its name, difficulty and pitch and a button `data-ai-personality="{id}"` that sends `seatAiVoidLord`. The seated one is marked `aria-pressed="true"`. When an AI is seated, a pace control (`data-ai-pace`, three buttons Slow/Normal/Fast, the current one `aria-pressed`) sends `setAiPace`.
- **AI seat row:** shows "AI Void Lord" instead of Host/Player (`data-testid="ai-seat"`) and no friend button. The host's Remove still works.
- **Non-hosts** see the AI seat row and a read-only "Pace: Normal" line.
- **☰ menu during draft and battle:** when `AiPace` isn't null, it shows "AI pace" with three menuitems (`data-menu="aiPace-slow|normal|fast"`, `aria-checked` on the current one). They are enabled only when `CanSetAiPace` (viewer is host).
- **GameExperience polling:** `aiActing = AiInterval(AiPace)` when the lifecycle is Active and `Turn.ActiveSeatId` or `PendingChoiceSummary.SeatId` belongs to a membership with a non-null `AiPersonality`; otherwise null.

- [ ] **Step 1: Write the failing tests:**
  - `WarRoomLobbyTests`: `Host_sees_four_ai_personalities_and_seats_one` — four `[data-ai-personality]` buttons; clicking `warden` raises OnCommand with kind `seatAiVoidLord` and payload personality `warden`. `Ai_seat_row_is_labelled_and_pace_is_offered` — a membership with `AiPersonality = "ravager"` renders `[data-testid=ai-seat]`, and the host sees `[data-ai-pace]` with Normal pressed. `Non_host_cannot_pick_ai` — no `[data-testid=ai-picker]`.
  - `MatchMenuTests`: `Host_changes_ai_pace_from_menu` — `AiPace="normal"`, `CanSetAiPace=true`; clicking `aiPace-fast` raises `OnSetAiPace("fast")`. `Non_host_sees_pace_read_only` — the menuitems are disabled.
  - `PollingCadenceTests`: `Ai_turn_polls_at_pace` — `Select(false,false,false,0, AiInterval("fast")) == 400ms`; slow → 2 s; failures still back off.
  - Contracts fixture round-trip includes `aiPace`, `aiPersonalities` and `memberships[].aiPersonality`.
- [ ] **Step 2: Run** `dotnet test tests/BloodAndBanners.UI.Tests` and `dotnet test tests/BloodAndBanners.Contracts.Tests`. Expected: the new tests FAIL.
- [ ] **Step 3: Implement** the contracts, components and cadence. Wire `OnSetAiPace` in `GameTable` and `DraftTable` to `Send("setAiPace", new { pace })`.
- [ ] **Step 4: Run** both `dotnet test` commands. Expected: all PASS (note the known flaky `AttackPopup` queued-attacks test; rerun if it alone fails).
- [ ] **Step 5: Commit** `feat(ai): AI Void Lord picker, pace control and paced polling`.

### Task 9: End-to-end check and docs

**Files:**
- Modify: `README.md` (a short "Play against the AI Void Lord" section after the Void incursion paragraph)

- [ ] **Step 1: Run every suite:** `php api/tests/run.php` (expect `N tests, 0 failures`), `dotnet test tests/BloodAndBanners.UI.Tests`, `dotnet test tests/BloodAndBanners.Contracts.Tests`.
- [ ] **Step 2: Live check** with `scripts/start-api.ps1` + `dotnet run --project src/BloodAndBanners.Web/...`:
  - open a Void incursion on Void Reaches;
  - seat The Huntress;
  - ready and start with one hero, then draft;
  - watch one full AI turn play out step by step at Normal;
  - switch to Fast from ☰ and confirm the turns speed up;
  - confirm the log names the personality.
  
  Note anything off for playtest.
- [ ] **Step 3: Write** the README section: seating an AI, the four personalities, the pace setting, and that the AI acts while someone has the game open.
- [ ] **Step 4: Commit** `docs: describe playing against the AI Void Lord`.
