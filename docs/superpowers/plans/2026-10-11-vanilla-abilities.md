# Vanilla Abilities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Heroes and units in a Vanilla duel use their 22 printed abilities (spec groups A–E); deferred abilities are visible but inert.

**Architecture:** A PHP ability registry maps ability names to small handler classes that plug into a few hook points (stat deltas, evasion, defeat prevention, attack allowance, move-after-attack, special attacks, targeted abilities, re-roll). Every stat read goes through `Stats::effective`. Every attack becomes a list of steps run by `StepRunner`, which can suspend on a Moonlight Glare choice and resume on `resolveChoice`. The Blazor client gains ability lists, effective stats, a targeting mode, Chain Lightning picking, the re-roll prompt and combat-log lines.

**Tech Stack:** PHP 8.3 (no framework; tests via `php api/tests/run.php`), .NET 10 Blazor component library + bUnit/xUnit (`dotnet test`).

**Spec:** `docs/superpowers/specs/2026-10-11-vanilla-abilities-design.md`

## Global Constraints

- Vanilla PvP only; abilities apply only in the `active` phase.
- Abilities are copied into match state at pick time with the stats; print-data edits never change an in-progress match.
- Matches saved before this slice must keep playing: missing `abilities`, `flags`, `effects`, `pending` and new `turn` fields read as empty (`MatchEngine::normalize`).
- "Until your next turn" = until the start of the caster's seat's next turn (`Effects::UNTIL_NEXT_TURN`, `at: "turnStart"`); "until end of turn" = `Effects::UNTIL_END_OF_TURN`, `at: "turnEnd"`.
- Same named effect on the same unit refreshes instead of stacking. Effective stats never go below 0.
- Power order: base (or a special attack's fixed Power), plus effects and essence deltas, floor 0, then Chaos Strike doubling.
- Moonlight Glare keep-for-them timeout: **45 seconds**, measured on the server clock.
- Deferred abilities (no handler): Teleport, Gust of Wind, Knockback, Stunning Blow, Flying, Lava Walker. They project with `playable: false`.
- New error codes: `unknownAbility`, `abilityNotPlayable`, `abilityAlreadyUsed`, `abilityLocked`, `invalidAbilityTarget`, `chainTooLong`, `choicePending`, `notYourChoice`, `unknownChoice`, `choiceNotExpired`, plus `noAttacksLeft` (a unit that keeps its activation open after its last attack, e.g. Silent Sting, tries another attack).
- Commits end with the session's `Co-Authored-By` / `Claude-Session` trailer lines.

Refinements of the spec made while planning (same behaviour, concrete shapes):

- Per-turn usage lives on `turn` (`attacks`, `moveSpent`, `movedAfterAttack`, `abilitiesUsed[unitId][]`, `glareUsed[]`) so `Turn::fresh()` resets it for free; persistent `flags` live on each unit (`lastHornSpent`, `spellsLockedOnTurn`). `turn.number` counts seat turns so Fragile Focus can name "his next turn".
- Instead of a bare `choicePending` boolean, every viewer gets `pendingChoiceSummary {choiceId, seatId, unitId, keepableAt}` (null when nothing is pending); the owning seat additionally gets the full `pendingChoice` with faces and options. The waiting seat needs the `choiceId` to send "keep".

## Review Focus

1. A unit defeated mid-resolution (Fireball target dies, a Chain Lightning link kills, splash kills the Wizard) — later steps must skip dead units, not crash or resurrect them. Pinned in Task 6 and Task 7.
2. The match ending mid-steps (annihilation by splash or by the second Dual Strike) — the runner must stop and leave no `pending` choice. Pinned in Task 6.
3. A pending choice surviving a JSON round trip (state saved, reloaded, then resolved) — steps with `null` power and nested arrays must resume identically. Pinned in Task 9.
4. The auto end-of-turn firing while a choice is pending, or while a spent unit still has an unused Ability — the turn must not pass in the first case and must not pass in the second. Pinned in Task 9 and Task 8.
5. Old saved matches (no new fields) — attacking, moving and legal actions keep working on plain stats. Pinned in Task 10.

---

## File Structure

PHP (`api/src/Rules/Vanilla/`):

| File | Responsibility |
|---|---|
| `Catalog.php` (modify) | Copy `abilities` from `gameData.json` into hero/unit cards. |
| `Turn.php` (modify) | Turn `number` and per-turn usage fields; `nextTurnNumberOf`. |
| `MatchEngine.php` (modify) | `normalize()` for old states; pass `$now` to `BattleRules`. |
| `Effects.php` (create) | Add, refresh, total and expire timed effects. |
| `Stats.php` (create) | `effective()` — the only reader of Power/Defense/Range/Move. |
| `Targeting.php` (create) | Enemies-in-sight, allies-within, units-within helpers. |
| `Wounds.php` (create) | Apply wounds: Fragile Focus lock, One Last Horn, defeat, annihilation. |
| `StepRunner.php` (create) | Run attack steps; suspend/resume Moonlight Glare choices. |
| `Combat.php` (modify) | Effective range, public `visible()`, Evasion-aware disengagement. |
| `Movement.php` (modify) | `costs()` with a move budget; effective Move. |
| `BattleRules.php` (modify) | Commands `specialAttack`, `useAbility`, `resolveChoice`; activation flow; legal actions. |
| `Abilities/Ability.php` (create) | Base class with no-op hooks. |
| `Abilities/SpecialAttack.php`, `Abilities/TargetedAbility.php` (create) | Base classes for Attack- and Ability-type handlers. |
| `Abilities/AbilityRegistry.php` (create) | Name → handler map. |
| `Abilities/*.php` (create) | One class per ability (listed per task). |

`api/src/Game/GameService.php` (modify): project abilities, effects, effective stats, `ability` on legal actions, `pendingChoice` / `pendingChoiceSummary`; normalize state.

Client:

| File | Responsibility |
|---|---|
| `src/BloodAndBanners.Contracts/MatchProjection.cs`, `GameProjection.cs` (modify) | New records and init properties. |
| `src/BloodAndBanners.UI/Components/AbilityList.razor` (+`.css`) (create) | Ability list with badges, buttons, used/deferred states. |
| `src/BloodAndBanners.UI/Services/HexMath.cs` (create) | Axial distance and neighbours for client previews. |
| `src/BloodAndBanners.UI/Components/GameTable.razor` (+`.css`) (modify) | Effective stats, targeting mode, splash preview, chain picking, effect markers, Glare prompt. |
| `src/BloodAndBanners.UI/Components/DraftTable.razor` (+`.css`) (modify) | Abilities on draft cards. |
| `src/BloodAndBanners.UI/Components/GameExperience.razor` (modify) | Polling cadence while a choice is pending. |
| `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs` (modify) | Lines for the new events. |

Tests: `api/tests/VanillaAbilityTest.php` (create, registered in `api/tests/run.php`), `api/tests/VanillaBattleTest.php`, `api/tests/VanillaMatchServiceTest.php`, `tests/BloodAndBanners.UI.Tests/*`, `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`, `tests/fixtures/game-projection.json`.

Run commands used throughout:

- PHP: `php api/tests/run.php` (prints `PASS`/`FAIL` per test and a summary; exit code 1 on failure).
- .NET: `dotnet test` from the repo root.

---

### Task 1: Abilities in the catalog and turn bookkeeping

**Files:**
- Modify: `api/src/Rules/Vanilla/Catalog.php`
- Modify: `api/src/Rules/Vanilla/Turn.php`
- Modify: `api/src/Rules/Vanilla/MatchEngine.php`
- Modify: `api/src/Rules/Vanilla/DraftRules.php` (deploy)
- Modify: `api/tests/fixtures/catalog.json`
- Create: `api/tests/VanillaAbilityTest.php`
- Modify: `api/tests/run.php`

**Interfaces:**
- Produces: catalog cards gain `abilities: [{name, type, description}]`; deployed units gain `abilities` and `flags: []`.
- Produces: `Turn::fresh()` / `Turn::idle()` return `number`, `attacks`, `moveSpent`, `movedAfterAttack`, `abilitiesUsed`, `glareUsed` in addition to today's keys.
- Produces: `Turn::nextTurnNumberOf(array $state, string $seatId): int`.
- Produces: `MatchEngine::normalize(array $state): array` (public static).
- Produces: test helper `ability_unit(string $unitId, string $seatId, ?string $hex, array $abilityNames, array $stats = []): array` in `VanillaAbilityTest.php`.

- [ ] **Step 1: Give the fixture catalog one ability so the copy is observable**

In `api/tests/fixtures/catalog.json`, change the `Test Captain` hero's `"abilities": []` to:

```json
"abilities": [{"name": "Shield Wall", "type": "Ability", "description": "Grant one adjacent unit +1 Defense until the start of your next turn"}]
```

- [ ] **Step 2: Write the failing tests**

Create `api/tests/VanillaAbilityTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;

/** A battle unit carrying the named abilities (types are irrelevant to the rules, which key on names). */
function ability_unit(string $unitId, string $seatId, ?string $hex, array $abilityNames, array $stats = []): array
{
    $abilities = array_map(
        static fn(string $name): array => ['name' => $name, 'type' => 'Essence', 'description' => ''],
        $abilityNames
    );
    return vanilla_unit($unitId, $seatId, $hex, $stats + ['abilities' => $abilities, 'flags' => []]);
}

function vanilla_ability_tests(): array
{
    return [
        'catalog cards carry their abilities and deployment copies them' => static function (): void {
            $captain = test_catalog()->hero('Test Captain');
            expect_same(
                [['name' => 'Shield Wall', 'type' => 'Ability', 'description' => 'Grant one adjacent unit +1 Defense until the start of your next turn']],
                $captain['abilities'],
                'hero abilities are copied from the catalog'
            );
            expect_same([], test_catalog()->army('Red Company')['units'][0]['abilities'], 'units without abilities get an empty list');

            $engine = new MatchEngine(test_catalog());
            $none = new SequenceRandom([]);
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([17, 5]))['state'];
            foreach ([
                ['seat-a', 'chooseFirstPlayer', ['seatId' => 'seat-a']],
                ['seat-b', 'chooseStartPosition', ['start' => 2]],
                ['seat-b', 'pickHero', ['hero' => 'Test Marshal']],
                ['seat-a', 'pickHero', ['hero' => 'Test Captain']],
                ['seat-a', 'pickArmy', ['army' => 'Red Company']],
                ['seat-b', 'pickArmy', ['army' => 'Blue Company']],
            ] as [$seat, $kind, $payload]) {
                $state = $engine->apply($state, $seat, $kind, $payload, $none)['state'];
            }
            $hero = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['name'] === 'Test Captain'))[0];
            expect_same('Shield Wall', $hero['abilities'][0]['name'], 'the deployed hero carries Shield Wall');
            expect_same([], $hero['flags'], 'deployed units start with no flags');
        },

        'turns are numbered and carry fresh usage records' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            expect_same(1, $state['turn']['number'], 'the first turn is number one');
            expect_same(
                ['attacks' => 0, 'moveSpent' => 0, 'movedAfterAttack' => false, 'abilitiesUsed' => [], 'glareUsed' => []],
                array_intersect_key($state['turn'], array_flip(['attacks', 'moveSpent', 'movedAfterAttack', 'abilitiesUsed', 'glareUsed'])),
                'usage starts empty'
            );
            $next = vanilla_command($state, 'seat-a', 'endTurn')['state'];
            expect_same(2, $next['turn']['number'], 'each seat turn advances the number');
            expect_same(3, Turn::nextTurnNumberOf($next, 'seat-a'), 'seat-a plays next on turn three');
            expect_same(4, Turn::nextTurnNumberOf($next, 'seat-b'), 'the active seat\'s next turn is two ahead');
        },

        'old saved states are normalized with empty ability data' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            unset($state['turn']['number'], $state['turn']['attacks'], $state['turn']['abilitiesUsed']);
            $normal = MatchEngine::normalize($state);
            expect_same([], $normal['effects'], 'effects default to empty');
            expect_same(null, $normal['pending'], 'nothing is pending');
            expect_same([], $normal['units']['a1']['abilities'], 'units default to no abilities');
            expect_same([], $normal['units']['a1']['flags'], 'units default to no flags');
            expect_same(0, $normal['turn']['attacks'], 'attack count defaults to zero');
            expect_same([], $normal['turn']['abilitiesUsed'], 'ability usage defaults to empty');
        },
    ];
}
```

In `api/tests/run.php` add `require __DIR__ . '/VanillaAbilityTest.php';` after the `VanillaBattleTest.php` require, and add `vanilla_ability_tests()` to the `array_merge(...)` list after `vanilla_battle_tests()`.

- [ ] **Step 3: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the three new tests FAIL (missing `abilities` key / `number` key / undefined `MatchEngine::normalize`).

- [ ] **Step 4: Implement**

`Catalog::stats()` — add an `abilities` entry:

```php
    private static function stats(array $card): array
    {
        return [
            'name' => (string)$card['name'],
            'image' => (string)($card['image'] ?? ''),
            'move' => (int)$card['move'],
            'range' => (int)$card['range'],
            'power' => (int)$card['power'],
            'defense' => (int)$card['defense'],
            'hp' => (int)$card['hp'],
            'abilities' => array_map(static fn(array $ability): array => [
                'name' => (string)$ability['name'],
                'type' => (string)$ability['type'],
                'description' => (string)($ability['description'] ?? ''),
            ], $card['abilities'] ?? []),
        ];
    }
```

`Turn.php` — replace the class body:

```php
final class Turn
{
    /** Per-turn bookkeeping that every fresh turn resets. */
    private const USAGE = ['attacks' => 0, 'moveSpent' => 0, 'movedAfterAttack' => false, 'abilitiesUsed' => [], 'glareUsed' => []];

    public static function idle(): array
    {
        return ['round' => 0, 'number' => 0, 'activeSeatId' => null, 'activeUnitId' => null, 'activated' => [], 'moved' => false, 'allowance' => 0] + self::USAGE;
    }

    public static function fresh(array $state, string $seatId, int $round): array
    {
        $alive = count(array_filter(
            $state['units'],
            static fn(array $unit): bool => $unit['seatId'] === $seatId && $unit['hex'] !== null
        ));
        return [
            'round' => $round,
            'number' => (int)($state['turn']['number'] ?? 0) + 1,
            'activeSeatId' => $seatId,
            'activeUnitId' => null,
            'activated' => [],
            'moved' => false,
            'allowance' => min(4, $alive),
        ] + self::USAGE;
    }

    /** Fill fields that turns saved before abilities existed are missing. */
    public static function normalize(array $turn): array
    {
        return $turn + ['number' => 1] + self::USAGE;
    }

    /** The turn number on which the given seat next starts a turn. */
    public static function nextTurnNumberOf(array $state, string $seatId): int
    {
        return $state['turn']['number'] + ($state['turn']['activeSeatId'] === $seatId ? 2 : 1);
    }

    public static function activationsRemaining(array $state): int
    {
        if ($state['phase'] !== 'active') {
            return 0;
        }
        return max(0, $state['turn']['allowance'] - count($state['turn']['activated']));
    }
}
```

`MatchEngine.php` — in `start()` add `'effects' => [], 'pending' => null,` to the initial `$state` array (after `'units' => []`), and add:

```php
    /** Fill fields that states saved before abilities existed are missing, so old matches play on stats alone. */
    public static function normalize(array $state): array
    {
        $state['effects'] ??= [];
        $state['pending'] ??= null;
        $state['turn'] = Turn::normalize($state['turn'] ?? Turn::idle());
        foreach ($state['units'] ?? [] as $unitId => $unit) {
            $state['units'][$unitId] = $unit + ['abilities' => [], 'flags' => []];
        }
        return $state;
    }
```

Call it at the top of `apply()` and `legalActions()`: first line `$state = self::normalize($state);`.

`DraftRules::deploy()` — units get flags: change the unit line to

```php
                $units[$unitId] = ['unitId' => $unitId, 'seatId' => $seatId] + $stats + ['wounds' => 0, 'hex' => $positions[$unitId], 'flags' => []];
```

In `api/tests/VanillaTestSupport.php` `vanilla_battle()`: add `'effects' => [], 'pending' => null,` next to `'units' => []` so test states match fresh ones.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS (the summary shows `0 failures`).

- [ ] **Step 6: Commit**

```bash
git add api/src/Rules/Vanilla/Catalog.php api/src/Rules/Vanilla/Turn.php api/src/Rules/Vanilla/MatchEngine.php api/src/Rules/Vanilla/DraftRules.php api/tests
git commit -m "Copy abilities into match state and number each turn"
```

---

### Task 2: Ability registry, effects and effective stats

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/Ability.php`
- Create: `api/src/Rules/Vanilla/Abilities/AbilityRegistry.php`
- Create: `api/src/Rules/Vanilla/Effects.php`
- Create: `api/src/Rules/Vanilla/Stats.php`
- Create: `api/src/Rules/Vanilla/Targeting.php`
- Modify: `api/src/Rules/Vanilla/Combat.php`
- Modify: `api/src/Rules/Vanilla/Movement.php`
- Modify: `api/src/Rules/Vanilla/BattleRules.php` (passTurn)
- Test: `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `MatchEngine::normalize`, `Turn::fresh` (Task 1).
- Produces:
  - `abstract class Abilities\Ability` with public readonly `string $name` and hooks (all no-ops by default): `statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int`, `attacksPerActivation(): int` (1), `movesAfterAttack(): bool`, `evades(): bool`, `survivesDefeatOnce(): bool`, `locksSpellsWhenWounded(): bool`, `offersReroll(): bool`.
  - `AbilityRegistry::get(string $name): ?Ability`, `::abilitiesOf(array $unit): Ability[]` (known handlers only), `::find(array $unit, string $name): ?Ability`, `::any(array $unit, callable $test): bool`, `::isPlayable(string $name): bool`.
  - `Effects::UNTIL_NEXT_TURN = 'turnStart'`, `Effects::UNTIL_END_OF_TURN = 'turnEnd'`; `Effects::add(array $state, string $name, string $sourceUnitId, string $targetUnitId, string $stat, int $amount, string $seatId, string $at, array &$events): array`; `Effects::total(array $state, string $unitId, string $stat): int`; `Effects::forUnit(array $state, string $unitId): array`; `Effects::expire(array $state, string $seatId, string $at, array &$events): array`.
  - `Stats::effective(array $state, string $unitId, string $stat, array $context = [], ?int $base = null): int` — `$context` keys `attackerId`, `defenderId`; `$base` overrides the printed stat (special attacks' fixed Power).
  - `Targeting::enemiesInSight(array $state, string $unitId, int $range): array` (unit ids), `Targeting::alliesWithin(array $state, string $unitId, int $range, bool $includeSelf): array`, `Targeting::unitsWithin(array $state, string $unitId, int $range, bool $includeSelf): array`.
  - `Combat::visible(array $state, string $fromHex, string $toHex): bool` (public).
  - `Movement::costs(array $state, string $unitId, ?int $budget = null): array` (hex => steps), `Movement::reachable(array $state, string $unitId, ?int $budget = null): array`.
  - `BattleRules::passTurn()` now returns `[$state, array $events]` (events list, not a single event).

- [ ] **Step 1: Write the failing tests**

Append to the array returned by `vanilla_ability_tests()`:

```php
        'effects modify effective stats, refresh by name and expire at turn boundaries' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['defense' => 2, 'power' => 1]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $events = [];
            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_NEXT_TURN, $events);
            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_NEXT_TURN, $events);
            $state = Effects::add($state, 'Warlord\'s Command', 'a1', 'a1', 'power', 1, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::add($state, 'Rune of Distraction', 'b1', 'a1', 'power', -5, 'seat-b', Effects::UNTIL_NEXT_TURN, $events);
            expect_same(4, count($events), 'each application is announced');
            expect_same(3, Stats::effective($state, 'a1', 'defense'), 'the same named effect does not stack');
            expect_same(0, Stats::effective($state, 'a1', 'power'), 'effective stats never drop below zero');

            $passed = vanilla_command($state, 'seat-a', 'endTurn');
            expect_same(['effectExpired', 'turnEnded', 'effectExpired'], array_column($passed['events'], 'kind'), 'end-of-turn effects expire, the turn passes, then seat-b\'s effects end');
            expect_same(['Shield Wall'], array_column(Effects::forUnit($passed['state'], 'a1'), 'name'), 'Shield Wall lasts until seat-a\'s next turn');

            $back = vanilla_command($passed['state'], 'seat-b', 'endTurn');
            expect_same(['turnEnded', 'effectExpired'], array_column($back['events'], 'kind'), 'Shield Wall fades as seat-a starts again');
            expect_same(2, Stats::effective($back['state'], 'a1', 'defense'), 'defense is back to base');
        },

        'movement and range read effective stats' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 1, 'range' => 1]),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $events = [];
            $state = Effects::add($state, 'Test Haste', 'a1', 'a1', 'move', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::add($state, 'Test Sight', 'a1', 'a1', 'range', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_same(['1,0', '2,0'], Movement::reachable($state, 'a1'), 'the extra move opens two more hexes');
            expect_same(['b1'], Combat::targets($state, 'a1'), 'the extra range reaches the enemy');
            expect_same(['1,0' => 1, '2,0' => 2], Movement::costs($state, 'a1'), 'costs count steps');
            expect_same(['1,0'], Movement::reachable($state, 'a1', 1), 'a budget caps the search');
        },

        'the registry only knows implemented abilities' => static function (): void {
            expect_same(false, AbilityRegistry::isPlayable('Teleport'), 'Teleport is deferred');
            expect_same(null, AbilityRegistry::find(ability_unit('x', 'seat-a', '0,0', ['Teleport']), 'Teleport'), 'deferred abilities have no handler');
        },
```

Add to the `use` lines at the top of `VanillaAbilityTest.php`:

```php
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the three new tests FAIL with class-not-found errors for `Effects` / `AbilityRegistry`.

- [ ] **Step 3: Implement the base class and registry**

`api/src/Rules/Vanilla/Abilities/Ability.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** A printed ability. Subclasses override only the hooks they need; every hook defaults to "no effect". */
abstract class Ability
{
    public function __construct(public readonly string $name)
    {
    }

    /** Additive change to $unitId's $stat caused by this ability on $ownerId. Context: attackerId, defenderId. */
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        return 0;
    }

    public function attacksPerActivation(): int
    {
        return 1;
    }

    public function movesAfterAttack(): bool
    {
        return false;
    }

    public function evades(): bool
    {
        return false;
    }

    public function survivesDefeatOnce(): bool
    {
        return false;
    }

    public function locksSpellsWhenWounded(): bool
    {
        return false;
    }

    public function offersReroll(): bool
    {
        return false;
    }
}
```

`api/src/Rules/Vanilla/Abilities/AbilityRegistry.php` (handlers are added by later tasks; the map starts empty):

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

/** Maps printed ability names to handlers. Names without a handler are deferred and have no effect in play. */
final class AbilityRegistry
{
    /** @var array<string, Ability>|null */
    private static ?array $handlers = null;

    public static function get(string $name): ?Ability
    {
        return self::handlers()[$name] ?? null;
    }

    public static function isPlayable(string $name): bool
    {
        return self::get($name) !== null;
    }

    /** @return Ability[] the unit's abilities that have handlers */
    public static function abilitiesOf(array $unit): array
    {
        $found = [];
        foreach ($unit['abilities'] ?? [] as $ability) {
            $handler = self::get((string)$ability['name']);
            if ($handler !== null) {
                $found[] = $handler;
            }
        }
        return $found;
    }

    public static function find(array $unit, string $name): ?Ability
    {
        foreach (self::abilitiesOf($unit) as $ability) {
            if ($ability->name === $name) {
                return $ability;
            }
        }
        return null;
    }

    public static function any(array $unit, callable $test): bool
    {
        foreach (self::abilitiesOf($unit) as $ability) {
            if ($test($ability)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, Ability> */
    private static function handlers(): array
    {
        if (self::$handlers === null) {
            $list = [];
            self::$handlers = [];
            foreach ($list as $handler) {
                self::$handlers[$handler->name] = $handler;
            }
        }
        return self::$handlers;
    }
}
```

Later tasks add entries to `$list` (e.g. `new TremblingFear('Trembling Fear')`).

- [ ] **Step 4: Implement Effects, Stats and Targeting**

`api/src/Rules/Vanilla/Effects.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/** Timed stat changes. An effect expires when the named seat's turn starts (UNTIL_NEXT_TURN) or ends (UNTIL_END_OF_TURN). */
final class Effects
{
    public const UNTIL_NEXT_TURN = 'turnStart';
    public const UNTIL_END_OF_TURN = 'turnEnd';

    public static function add(
        array $state,
        string $name,
        string $sourceUnitId,
        string $targetUnitId,
        string $stat,
        int $amount,
        string $seatId,
        string $at,
        array &$events,
    ): array {
        $state['effects'] = array_values(array_filter(
            $state['effects'],
            static fn(array $effect): bool => !($effect['name'] === $name && $effect['targetUnitId'] === $targetUnitId)
        ));
        $effect = [
            'name' => $name,
            'sourceUnitId' => $sourceUnitId,
            'targetUnitId' => $targetUnitId,
            'stat' => $stat,
            'amount' => $amount,
            'expires' => ['seatId' => $seatId, 'at' => $at],
        ];
        $state['effects'][] = $effect;
        $hex = $state['units'][$targetUnitId]['hex'];
        $events[] = MatchEngine::event('effectApplied', $hex === null ? [] : [$hex], $effect);
        return $state;
    }

    public static function total(array $state, string $unitId, string $stat): int
    {
        $total = 0;
        foreach (self::forUnit($state, $unitId) as $effect) {
            if ($effect['stat'] === $stat) {
                $total += (int)$effect['amount'];
            }
        }
        return $total;
    }

    public static function forUnit(array $state, string $unitId): array
    {
        return array_values(array_filter(
            $state['effects'] ?? [],
            static fn(array $effect): bool => $effect['targetUnitId'] === $unitId
        ));
    }

    public static function expire(array $state, string $seatId, string $at, array &$events): array
    {
        $kept = [];
        foreach ($state['effects'] as $effect) {
            if ($effect['expires']['seatId'] === $seatId && $effect['expires']['at'] === $at) {
                $events[] = MatchEngine::event('effectExpired', [], ['name' => $effect['name'], 'targetUnitId' => $effect['targetUnitId']]);
                continue;
            }
            $kept[] = $effect;
        }
        $state['effects'] = $kept;
        return $state;
    }
}
```

`api/src/Rules/Vanilla/Stats.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;

/** The only place combat and movement read Power, Defense, Range and Move. */
final class Stats
{
    public static function effective(array $state, string $unitId, string $stat, array $context = [], ?int $base = null): int
    {
        $value = ($base ?? (int)$state['units'][$unitId][$stat]) + Effects::total($state, $unitId, $stat);
        foreach ($state['units'] as $ownerId => $owner) {
            if ($owner['hex'] === null) {
                continue;
            }
            foreach (AbilityRegistry::abilitiesOf($owner) as $ability) {
                $value += $ability->statDelta($state, (string)$ownerId, $unitId, $stat, $context);
            }
        }
        return max(0, $value);
    }
}
```

`api/src/Rules/Vanilla/Targeting.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Targeting
{
    /** Living enemies within $range that $unitId can see. */
    public static function enemiesInSight(array $state, string $unitId, int $range): array
    {
        $self = $state['units'][$unitId];
        return array_values(array_filter(
            self::unitsWithin($state, $unitId, $range, false),
            static fn(string $id): bool => $state['units'][$id]['seatId'] !== $self['seatId']
                && Combat::visible($state, $self['hex'], $state['units'][$id]['hex'])
        ));
    }

    public static function alliesWithin(array $state, string $unitId, int $range, bool $includeSelf): array
    {
        $seatId = $state['units'][$unitId]['seatId'];
        return array_values(array_filter(
            self::unitsWithin($state, $unitId, $range, $includeSelf),
            static fn(string $id): bool => $state['units'][$id]['seatId'] === $seatId
        ));
    }

    /** Living units within $range hexes, sorted by id. */
    public static function unitsWithin(array $state, string $unitId, int $range, bool $includeSelf): array
    {
        $origin = $state['units'][$unitId]['hex'];
        $found = [];
        foreach ($state['units'] as $id => $unit) {
            $id = (string)$id;
            if ($unit['hex'] === null || (!$includeSelf && $id === $unitId)) {
                continue;
            }
            if (Hex::distance($origin, $unit['hex']) <= $range) {
                $found[] = $id;
            }
        }
        sort($found, SORT_STRING);
        return $found;
    }
}
```

- [ ] **Step 5: Route combat and movement through effective stats**

In `Combat.php`:

- `targetError()`: replace `$attacker['range']` with `Stats::effective($state, $attackerId, 'range')`, and replace the line-of-sight check with `if (!self::visible($state, $attacker['hex'], $target['hex']))`.
- Add:

```php
    public static function visible(array $state, string $fromHex, string $toHex): bool
    {
        return (new HexLineOfSight())->isVisible($fromHex, $toHex, self::sightBoard($state));
    }
```

- `targets()`: replace its body with `return Targeting::enemiesInSight($state, $attackerId, Stats::effective($state, $attackerId, 'range'));`

In `Movement.php` replace `reachable()` with `costs()` + a thin `reachable()`:

```php
    /** Hexes the unit can end on, mapped to the steps it takes to get there. */
    public static function costs(array $state, string $unitId, ?int $budget = null): array
    {
        $unit = $state['units'][$unitId];
        $budget ??= Stats::effective($state, $unitId, 'move');
        $board = $state['board'];
        $occupants = self::occupants($state);
        $frontier = [$unit['hex']];
        $seen = [$unit['hex'] => true];
        $costs = [];
        for ($step = 1; $step <= $budget; $step++) {
            $next = [];
            foreach ($frontier as $from) {
                foreach (Hex::neighbors($from, $board) as $to) {
                    if (isset($seen[$to]) || $board[$to]['sightBlocker']) {
                        continue;
                    }
                    if (abs($board[$to]['elevation'] - $board[$from]['elevation']) > 1) {
                        continue;
                    }
                    $occupant = $occupants[$to] ?? null;
                    if ($occupant !== null && $occupant !== $unit['seatId']) {
                        continue;
                    }
                    $seen[$to] = true;
                    $next[] = $to;
                    if ($occupant === null) {
                        $costs[$to] = $step;
                    }
                }
            }
            $frontier = $next;
        }
        ksort($costs, SORT_STRING);
        return $costs;
    }

    public static function reachable(array $state, string $unitId, ?int $budget = null): array
    {
        return array_map('strval', array_keys(self::costs($state, $unitId, $budget)));
    }
```

- [ ] **Step 6: Expire effects when turns pass**

In `BattleRules.php` replace `passTurn()`:

```php
    private static function passTurn(array $state): array
    {
        $current = $state['turn']['activeSeatId'];
        $next = MatchEngine::otherSeat($state, $current);
        $events = [];
        $state = Effects::expire($state, $current, Effects::UNTIL_END_OF_TURN, $events);
        $round = $state['turn']['round'] + ($next === $state['draft']['firstSeatId'] ? 1 : 0);
        $state['turn'] = Turn::fresh($state, $next, $round);
        $events[] = MatchEngine::event('turnEnded', [], ['seatId' => $current, 'nextSeatId' => $next, 'round' => $round]);
        $state = Effects::expire($state, $next, Effects::UNTIL_NEXT_TURN, $events);
        return [$state, $events];
    }
```

Update its two callers in `apply()`:

```php
        if ($kind === 'endTurn') {
            [$state, $events] = self::passTurn($state);
            return ['state' => $state, 'events' => $events];
        }
```

and

```php
        if ($state['phase'] === 'active' && !$this->canAct($state, $seatId)) {
            [$state, $passed] = self::passTurn($state);
            $events = array_merge($events, $passed);
        }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 8: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/VanillaAbilityTest.php
git commit -m "Add the ability registry, timed effects and effective stats"
```

---

### Task 3: Attacks run as steps; wounds in one place

No behaviour change for unit-only battles except `attackResolved` gaining `ability` and `rerolled` keys.

**Files:**
- Create: `api/src/Rules/Vanilla/Wounds.php`
- Create: `api/src/Rules/Vanilla/StepRunner.php`
- Modify: `api/src/Rules/Vanilla/BattleRules.php`
- Modify: `api/src/Rules/Vanilla/MatchEngine.php`
- Modify: `api/tests/VanillaBattleTest.php`
- Modify: `api/tests/VanillaTestSupport.php`

**Interfaces:**
- Consumes: `Stats::effective`, `Effects::add`, `AbilityRegistry::any` (Task 2).
- Produces:
  - `Wounds::apply(array $state, string $unitId, int $wounds, array &$events): array` — also handles defeat and annihilation (moved from `BattleRules::wound`).
  - `StepRunner::__construct(RandomSource $random, int $now)`, `run(array $state, array $steps, array &$events): array`, `resolve(array $state, string $option, array &$events): array`.
  - Step shapes (arrays with key `op`):
    - `StepRunner::strike(string $attackerId, string $targetId, ?int $power = null, ?string $ability = null, bool $double = false): array`
    - `['op' => 'land', ...]` (internal; created by `strike`)
    - `StepRunner::splash(string $hex, string $excludeUnitId, string $ability): array`
    - `StepRunner::chaos(string $unitId, string $targetId, string $ability): array`
    - `StepRunner::effect(string $name, string $sourceUnitId, string $targetUnitId, string $stat, int $amount, string $seatId, string $at): array`
  - `attackResolved` payload keys, in order: `unitId, targetUnitId, ability, attackFaces, defenseFaces, rerolled, wounds`.
  - `MatchEngine::apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random, ?int $now = null)`, `MatchEngine::legalActions(array $state, ?string $seatId, ?int $now = null)`; `BattleRules::__construct(int $now)`.
  - Test helper `vanilla_command(..., array $rolls = [], ?int $now = null)`.

- [ ] **Step 1: Update the existing exact-payload test (it will fail until the runner exists)**

In `api/tests/VanillaBattleTest.php`, test `'wounds are swords minus shields and lethal wounds remove the unit'`, change the expected payload to:

```php
                ['unitId' => 'a1', 'targetUnitId' => 'b1', 'ability' => null, 'attackFaces' => ['sword', 'sword', 'sword'], 'defenseFaces' => ['shield', 'blank'], 'rerolled' => null, 'wounds' => 2],
```

In `api/tests/VanillaTestSupport.php` change `vanilla_command`:

```php
function vanilla_command(array $state, string $seatId, string $kind, array $payload = [], array $rolls = [], ?int $now = null): array
{
    return (new MatchEngine(test_catalog()))->apply($state, $seatId, $kind, $payload, new SequenceRandom($rolls), $now);
}
```

- [ ] **Step 2: Run tests to verify the payload test fails**

Run: `php api/tests/run.php`
Expected: `FAIL wounds are swords minus shields and lethal wounds remove the unit` (missing `ability`/`rerolled`), everything else PASS.

- [ ] **Step 3: Extract Wounds**

Create `api/src/Rules/Vanilla/Wounds.php` holding the defeat logic moved out of `BattleRules` (Fragile Focus and One Last Horn hooks are wired here now; their handlers arrive in Task 5):

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Abilities\Ability;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;

final class Wounds
{
    public static function apply(array $state, string $unitId, int $wounds, array &$events): array
    {
        $unit = $state['units'][$unitId];
        if ($wounds <= 0 || $unit['hex'] === null || $state['phase'] !== 'active') {
            return $state;
        }
        if (AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->locksSpellsWhenWounded())) {
            $state['units'][$unitId]['flags']['spellsLockedOnTurn'] = Turn::nextTurnNumberOf($state, $unit['seatId']);
        }
        $total = min($unit['hp'], $unit['wounds'] + $wounds);
        if ($total >= $unit['hp']
            && empty($unit['flags']['lastHornSpent'])
            && AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->survivesDefeatOnce())) {
            $state['units'][$unitId]['wounds'] = $unit['hp'] - 1;
            $state['units'][$unitId]['flags']['lastHornSpent'] = true;
            $events[] = MatchEngine::event('survivedDefeat', [$unit['hex']], ['unitId' => $unitId, 'ability' => 'One Last Horn']);
            return $state;
        }
        $state['units'][$unitId]['wounds'] = $total;
        if ($total < $unit['hp']) {
            return $state;
        }
        $events[] = MatchEngine::event('unitDefeated', [$unit['hex']], ['unitId' => $unitId]);
        $state['units'][$unitId]['hex'] = null;
        if ($state['turn']['activeUnitId'] === $unitId) {
            $state['turn']['activeUnitId'] = null;
        }
        foreach ($state['seats'] as $seatId) {
            if (!self::anyAlive($state, $seatId)) {
                $winner = MatchEngine::otherSeat($state, $seatId);
                $state['phase'] = 'completed';
                $state['result'] = ['reason' => 'annihilation', 'winningSeatId' => $winner];
                $state['turn']['activeUnitId'] = null;
                $state['pending'] = null;
                $events[] = MatchEngine::event('matchCompleted', [], ['winningSeatId' => $winner, 'reason' => 'annihilation']);
                break;
            }
        }
        return $state;
    }

    public static function anyAlive(array $state, string $seatId): bool
    {
        foreach ($state['units'] as $unit) {
            if ($unit['seatId'] === $seatId && $unit['hex'] !== null) {
                return true;
            }
        }
        return false;
    }
}
```

In `BattleRules.php`: delete `wound()` and `anyAlive()`; replace every `$this->wound($state, X, N, $events)` with `Wounds::apply($state, X, N, $events)`.

- [ ] **Step 4: Create StepRunner (strike/land only for now; the Glare check is added in Task 9)**

`api/src/Rules/Vanilla/StepRunner.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;

/**
 * Resolves attacks as a queue of steps. A step may add steps in front of the queue, and may open a
 * choice, which saves the rest of the queue in $state['pending'] until resolve() continues it.
 */
final class StepRunner
{
    public function __construct(private readonly RandomSource $random, private readonly int $now)
    {
    }

    public static function strike(string $attackerId, string $targetId, ?int $power = null, ?string $ability = null, bool $double = false): array
    {
        return ['op' => 'strike', 'attackerId' => $attackerId, 'targetId' => $targetId, 'power' => $power, 'ability' => $ability, 'double' => $double];
    }

    public static function splash(string $hex, string $excludeUnitId, string $ability): array
    {
        return ['op' => 'splash', 'hex' => $hex, 'excludeUnitId' => $excludeUnitId, 'ability' => $ability];
    }

    public static function chaos(string $unitId, string $targetId, string $ability): array
    {
        return ['op' => 'chaos', 'unitId' => $unitId, 'targetId' => $targetId, 'ability' => $ability];
    }

    public static function effect(string $name, string $sourceUnitId, string $targetUnitId, string $stat, int $amount, string $seatId, string $at): array
    {
        return [
            'op' => 'effect', 'name' => $name, 'sourceUnitId' => $sourceUnitId, 'targetUnitId' => $targetUnitId,
            'stat' => $stat, 'amount' => $amount, 'seatId' => $seatId, 'at' => $at,
        ];
    }

    public function run(array $state, array $steps, array &$events): array
    {
        while ($steps !== [] && $state['phase'] === 'active') {
            $step = array_shift($steps);
            [$state, $more, $choice] = $this->execute($state, $step, $events);
            $steps = array_merge($more, $steps);
            if ($choice !== null) {
                $state['pending'] = ['choice' => $choice, 'steps' => $steps];
                $events[] = MatchEngine::event('choiceOpened', [], [
                    'choiceId' => $choice['choiceId'], 'seatId' => $choice['seatId'], 'kind' => $choice['kind'], 'unitId' => $choice['unitId'],
                ]);
                return $state;
            }
        }
        return $state;
    }

    /** @return array{0: array, 1: array, 2: ?array} state, steps to run next, choice to open */
    private function execute(array $state, array $step, array &$events): array
    {
        return match ($step['op']) {
            'strike' => $this->strikeStep($state, $step, $events),
            'land' => [$this->land($state, $step, $events), [], null],
            default => throw new \LogicException("Unknown step {$step['op']}."),
        };
    }

    private function strikeStep(array $state, array $step, array &$events): array
    {
        $attackerId = $step['attackerId'];
        $targetId = $step['targetId'];
        if ($state['units'][$attackerId]['hex'] === null || $state['units'][$targetId]['hex'] === null) {
            return [$state, [], null];
        }
        $context = ['attackerId' => $attackerId, 'defenderId' => $targetId];
        $power = Stats::effective($state, $attackerId, 'power', $context, $step['power']);
        if ($step['double']) {
            $power *= 2;
        }
        $defense = Stats::effective($state, $targetId, 'defense', $context);
        $dice = new CustomDice();
        $land = [
            'op' => 'land',
            'attackerId' => $attackerId,
            'targetId' => $targetId,
            'ability' => $step['ability'],
            'attackFaces' => $dice->roll($power, $this->random)['faces'],
            'defenseFaces' => $dice->roll($defense, $this->random)['faces'],
            'rerolled' => null,
        ];
        return [$state, [$land], null];
    }

    private function land(array $state, array $step, array &$events): array
    {
        $swords = count(array_filter($step['attackFaces'], static fn(string $face): bool => $face === 'sword'));
        $shields = count(array_filter($step['defenseFaces'], static fn(string $face): bool => $face === 'shield'));
        $wounds = max(0, $swords - $shields);
        $events[] = MatchEngine::event(
            'attackResolved',
            [$state['units'][$step['attackerId']]['hex'] ?? $state['units'][$step['targetId']]['hex'], $state['units'][$step['targetId']]['hex']],
            [
                'unitId' => $step['attackerId'],
                'targetUnitId' => $step['targetId'],
                'ability' => $step['ability'],
                'attackFaces' => $step['attackFaces'],
                'defenseFaces' => $step['defenseFaces'],
                'rerolled' => $step['rerolled'],
                'wounds' => $wounds,
            ]
        );
        return Wounds::apply($state, $step['targetId'], $wounds, $events);
    }
}
```

Note: `land()`'s first focus hex falls back to the target's hex when the attacker died before landing (possible after a Moonlight Glare pause, Task 9).

- [ ] **Step 5: Use the runner in BattleRules and thread `$now`**

`BattleRules.php`:

```php
    public function __construct(private readonly int $now)
    {
    }
```

Replace the body of `attack()` after the `$blocked` check with:

```php
        $state['turn']['attacks']++;
        $state['turn']['activeUnitId'] = null;
        return (new StepRunner($random, $this->now))->run($state, [StepRunner::strike($unitId, $targetId)], $events);
```

`MatchEngine.php`:

```php
    public function apply(array $state, string $seatId, string $kind, array $payload, RandomSource $random, ?int $now = null): array
    {
        $state = self::normalize($state);
        return match ($state['phase']) {
            'draft' => (new DraftRules($this->catalog))->apply($state, $seatId, $kind, $payload),
            'active' => (new BattleRules($now ?? time()))->apply($state, $seatId, $kind, $payload, $random),
            default => throw new GameServiceException('matchCompleted', 'The match is already over.'),
        };
    }

    public function legalActions(array $state, ?string $seatId, ?int $now = null): array
    {
        if ($seatId === null) {
            return [];
        }
        $state = self::normalize($state);
        return match ($state['phase']) {
            'draft' => (new DraftRules($this->catalog))->legalActions($state, $seatId),
            'active' => (new BattleRules($now ?? time()))->legalActions($state, $seatId),
            default => [],
        };
    }
```

Search for other `new BattleRules(` uses (`grep -rn "new BattleRules" api`) and pass `time()` if any exist outside `MatchEngine`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add api/src/Rules/Vanilla api/tests
git commit -m "Resolve attacks through a step runner and centralize wounds"
```

---

### Task 4: Passive essences (group A) and Fragile Focus flag

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/TremblingFear.php`, `RecklessFury.php`, `UnbreakableLine.php`, `Evasion.php`, `OneLastHorn.php`, `FragileFocus.php`
- Modify: `api/src/Rules/Vanilla/Abilities/AbilityRegistry.php`
- Modify: `api/src/Rules/Vanilla/StepRunner.php` (evasion in `strikeStep`)
- Modify: `api/src/Rules/Vanilla/BattleRules.php` (evasion on disengagement)
- Test: `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `Ability` hooks, `Stats::effective` context keys `attackerId`/`defenderId`, `Wounds::apply` hooks (Tasks 2–3).
- Produces: event `evaded` with payload `{unitId, attackerId, roll, evaded}`; disengagement hit entries gain `evasionRoll` and `evaded` keys **only when the mover has Evasion**.

- [ ] **Step 1: Write the failing tests**

Append to `vanilla_ability_tests()`:

```php
        'Trembling Fear weakens non-hero attackers only' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('a-hero', 'seat-a', '2,0', ['power' => 3, 'isHero' => true]),
                ability_unit('b-hero', 'seat-b', '1,0', ['Trembling Fear'], ['isHero' => true]),
            ]);
            $vs = ['attackerId' => 'a1', 'defenderId' => 'b-hero'];
            expect_same(2, Stats::effective($state, 'a1', 'power', $vs), 'a unit attacking Urgrok loses one Power');
            expect_same(3, Stats::effective($state, 'a-hero', 'power', ['attackerId' => 'a-hero', 'defenderId' => 'b-hero']), 'heroes are not afraid');
            expect_same(3, Stats::effective($state, 'a1', 'power'), 'outside an attack nothing changes');
        },

        'Reckless Fury adds a Power per wound' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(2)), [
                ability_unit('a1', 'seat-a', '0,0', ['Reckless Fury'], ['power' => 2, 'wounds' => 2, 'hp' => 5]),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(4, Stats::effective($state, 'a1', 'power'), 'two wounds, two more Power');
        },

        'Unbreakable Line needs the other guard or the center adjacent' => static function (): void {
            $guard = ability_unit('a1', 'seat-a', '0,0', ['Unbreakable Line'], ['name' => 'Left Guard', 'defense' => 3]);
            $alone = vanilla_battle(vanilla_board(vanilla_line(4)), [$guard, vanilla_unit('a2', 'seat-a', '2,0', ['name' => 'Center']), vanilla_unit('b1', 'seat-b', '3,0')]);
            expect_same(3, Stats::effective($alone, 'a1', 'defense'), 'no partner adjacent');
            $line = vanilla_battle(vanilla_board(vanilla_line(4)), [$guard, vanilla_unit('a2', 'seat-a', '1,0', ['name' => 'Center']), vanilla_unit('b1', 'seat-b', '3,0')]);
            expect_same(4, Stats::effective($line, 'a1', 'defense'), 'the Center holds the line');
            $enemyCenter = vanilla_battle(vanilla_board(vanilla_line(4)), [$guard, vanilla_unit('b2', 'seat-b', '1,0', ['name' => 'Center']), vanilla_unit('b1', 'seat-b', '3,0')]);
            expect_same(3, Stats::effective($enemyCenter, 'a1', 'defense'), 'an enemy Center is no partner');
        },

        'Evasion makes attacks miss on an 11 or higher' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('a2', 'seat-a', '2,0', ['power' => 3]),
                ability_unit('b1', 'seat-b', '1,0', ['Evasion'], ['defense' => 1, 'hp' => 5]),
            ]);
            $missed = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [11]);
            expect_same(['evaded'], array_column($missed['events'], 'kind'), 'no dice are rolled after a successful evasion');
            expect_same(['unitId' => 'b1', 'attackerId' => 'a1', 'roll' => 11, 'evaded' => true], $missed['events'][0]['payload'], 'the roll is reported');
            $hit = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [10, 1, 1, 1, 6]);
            expect_same(['evaded', 'attackResolved'], array_column($hit['events'], 'kind'), 'a failed evasion still rolls the attack');
            expect_same(3, $hit['state']['units']['b1']['wounds'], 'the attack lands');
        },

        'Evasion applies to disengagement strikes' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '1,0', ['Evasion']),
                vanilla_unit('b1', 'seat-b', '0,0'),
            ]);
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [15]);
            expect_same(
                [['byUnitId' => 'b1', 'faces' => [], 'wounds' => 0, 'evasionRoll' => 15, 'evaded' => true]],
                $moved['events'][0]['payload']['disengagement'],
                'the strike is evaded before any die'
            );
        },

        'One Last Horn survives the first defeat at 1 HP' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                ability_unit('b-hero', 'seat-b', '1,0', ['One Last Horn'], ['isHero' => true, 'defense' => 0, 'hp' => 2]),
                vanilla_unit('b2', 'seat-b', '2,0'),
            ]);
            $first = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b-hero'], [1, 1, 1]);
            expect_same(['attackResolved', 'survivedDefeat'], array_slice(array_column($first['events'], 'kind'), 0, 2), 'the horn sounds');
            expect_same(1, $first['state']['units']['b-hero']['wounds'], 'he stands at 1 HP');
            expect_same(true, $first['state']['units']['b-hero']['flags']['lastHornSpent'], 'the horn is spent');
            $later = $first['state'];
            $later['turn'] = Turn::fresh($later, 'seat-a', 3);
            $second = vanilla_command($later, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b-hero'], [1, 1, 1]);
            expect_same(null, $second['state']['units']['b-hero']['hex'], 'the second defeat is final');
        },

        'Fragile Focus locks spells on Malachar\'s next turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                ability_unit('b-hero', 'seat-b', '1,0', ['Fragile Focus'], ['isHero' => true, 'defense' => 0, 'hp' => 6]),
            ]);
            $hit = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b-hero'], [1]);
            expect_same(2, $hit['state']['units']['b-hero']['flags']['spellsLockedOnTurn'], 'seat-b\'s next turn is number two');
        },
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the seven new tests FAIL (no handlers registered).

- [ ] **Step 3: Implement the handlers**

All in `api/src/Rules/Vanilla/Abilities/`, namespace `BloodAndBanners\Rules\Vanilla\Abilities`, each starting with `<?php declare(strict_types=1);` as in `Ability.php`.

`TremblingFear.php`:

```php
final class TremblingFear extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        $attacking = $stat === 'power'
            && ($context['defenderId'] ?? null) === $ownerId
            && ($context['attackerId'] ?? null) === $unitId;
        return $attacking && !$state['units'][$unitId]['isHero'] ? -1 : 0;
    }
}
```

`RecklessFury.php`:

```php
final class RecklessFury extends Ability
{
    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        return $stat === 'power' && $unitId === $ownerId ? (int)$state['units'][$ownerId]['wounds'] : 0;
    }
}
```

`UnbreakableLine.php`:

```php
use BloodAndBanners\Rules\Vanilla\Hex;

/** Left Guard and Right Guard: +1 Defense while another member of the line (Left Guard, Center, Right Guard) is adjacent. */
final class UnbreakableLine extends Ability
{
    private const LINE = ['Left Guard', 'Center', 'Right Guard'];

    public function statDelta(array $state, string $ownerId, string $unitId, string $stat, array $context): int
    {
        if ($stat !== 'defense' || $unitId !== $ownerId) {
            return 0;
        }
        $self = $state['units'][$ownerId];
        foreach ($state['units'] as $id => $unit) {
            if ((string)$id === $ownerId || $unit['hex'] === null || $unit['seatId'] !== $self['seatId']) {
                continue;
            }
            if (in_array($unit['name'], self::LINE, true) && $unit['name'] !== $self['name'] && Hex::distance($self['hex'], $unit['hex']) === 1) {
                return 1;
            }
        }
        return 0;
    }
}
```

`Evasion.php`, `OneLastHorn.php`, `FragileFocus.php`:

```php
final class Evasion extends Ability
{
    public function evades(): bool
    {
        return true;
    }
}
```

```php
final class OneLastHorn extends Ability
{
    public function survivesDefeatOnce(): bool
    {
        return true;
    }
}
```

```php
final class FragileFocus extends Ability
{
    public function locksSpellsWhenWounded(): bool
    {
        return true;
    }
}
```

Register them in `AbilityRegistry::handlers()`:

```php
            $list = [
                new TremblingFear('Trembling Fear'),
                new RecklessFury('Reckless Fury'),
                new UnbreakableLine('Unbreakable Line'),
                new Evasion('Evasion'),
                new OneLastHorn('One Last Horn'),
                new FragileFocus('Fragile Focus'),
            ];
```

- [ ] **Step 4: Roll evasion in the runner and on disengagement**

`StepRunner::strikeStep()` — after the dead-unit check, insert:

```php
        if (AbilityRegistry::any($state['units'][$targetId], static fn(Ability $ability): bool => $ability->evades())) {
            $roll = $this->random->roll(20);
            $events[] = MatchEngine::event('evaded', [$state['units'][$targetId]['hex']], [
                'unitId' => $targetId, 'attackerId' => $attackerId, 'roll' => $roll, 'evaded' => $roll > 10,
            ]);
            if ($roll > 10) {
                return [$state, [], null];
            }
        }
```

`BattleRules::move()` — replace the disengagement loop:

```php
        $evades = AbilityRegistry::any($state['units'][$unitId], static fn(Ability $ability): bool => $ability->evades());
        foreach (Movement::disengagedFrom($state, $unitId, $to) as $enemyId) {
            if ($evades) {
                $roll = $random->roll(20);
                if ($roll > 10) {
                    $hits[] = ['byUnitId' => $enemyId, 'faces' => [], 'wounds' => 0, 'evasionRoll' => $roll, 'evaded' => true];
                    continue;
                }
                $hits[] = ['byUnitId' => $enemyId] + Combat::disengagementHit($random) + ['evasionRoll' => $roll, 'evaded' => false];
                continue;
            }
            $hits[] = ['byUnitId' => $enemyId] + Combat::disengagementHit($random);
        }
```

Add `use BloodAndBanners\Rules\Vanilla\Abilities\Ability;` and `use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;` to both `BattleRules.php` and `StepRunner.php`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/VanillaAbilityTest.php
git commit -m "Add passive essences: Trembling Fear, Reckless Fury, Unbreakable Line, Evasion, One Last Horn, Fragile Focus"
```

---

### Task 5: Attack twice and move after attacking (group B)

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/MultiStrike.php`, `SilentSting.php`
- Modify: `api/src/Rules/Vanilla/Abilities/AbilityRegistry.php`
- Modify: `api/src/Rules/Vanilla/BattleRules.php`
- Test: `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: turn fields `attacks`, `moveSpent`, `movedAfterAttack` (Task 1); `Movement::costs` (Task 2).
- Produces (private to `BattleRules`, used by Tasks 6–9): `attackAllowance(array $unit): int`, `attacksLeft(array $state, string $unitId): bool`, `canMove(array $state, string $unitId): bool`, `closeIfSpent(array $state, string $unitId): array`. New error `noAttacksLeft`.

- [ ] **Step 1: Write the failing tests**

```php
        'Dual Strike keeps the activation open for a second attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                ability_unit('a1', 'seat-a', '1,0', ['Dual Strike'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '0,0', ['defense' => 0, 'hp' => 5]),
                vanilla_unit('b2', 'seat-b', '2,0', ['defense' => 0, 'hp' => 5]),
            ]);
            $first = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]);
            expect_same('a1', $first['state']['turn']['activeUnitId'], 'the activation stays open');
            expect_game_error('alreadyMoved', static fn() => vanilla_command($first['state'], 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0']));
            $second = vanilla_command($first['state'], 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b2'], [1]);
            expect_same(1, $second['state']['units']['b2']['wounds'], 'the second strike lands');
            expect_same(null, $second['state']['turn']['activeUnitId'], 'two attacks spend the activation');
        },

        'Silent Sting splits its move around the attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a1', 'seat-a', '0,0', ['Silent Sting'], ['move' => 3, 'range' => 4, 'power' => 1]),
                vanilla_unit('b1', 'seat-b', '5,0', ['defense' => 0, 'hp' => 5]),
            ]);
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0'])['state'];
            $struck = vanilla_command($moved, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1])['state'];
            expect_same('a1', $struck['turn']['activeUnitId'], 'the Thornshaft may still move');
            expect_game_error('noAttacksLeft', static fn() => vanilla_command($struck, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]));
            expect_game_error('hexUnreachable', static fn() => vanilla_command($struck, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '4,0']));
            $after = vanilla_command($struck, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'])['state'];
            expect_same('3,0', $after['units']['a1']['hex'], 'two remaining steps carry it on');
            expect_same(null, $after['turn']['activeUnitId'], 'the move after the attack ends the activation');
        },
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: both FAIL.

- [ ] **Step 3: Implement the handlers**

```php
/** Dual Strike, Twin Strikes, Dual Strikes: two attacks per activation. */
final class MultiStrike extends Ability
{
    public function attacksPerActivation(): int
    {
        return 2;
    }
}
```

```php
final class SilentSting extends Ability
{
    public function movesAfterAttack(): bool
    {
        return true;
    }
}
```

Register: `new MultiStrike('Dual Strike'), new MultiStrike('Twin Strikes'), new MultiStrike('Dual Strikes'), new SilentSting('Silent Sting'),`.

- [ ] **Step 4: Rework the activation flow in BattleRules**

Add the message: `'noAttacksLeft' => 'That unit has already made all of its attacks this activation.',`

Add helpers:

```php
    private static function attackAllowance(array $unit): int
    {
        $allowance = 1;
        foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
            $allowance = max($allowance, $ability->attacksPerActivation());
        }
        return $allowance;
    }

    /** Whether $unitId may still attack, as the active unit or as a fresh activation. */
    private static function attacksLeft(array $state, string $unitId): bool
    {
        if ($state['turn']['activeUnitId'] !== $unitId) {
            return true;
        }
        return $state['turn']['attacks'] < self::attackAllowance($state['units'][$unitId]);
    }

    private static function stings(array $unit): bool
    {
        return AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->movesAfterAttack());
    }

    private static function canMove(array $state, string $unitId): bool
    {
        $turn = $state['turn'];
        if ($turn['activeUnitId'] !== $unitId) {
            return true;
        }
        if ($turn['attacks'] === 0) {
            return !$turn['moved'];
        }
        return self::stings($state['units'][$unitId])
            && !$turn['movedAfterAttack']
            && $turn['moveSpent'] < Stats::effective($state, $unitId, 'move');
    }

    /** End the activation once the unit has nothing left to do with it. */
    private static function closeIfSpent(array $state, string $unitId): array
    {
        if ($state['turn']['activeUnitId'] !== $unitId) {
            return $state;
        }
        if (!self::attacksLeft($state, $unitId) && !($state['turn']['attacks'] > 0 && self::canMove($state, $unitId))) {
            $state['turn']['activeUnitId'] = null;
        }
        return $state;
    }
```

Replace `activate()`'s reset line `$state['turn']['moved'] = false;` with:

```php
        $state['turn']['moved'] = false;
        $state['turn']['attacks'] = 0;
        $state['turn']['moveSpent'] = 0;
        $state['turn']['movedAfterAttack'] = false;
```

Replace `move()`:

```php
    private function move(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $to = self::text($payload, 'to');
        $state = $this->activate($state, $seatId, $unitId);
        if (!self::canMove($state, $unitId)) {
            self::fail('alreadyMoved');
        }
        $budget = Stats::effective($state, $unitId, 'move') - $state['turn']['moveSpent'];
        $costs = Movement::costs($state, $unitId, $budget);
        if (!isset($costs[$to])) {
            self::fail('hexUnreachable');
        }
        $from = $state['units'][$unitId]['hex'];
        $hits = [];
        // (disengagement loop from Task 4, unchanged)
        $state['units'][$unitId]['hex'] = $to;
        $state['turn']['moveSpent'] += $costs[$to];
        if ($state['turn']['attacks'] > 0) {
            $state['turn']['movedAfterAttack'] = true;
        } else {
            $state['turn']['moved'] = true;
        }
        $events[] = MatchEngine::event('unitMoved', [$from, $to], [
            'unitId' => $unitId,
            'from' => $from,
            'to' => $to,
            'disengagement' => $hits,
        ]);
        $state = Wounds::apply($state, $unitId, array_sum(array_column($hits, 'wounds')), $events);
        return self::closeIfSpent($state, $unitId);
    }
```

(Keep Task 4's disengagement loop where the comment is.) `$costs` keys may be strings like `"1,0"`; `isset($costs[$to])` works because `$to` is the same string.

In `attack()`, after `$state = $this->activate(...)` add:

```php
        if (!self::attacksLeft($state, $unitId)) {
            self::fail('noAttacksLeft');
        }
```

and replace the Task 3 tail with:

```php
        $state['turn']['attacks']++;
        $state = self::closeIfSpent($state, $unitId);
        return (new StepRunner($random, $this->now))->run($state, [StepRunner::strike($unitId, $targetId)], $events);
```

In `legalActions()`, change the move condition `if (!$active || !$state['turn']['moved'])` to `if (self::canMove($state, $unitId))`, compute move targets with the remaining budget:

```php
            if (self::canMove($state, $unitId)) {
                $budget = Stats::effective($state, $unitId, 'move') - ($active ? $state['turn']['moveSpent'] : 0);
                $hexes = Movement::reachable($state, $unitId, $budget);
```

and guard the attack listing with `if (self::attacksLeft($state, $unitId))`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS, including the existing `'units activate once per turn and move once per activation'`.

- [ ] **Step 6: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/VanillaAbilityTest.php
git commit -m "Let Dual Strike units attack twice and Silent Sting move after attacking"
```

---

### Task 6: Special attacks (group C except Chain Lightning)

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/SpecialAttack.php`, `FixedStrike.php`, `ChaosStrike.php`, `Fireball.php`, `MysticShield.php`
- Modify: `api/src/Rules/Vanilla/Abilities/AbilityRegistry.php`
- Modify: `api/src/Rules/Vanilla/StepRunner.php` (splash, chaos, effect steps)
- Modify: `api/src/Rules/Vanilla/BattleRules.php` (`specialAttack` command + legal actions)
- Test: `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `Targeting`, `StepRunner::strike/splash/chaos/effect`, `attacksLeft`, `closeIfSpent`.
- Produces:
  - `abstract class SpecialAttack extends Ability` with `targets(array $state, string $unitId): array` (first-target unit ids), `validate(array $state, string $unitId, array $targetIds): ?string`, `steps(array $state, string $unitId, array $targetIds): array`.
  - Command `specialAttack {unitId, ability, targetUnitIds: string[]}`; legal action `['kind' => 'specialAttack', 'unitId' => ..., 'ability' => name, 'targetHexes' => [...]]`.
  - Events `chaosRolled {unitId, roll, struck}`, `splashDamage {ability, hits: [{unitId, faces, wounds}]}`.
  - `BattleRules` private helper `resolveAbility(array $unit, string $name, string $class): Ability` raising `unknownAbility` / `abilityNotPlayable`.

- [ ] **Step 1: Write the failing tests**

```php
        'Bolt of Annihilation strikes at Power 6 from range 5' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Bolt of Annihilation'], ['isHero' => true, 'range' => 1, 'power' => 1]),
                vanilla_unit('b1', 'seat-b', '5,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $actions = (new MatchEngine(test_catalog()))->legalActions($state, 'seat-a');
            expect_same(
                [['kind' => 'specialAttack', 'unitId' => 'a-hero', 'ability' => 'Bolt of Annihilation', 'targetHexes' => ['5,0']]],
                array_values(array_filter($actions, static fn(array $a): bool => $a['kind'] === 'specialAttack')),
                'the bolt reaches what the hero cannot'
            );
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Bolt of Annihilation', 'targetUnitIds' => ['b1']], [1, 1, 1, 1, 1, 6]);
            expect_same('Bolt of Annihilation', $result['events'][0]['payload']['ability'], 'the event names the ability');
            expect_same(6, count($result['events'][0]['payload']['attackFaces']), 'six dice');
            expect_same(5, $result['state']['units']['b1']['wounds'], 'five swords land');
        },

        'special attacks reject unknown, deferred and locked abilities' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Bolt of Annihilation', 'Teleport'], ['isHero' => true]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $payload = static fn(string $name): array => ['unitId' => 'a-hero', 'ability' => $name, 'targetUnitIds' => ['b1']];
            expect_game_error('unknownAbility', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('Fireball')));
            expect_game_error('abilityNotPlayable', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('Teleport')));
            $locked = $state;
            $locked['units']['a-hero']['flags']['spellsLockedOnTurn'] = $state['turn']['number'];
            expect_game_error('abilityLocked', static fn() => vanilla_command($locked, 'seat-a', 'specialAttack', $payload('Bolt of Annihilation')));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Bolt of Annihilation', 'targetUnitIds' => ['a-hero']]));
        },

        'Chaos Strike doubles on 10 or more, fizzles below, and always lowers defense' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Chaos Strike'], ['isHero' => true, 'power' => 2, 'defense' => 3]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $payload = ['unitId' => 'a-hero', 'ability' => 'Chaos Strike', 'targetUnitIds' => ['b1']];
            $surge = vanilla_command($state, 'seat-a', 'specialAttack', $payload, [10, 1, 1, 1, 1]);
            expect_same(['effectApplied', 'chaosRolled', 'attackResolved'], array_slice(array_column($surge['events'], 'kind'), 0, 3), 'the surge doubles the dice (then the spent turn passes)');
            expect_same(4, $surge['state']['units']['b1']['wounds'], 'four dice from Power 2');
            expect_same(1, Stats::effective($surge['state'], 'a-hero', 'defense'), 'Defense drops by two');
            $fizzle = vanilla_command($state, 'seat-a', 'specialAttack', $payload, [9]);
            expect_same(['effectApplied', 'chaosRolled'], array_slice(array_column($fizzle['events'], 'kind'), 0, 2), 'no attack on a 9');
            expect_same(['unitId' => 'a-hero', 'roll' => 9, 'struck' => false], $fizzle['events'][1]['payload'], 'the roll is reported');
        },

        'Fireball splashes every other adjacent unit for one die without defense' => static function (): void {
            $board = vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 0, '4,0' => 0, '3,-1' => 0]);
            $state = vanilla_battle($board, [
                ability_unit('a1', 'seat-a', '0,0', ['Fireball'], ['range' => 1]),
                vanilla_unit('a2', 'seat-a', '4,0', ['hp' => 3]),
                vanilla_unit('b1', 'seat-b', '3,0', ['defense' => 0, 'hp' => 9]),
                vanilla_unit('b2', 'seat-b', '3,-1', ['hp' => 1]),
            ]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a1', 'ability' => 'Fireball', 'targetUnitIds' => ['b1']], [6, 6, 6, 6, 1, 1]);
            expect_same(['attackResolved', 'splashDamage', 'unitDefeated'], array_slice(array_column($result['events'], 'kind'), 0, 3), 'strike, splash, then a casualty');
            expect_same(
                ['ability' => 'Fireball', 'hits' => [['unitId' => 'a2', 'faces' => ['sword'], 'wounds' => 1], ['unitId' => 'b2', 'faces' => ['sword'], 'wounds' => 1]]],
                $result['events'][1]['payload'],
                'friends are scorched too'
            );
            expect_same(null, $result['state']['units']['b2']['hex'], 'the splash can kill');
        },

        'Fireball splash that wipes out the last enemy ends the match without further steps' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Fireball']),
                vanilla_unit('b1', 'seat-b', '2,0', ['defense' => 0, 'hp' => 1]),
                vanilla_unit('b2', 'seat-b', '3,0', ['hp' => 1]),
            ]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a1', 'ability' => 'Fireball', 'targetUnitIds' => ['b1']], [1, 6, 6, 6, 1]);
            expect_same('completed', $result['state']['phase'], 'the splash finishes the war');
            expect_same(null, $result['state']['pending'], 'nothing is left pending');
        },

        'Mystic Shield gives an ally +3 Defense and uses the attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Mystic Shield'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '4,0', ['defense' => 1]),
                vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Mystic Shield', 'targetUnitIds' => ['a2']]);
            expect_same(4, Stats::effective($result['state'], 'a2', 'defense'), 'the ward holds');
            expect_same(null, $result['state']['turn']['activeUnitId'], 'the spell was Malachar\'s attack');
        },
```

Check the Fireball board: `vanilla_board` keys must be strings like `'3,-1'`; neighbours of `3,0` within the board are `2,0`, `4,0`, `3,-1` (direction `[0,-1]`) — `2,0` is empty, so splash hits `a2` (4,0) and `b2` (3,-1) in id order. Dice script: Power 4 → `6,6,6,6` (no swords), Defense 0 → none, splash `a2` → `1`, `b2` → `1`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the six new tests FAIL (`unsupportedCommand`).

- [ ] **Step 3: Implement the special-attack classes**

`SpecialAttack.php`:

```php
/** An Attack-type ability: used instead of the unit's normal attack. */
abstract class SpecialAttack extends Ability
{
    /** Unit ids this attack may target first. */
    abstract public function targets(array $state, string $unitId): array;

    /** Steps resolving the attack against the ordered targets. */
    abstract public function steps(array $state, string $unitId, array $targetIds): array;

    /** Error code for an invalid target list, or null. Single-target attacks accept exactly one listed target. */
    public function validate(array $state, string $unitId, array $targetIds): ?string
    {
        return count($targetIds) === 1 && in_array($targetIds[0], $this->targets($state, $unitId), true)
            ? null
            : 'invalidAbilityTarget';
    }
}
```

`FixedStrike.php`:

```php
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** A plain strike with a printed Power and Range (Bolt of Annihilation, Flame Strike). */
final class FixedStrike extends SpecialAttack
{
    public function __construct(string $name, private readonly int $power, private readonly int $range)
    {
        parent::__construct($name);
    }

    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, $this->range);
    }

    public function steps(array $state, string $unitId, array $targetIds): array
    {
        return [StepRunner::strike($unitId, $targetIds[0], $this->power, $this->name)];
    }
}
```

`ChaosStrike.php`:

```php
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class ChaosStrike extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, Stats::effective($state, $unitId, 'range'));
    }

    public function steps(array $state, string $unitId, array $targetIds): array
    {
        return [StepRunner::chaos($unitId, $targetIds[0], $this->name)];
    }
}
```

`Fireball.php`:

```php
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class Fireball extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, 4);
    }

    public function steps(array $state, string $unitId, array $targetIds): array
    {
        $target = $targetIds[0];
        return [
            StepRunner::strike($unitId, $target, 4, $this->name),
            StepRunner::splash($state['units'][$target]['hex'], $target, $this->name),
        ];
    }
}
```

`MysticShield.php`:

```php
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

final class MysticShield extends SpecialAttack
{
    public function targets(array $state, string $unitId): array
    {
        return Targeting::alliesWithin($state, $unitId, 4, true);
    }

    public function steps(array $state, string $unitId, array $targetIds): array
    {
        return [StepRunner::effect($this->name, $unitId, $targetIds[0], 'defense', 3, $state['units'][$unitId]['seatId'], Effects::UNTIL_NEXT_TURN)];
    }
}
```

Register:

```php
                new FixedStrike('Bolt of Annihilation', 6, 5),
                new FixedStrike('Flame Strike', 3, 5),
                new ChaosStrike('Chaos Strike'),
                new Fireball('Fireball'),
                new MysticShield('Mystic Shield'),
```

- [ ] **Step 4: Add the splash, chaos and effect steps to StepRunner**

Extend `execute()`'s `match`:

```php
            'splash' => [$this->splashStep($state, $step, $events), [], null],
            'chaos' => $this->chaosStep($state, $step, $events),
            'effect' => [Effects::add($state, $step['name'], $step['sourceUnitId'], $step['targetUnitId'], $step['stat'], $step['amount'], $step['seatId'], $step['at'], $events), [], null],
```

and add:

```php
    private function splashStep(array $state, array $step, array &$events): array
    {
        $dice = new CustomDice();
        $hits = [];
        $ids = array_map('strval', array_keys($state['units']));
        sort($ids, SORT_STRING);
        foreach ($ids as $unitId) {
            $unit = $state['units'][$unitId];
            if ($unitId === $step['excludeUnitId'] || $unit['hex'] === null || Hex::distance($step['hex'], $unit['hex']) !== 1) {
                continue;
            }
            $roll = $dice->roll(1, $this->random);
            $hits[] = ['unitId' => $unitId, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
        }
        if ($hits === []) {
            return $state;
        }
        $events[] = MatchEngine::event('splashDamage', [$step['hex']], ['ability' => $step['ability'], 'hits' => $hits]);
        foreach ($hits as $hit) {
            $state = Wounds::apply($state, $hit['unitId'], $hit['wounds'], $events);
        }
        return $state;
    }

    private function chaosStep(array $state, array $step, array &$events): array
    {
        $unit = $state['units'][$step['unitId']];
        if ($unit['hex'] === null) {
            return [$state, [], null];
        }
        $state = Effects::add($state, $step['ability'], $step['unitId'], $step['unitId'], 'defense', -2, $unit['seatId'], Effects::UNTIL_NEXT_TURN, $events);
        $roll = $this->random->roll(20);
        $events[] = MatchEngine::event('chaosRolled', [$unit['hex']], ['unitId' => $step['unitId'], 'roll' => $roll, 'struck' => $roll >= 10]);
        return [$state, $roll >= 10 ? [self::strike($step['unitId'], $step['targetId'], null, $step['ability'], true)] : [], null];
    }
```

The splash rolls its dice in unit-id order so scripted tests are deterministic.

- [ ] **Step 5: Add the specialAttack command and legal actions to BattleRules**

Add `'specialAttack'` to `KINDS`, the messages:

```php
        'unknownAbility' => 'That unit does not have that ability.',
        'abilityNotPlayable' => 'That ability is not yet in the digital game.',
        'abilityLocked' => 'Fragile Focus: that unit cannot cast spells this turn.',
        'invalidAbilityTarget' => 'That is not a valid target for this ability.',
```

the dispatch line in `apply()`:

```php
        } elseif ($kind === 'specialAttack') {
            $state = $this->specialAttack($state, $seatId, $payload, $random, $events);
```

and:

```php
    private function specialAttack(array $state, string $seatId, array $payload, RandomSource $random, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $name = self::text($payload, 'ability');
        $targets = array_values(array_filter($payload['targetUnitIds'] ?? [], 'is_string'));
        $state = $this->activate($state, $seatId, $unitId);
        $ability = self::resolveAbility($state['units'][$unitId], $name, SpecialAttack::class);
        if (self::spellsLocked($state, $unitId)) {
            self::fail('abilityLocked');
        }
        if (!self::attacksLeft($state, $unitId)) {
            self::fail('noAttacksLeft');
        }
        $error = $ability->validate($state, $unitId, $targets);
        if ($error !== null) {
            self::fail($error);
        }
        $state['turn']['attacks']++;
        $state = self::closeIfSpent($state, $unitId);
        return (new StepRunner($random, $this->now))->run($state, $ability->steps($state, $unitId, $targets), $events);
    }

    /** The unit's handler for $name, which must be an instance of $class. */
    private static function resolveAbility(array $unit, string $name, string $class): Ability
    {
        $printed = in_array($name, array_column($unit['abilities'], 'name'), true);
        $handler = AbilityRegistry::find($unit, $name);
        if (!$printed) {
            self::fail('unknownAbility');
        }
        if ($handler === null) {
            self::fail('abilityNotPlayable');
        }
        if (!$handler instanceof $class) {
            self::fail('unknownAbility');
        }
        return $handler;
    }

    private static function spellsLocked(array $state, string $unitId): bool
    {
        return ($state['units'][$unitId]['flags']['spellsLockedOnTurn'] ?? null) === $state['turn']['number'];
    }
```

Note: `activate()` runs first, so an unknown-ability error on a fresh unit throws and discards the activation (state is a value, not saved on error).

In `legalActions()`, inside the per-unit loop after the normal attack block:

```php
            if (self::attacksLeft($state, $unitId) && !self::spellsLocked($state, $unitId)) {
                foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                    if (!$ability instanceof SpecialAttack) {
                        continue;
                    }
                    $targets = $ability->targets($state, $unitId);
                    if ($targets !== []) {
                        $actions[] = [
                            'kind' => 'specialAttack',
                            'unitId' => $unitId,
                            'ability' => $ability->name,
                            'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $targets),
                        ];
                    }
                }
            }
```

Update `canAct()` to count `specialAttack` too: `if (in_array($action['kind'], ['moveUnit', 'attackUnit', 'specialAttack', 'useAbility'], true))`.

Add `use BloodAndBanners\Rules\Vanilla\Abilities\SpecialAttack;` to `BattleRules.php` and `use` lines for `Effects`/`Hex` to `StepRunner.php` as needed (same namespace — only the `Abilities` classes need imports).

- [ ] **Step 6: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/VanillaAbilityTest.php
git commit -m "Add special attacks: Bolt of Annihilation, Flame Strike, Chaos Strike, Fireball, Mystic Shield"
```

---

### Task 7: Chain Lightning

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/ChainLightning.php`
- Modify: `api/src/Rules/Vanilla/Abilities/AbilityRegistry.php`, `api/src/Rules/Vanilla/BattleRules.php` (message)
- Test: `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `SpecialAttack`, `Targeting::enemiesInSight`, `StepRunner::strike`.
- Produces: `ChainLightning::REACH = 5`; error `chainTooLong`. Legal-action target hexes = valid first hops.

- [ ] **Step 1: Write the failing tests**

```php
        'Chain Lightning hops between up to three enemies within five hexes in total' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(8)), [
                ability_unit('a1', 'seat-a', '0,0', ['Chain Lightning']),
                vanilla_unit('b1', 'seat-b', '2,0', ['defense' => 0, 'hp' => 9]),
                vanilla_unit('b2', 'seat-b', '4,0', ['defense' => 0, 'hp' => 9]),
                vanilla_unit('b3', 'seat-b', '5,0', ['defense' => 0, 'hp' => 9]),
                vanilla_unit('b4', 'seat-b', '7,0', ['defense' => 0, 'hp' => 9]),
                vanilla_unit('a2', 'seat-a', '6,0'),
            ]);
            $payload = static fn(array $targets): array => ['unitId' => 'a1', 'ability' => 'Chain Lightning', 'targetUnitIds' => $targets];
            $result = vanilla_command($state, 'seat-a', 'specialAttack', $payload(['b1', 'b2', 'b3']), [1, 1, 1, 1, 1, 1, 1, 1, 1]);
            expect_same(['attackResolved', 'attackResolved', 'attackResolved'], array_column($result['events'], 'kind'), 'three links, each defended separately');
            expect_same([3, 3, 3], [$result['state']['units']['b1']['wounds'], $result['state']['units']['b2']['wounds'], $result['state']['units']['b3']['wounds']], 'each takes Power 3');
            expect_game_error('chainTooLong', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload(['b1', 'b2', 'b4'])));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload(['b1', 'b1'])));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload([])));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload(['b1', 'b2', 'b3', 'b4'])));
        },

        'a chain link that kills does not stop later links' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Chain Lightning']),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 1]),
                vanilla_unit('b2', 'seat-b', '2,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a1', 'ability' => 'Chain Lightning', 'targetUnitIds' => ['b1', 'b2']], [1, 1, 1, 1, 1, 1]);
            expect_same(['attackResolved', 'unitDefeated', 'attackResolved'], array_slice(array_column($result['events'], 'kind'), 0, 3), 'the bolt carries on (then the spent turn passes)');
            expect_same(3, $result['state']['units']['b2']['wounds'], 'the second link lands');
        },
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: both FAIL (`unknownAbility`).

- [ ] **Step 3: Implement**

```php
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** Power 3 against 1–3 enemies in order; the first needs sight within 5, and all hops together cover at most 5 hexes. */
final class ChainLightning extends SpecialAttack
{
    public const REACH = 5;
    private const POWER = 3;
    private const MAX_TARGETS = 3;

    public function targets(array $state, string $unitId): array
    {
        return Targeting::enemiesInSight($state, $unitId, self::REACH);
    }

    public function validate(array $state, string $unitId, array $targetIds): ?string
    {
        $count = count($targetIds);
        if ($count < 1 || $count > self::MAX_TARGETS || count(array_unique($targetIds)) !== $count) {
            return 'invalidAbilityTarget';
        }
        if (!in_array($targetIds[0], $this->targets($state, $unitId), true)) {
            return 'invalidAbilityTarget';
        }
        $seatId = $state['units'][$unitId]['seatId'];
        $from = $state['units'][$unitId]['hex'];
        $total = 0;
        foreach ($targetIds as $targetId) {
            $target = $state['units'][$targetId] ?? null;
            if ($target === null || $target['hex'] === null || $target['seatId'] === $seatId) {
                return 'invalidAbilityTarget';
            }
            $total += Hex::distance($from, $target['hex']);
            $from = $target['hex'];
        }
        return $total > self::REACH ? 'chainTooLong' : null;
    }

    public function steps(array $state, string $unitId, array $targetIds): array
    {
        return array_map(fn(string $targetId): array => StepRunner::strike($unitId, $targetId, self::POWER, $this->name), $targetIds);
    }
}
```

Register `new ChainLightning('Chain Lightning'),`. Add the message `'chainTooLong' => 'Chain Lightning can only travel 5 hexes in total.',` to `BattleRules::MESSAGES`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/VanillaAbilityTest.php
git commit -m "Add Chain Lightning"
```

---

### Task 8: Targeted abilities (group D) and the useAbility command

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/TargetedAbility.php`, `StatBlessing.php`
- Modify: `api/src/Rules/Vanilla/Abilities/AbilityRegistry.php`, `api/src/Rules/Vanilla/BattleRules.php`
- Test: `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `Effects::add`, `Targeting`, `resolveAbility`, `activate`, turn `abilitiesUsed`.
- Produces:
  - `abstract class TargetedAbility extends Ability` with `targets(array $state, string $unitId): array` and `apply(array $state, string $unitId, string $targetId, array &$events): array`.
  - Command `useAbility {unitId, ability, targetUnitId}`; event `abilityUsed {unitId, ability, targetUnitId}`; legal action `['kind' => 'useAbility', 'unitId', 'ability', 'targetHexes']`.

- [ ] **Step 1: Write the failing tests**

```php
        'Shield Wall guards an adjacent ally once per turn without spending the attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Shield Wall'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '1,0', ['defense' => 2]),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $used = vanilla_command($state, 'seat-a', 'useAbility', ['unitId' => 'a-hero', 'ability' => 'Shield Wall', 'targetUnitId' => 'a2']);
            expect_same(['effectApplied', 'abilityUsed'], array_column($used['events'], 'kind'), 'the wall goes up');
            expect_same(3, Stats::effective($used['state'], 'a2', 'defense'), '+1 Defense');
            expect_same(['a-hero'], $used['state']['turn']['activated'], 'using the ability activated the hero');
            expect_same('a-hero', $used['state']['turn']['activeUnitId'], 'the hero may still move and attack');
            expect_game_error('abilityAlreadyUsed', static fn() => vanilla_command($used['state'], 'seat-a', 'useAbility', ['unitId' => 'a-hero', 'ability' => 'Shield Wall', 'targetUnitId' => 'a2']));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'useAbility', ['unitId' => 'a-hero', 'ability' => 'Shield Wall', 'targetUnitId' => 'a-hero']));
        },

        'an activated unit keeps its unused ability after its activation ends, and the turn does not auto-pass' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a-hero', 'seat-a', '1,0', ['Warlord\'s Command'], ['isHero' => true, 'power' => 1]),
                vanilla_unit('a2', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '2,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $state['turn']['allowance'] = 1;
            $struck = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a-hero', 'targetUnitId' => 'b1'], [6]);
            expect_same('seat-a', $struck['state']['turn']['activeSeatId'], 'the unused command keeps the turn alive');
            $commanded = vanilla_command($struck['state'], 'seat-a', 'useAbility', ['unitId' => 'a-hero', 'ability' => 'Warlord\'s Command', 'targetUnitId' => 'a2']);
            expect_same(
                ['effectApplied', 'abilityUsed', 'effectExpired', 'turnEnded'],
                array_column($commanded['events'], 'kind'),
                'the command lands after the hero acted, then with nothing left the turn passes and the command fades'
            );
            expect_same('seat-b', $commanded['state']['turn']['activeSeatId'], 'the turn has passed');
            expect_same(1, Stats::effective($commanded['state'], 'a2', 'power'), 'Warlord\'s Command lasted only until end of turn');
        },

        'runes reach the Rune Smith\'s range and Distraction can hit anyone' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Rune of Warding', 'Rune of Distraction'], ['range' => 3]),
                vanilla_unit('a2', 'seat-a', '1,0', ['defense' => 1]),
                vanilla_unit('b1', 'seat-b', '3,0', ['defense' => 3]),
                vanilla_unit('b2', 'seat-b', '4,0', ['defense' => 3]),
            ]);
            $actions = array_values(array_filter(
                (new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'),
                static fn(array $a): bool => $a['kind'] === 'useAbility'
            ));
            expect_same(
                [
                    ['kind' => 'useAbility', 'unitId' => 'a1', 'ability' => 'Rune of Warding', 'targetHexes' => ['0,0', '1,0']],
                    ['kind' => 'useAbility', 'unitId' => 'a1', 'ability' => 'Rune of Distraction', 'targetHexes' => ['1,0', '3,0']],
                ],
                $actions,
                'warding covers allies incl. self; distraction covers any other unit in range'
            );
            $hexed = vanilla_command($state, 'seat-a', 'useAbility', ['unitId' => 'a1', 'ability' => 'Rune of Distraction', 'targetUnitId' => 'b1']);
            expect_same(1, Stats::effective($hexed['state'], 'b1', 'defense'), '-2 Defense');
        },
```

Note the legal-action test: target hexes are listed in unit-id order (`Targeting` sorts ids): `a1` (0,0), `a2` (1,0) for warding; `a2` (1,0), `b1` (3,0) for distraction (`b2` at distance 4 is out of range).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the three new tests FAIL (`unsupportedCommand`).

- [ ] **Step 3: Implement**

`TargetedAbility.php`:

```php
/** An Ability-type ability: used once per turn by an activated unit, on top of its move and attack. */
abstract class TargetedAbility extends Ability
{
    abstract public function targets(array $state, string $unitId): array;

    abstract public function apply(array $state, string $unitId, string $targetId, array &$events): array;
}
```

`StatBlessing.php` — one class covers all four buffs/debuffs:

```php
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** Apply a timed stat change to one unit in reach. Reach null = the user's effective Range. */
final class StatBlessing extends TargetedAbility
{
    public const ALLIES = 'allies';
    public const ANYONE = 'anyone';

    public function __construct(
        string $name,
        private readonly string $stat,
        private readonly int $amount,
        private readonly string $expires,
        private readonly ?int $reach,
        private readonly string $who,
        private readonly bool $includeSelf,
    ) {
        parent::__construct($name);
    }

    public function targets(array $state, string $unitId): array
    {
        $reach = $this->reach ?? Stats::effective($state, $unitId, 'range');
        return $this->who === self::ALLIES
            ? Targeting::alliesWithin($state, $unitId, $reach, $this->includeSelf)
            : Targeting::unitsWithin($state, $unitId, $reach, $this->includeSelf);
    }

    public function apply(array $state, string $unitId, string $targetId, array &$events): array
    {
        return Effects::add($state, $this->name, $unitId, $targetId, $this->stat, $this->amount, $state['units'][$unitId]['seatId'], $this->expires, $events);
    }
}
```

Register:

```php
                new StatBlessing('Shield Wall', 'defense', 1, Effects::UNTIL_NEXT_TURN, 1, StatBlessing::ALLIES, false),
                new StatBlessing('Warlord\'s Command', 'power', 1, Effects::UNTIL_END_OF_TURN, 1, StatBlessing::ALLIES, false),
                new StatBlessing('Rune of Warding', 'defense', 2, Effects::UNTIL_NEXT_TURN, null, StatBlessing::ALLIES, true),
                new StatBlessing('Rune of Distraction', 'defense', -2, Effects::UNTIL_NEXT_TURN, null, StatBlessing::ANYONE, false),
```

(add `use BloodAndBanners\Rules\Vanilla\Effects;` to `AbilityRegistry.php`).

- [ ] **Step 4: Add the useAbility command and legal actions**

In `BattleRules`: add `'useAbility'` to `KINDS`, message `'abilityAlreadyUsed' => 'That ability has already been used this turn.'`, dispatch `} elseif ($kind === 'useAbility') { $state = $this->useAbility($state, $seatId, $payload, $events); }`, and:

```php
    private function useAbility(array $state, string $seatId, array $payload, array &$events): array
    {
        $unitId = self::text($payload, 'unitId');
        $name = self::text($payload, 'ability');
        $targetId = self::text($payload, 'targetUnitId');
        $unit = $state['units'][$unitId] ?? null;
        if ($unit === null || $unit['hex'] === null) {
            self::fail('unknownUnit');
        }
        if ($unit['seatId'] !== $seatId) {
            self::fail('notYourUnit');
        }
        $ability = self::resolveAbility($unit, $name, TargetedAbility::class);
        if (in_array($name, $state['turn']['abilitiesUsed'][$unitId] ?? [], true)) {
            self::fail('abilityAlreadyUsed');
        }
        if (!in_array($unitId, $state['turn']['activated'], true)) {
            $state = $this->activate($state, $seatId, $unitId);
        }
        if (!in_array($targetId, $ability->targets($state, $unitId), true)) {
            self::fail('invalidAbilityTarget');
        }
        $state = $ability->apply($state, $unitId, $targetId, $events);
        $state['turn']['abilitiesUsed'][$unitId][] = $name;
        $events[] = MatchEngine::event('abilityUsed', [$unit['hex'], $state['units'][$targetId]['hex']], [
            'unitId' => $unitId, 'ability' => $name, 'targetUnitId' => $targetId,
        ]);
        return $state;
    }
```

In `legalActions()`, the loop currently `continue`s for units that can't start an activation. Restructure the loop head so spent units still offer abilities:

```php
        foreach ($state['units'] as $unitId => $unit) {
            $unitId = (string)$unitId;
            if ($unit['seatId'] !== $seatId || $unit['hex'] === null) {
                continue;
            }
            $active = $state['turn']['activeUnitId'] === $unitId;
            $activated = in_array($unitId, $state['turn']['activated'], true);
            $canStart = !$activated && $this->activationError($state, $unitId) === null;
            if ($activated || $canStart) {
                foreach (AbilityRegistry::abilitiesOf($unit) as $ability) {
                    if (!$ability instanceof TargetedAbility || in_array($ability->name, $state['turn']['abilitiesUsed'][$unitId] ?? [], true)) {
                        continue;
                    }
                    $targets = $ability->targets($state, $unitId);
                    if ($targets !== []) {
                        $actions[] = [
                            'kind' => 'useAbility',
                            'unitId' => $unitId,
                            'ability' => $ability->name,
                            'targetHexes' => array_map(static fn(string $id): string => $state['units'][$id]['hex'], $targets),
                        ];
                    }
                }
            }
            if (!$active && !$canStart) {
                continue;
            }
            // ... existing move / attack / special attack listing unchanged ...
        }
```

The rune test expects only `useAbility` entries filtered, so ordering relative to moves does not matter, but within a unit `useAbility` entries must keep the unit's printed ability order (they do: `abilitiesOf` follows `$unit['abilities']`).

Add `use BloodAndBanners\Rules\Vanilla\Abilities\TargetedAbility;`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/VanillaAbilityTest.php
git commit -m "Add Shield Wall, Warlord's Command and the runes as usable abilities"
```

---

### Task 9: Moonlight Glare and the pending-choice mechanism (group E)

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/MoonlightGlare.php`
- Modify: `api/src/Rules/Vanilla/Abilities/AbilityRegistry.php`, `api/src/Rules/Vanilla/StepRunner.php`, `api/src/Rules/Vanilla/BattleRules.php`
- Test: `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `StepRunner::run` suspension (Task 3), `turn.glareUsed`.
- Produces:
  - `BattleRules::CHOICE_TIMEOUT_SECONDS = 45`.
  - `state.pending = {choice: {choiceId, seatId, kind: 'moonlightGlare', unitId, side: 'attack'|'defense', faces: string[], options: string[], openedAt: int}, steps: array}`; options are `"reroll:<index>"` and `"keep"`.
  - `state.choiceCounter` (int) for choice ids `"choice-<n>"`.
  - Command `resolveChoice {choiceId, option}`; events `choiceOpened`, `choiceResolved {choiceId, option}`; `attackResolved.rerolled = {side, index, from, to}`.
  - Legal actions while pending: owner `[['kind' => 'resolveChoice', 'unitId' => <duelist>, 'targetHexes' => []]]`; other seat the same entry only after 45 s; nothing else.

- [ ] **Step 1: Write the failing tests**

```php
        'Moonlight Glare pauses the defender\'s roll for the owner\'s decision' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 2]),
                ability_unit('b1', 'seat-b', '1,0', ['Moonlight Glare'], ['defense' => 2, 'hp' => 9]),
                vanilla_unit('b2', 'seat-b', '2,0'),
                vanilla_unit('a2', 'seat-a', '3,0'),
            ]);
            $opened = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 1, 4, 6], 1000);
            expect_same(['choiceOpened'], array_column($opened['events'], 'kind'), 'nothing lands yet');
            $choice = $opened['state']['pending']['choice'];
            expect_same(
                ['choiceId' => 'choice-1', 'seatId' => 'seat-b', 'kind' => 'moonlightGlare', 'unitId' => 'b1', 'side' => 'defense', 'faces' => ['shield', 'blank'], 'options' => ['reroll:1', 'keep'], 'openedAt' => 1000],
                $choice,
                'only the blank can be re-rolled'
            );
            expect_game_error('choicePending', static fn() => vanilla_command($opened['state'], 'seat-a', 'endTurn', [], [], 1001));
            expect_game_error('notYourChoice', static fn() => vanilla_command($opened['state'], 'seat-a', 'resolveChoice', ['choiceId' => 'choice-1', 'option' => 'reroll:1'], [], 1001));
            expect_game_error('choiceNotExpired', static fn() => vanilla_command($opened['state'], 'seat-a', 'resolveChoice', ['choiceId' => 'choice-1', 'option' => 'keep'], [], 1044));
            expect_game_error('unknownChoice', static fn() => vanilla_command($opened['state'], 'seat-b', 'resolveChoice', ['choiceId' => 'choice-9', 'option' => 'keep'], [], 1001));
            expect_same(
                [['kind' => 'resolveChoice', 'unitId' => 'b1', 'targetHexes' => []]],
                (new MatchEngine(test_catalog()))->legalActions($opened['state'], 'seat-b', 1001),
                'the owner may only decide'
            );
            expect_same([], (new MatchEngine(test_catalog()))->legalActions($opened['state'], 'seat-a', 1001), 'the attacker waits');

            $saved = json_decode(json_encode($opened['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $resolved = vanilla_command($saved, 'seat-b', 'resolveChoice', ['choiceId' => 'choice-1', 'option' => 'reroll:1'], [5], 1002);
            expect_same(['choiceResolved', 'attackResolved'], array_column($resolved['events'], 'kind'), 'the attack lands after the decision');
            expect_same(['side' => 'defense', 'index' => 1, 'from' => 'blank', 'to' => 'shield'], $resolved['events'][1]['payload']['rerolled'], 'the re-roll is reported');
            expect_same(0, $resolved['state']['units']['b1']['wounds'], 'two shields stop two swords');
            expect_same(null, $resolved['state']['pending'], 'the choice is closed');
            expect_same(['b1'], $resolved['state']['turn']['glareUsed'], 'the glare is spent for this turn');
        },

        'the waiting seat may keep the roll after 45 seconds' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                ability_unit('b1', 'seat-b', '1,0', ['Moonlight Glare'], ['defense' => 1, 'hp' => 9]),
                vanilla_unit('b2', 'seat-b', '2,0'),
            ]);
            $opened = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 6], 1000)['state'];
            expect_same(
                [['kind' => 'resolveChoice', 'unitId' => 'b1', 'targetHexes' => []]],
                (new MatchEngine(test_catalog()))->legalActions($opened, 'seat-a', 1045),
                'after 45 s the attacker may keep for them'
            );
            $kept = vanilla_command($opened, 'seat-a', 'resolveChoice', ['choiceId' => 'choice-1', 'option' => 'keep'], [], 1045);
            expect_same(['choiceResolved', 'attackResolved', 'turnEnded'], array_column($kept['events'], 'kind'), 'the roll stands and the spent turn passes');
            expect_same(1, $kept['state']['units']['b1']['wounds'], 'the sword lands');
            expect_same([], $kept['state']['turn']['glareUsed'], 'the turn has passed, so glare usage is fresh');
        },

        'no prompt when the Duelist\'s dice are already perfect, and once per turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                ability_unit('a1', 'seat-a', '0,0', ['Moonlight Glare'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
                vanilla_unit('a2', 'seat-a', '2,0'),
            ]);
            $perfect = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1], 1000);
            expect_same(['attackResolved'], array_column($perfect['events'], 'kind'), 'a sword needs no re-roll');
            $spent = $state;
            $spent['turn']['glareUsed'] = ['a1'];
            $again = vanilla_command($spent, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [6], 1000);
            expect_same(['attackResolved'], array_column($again['events'], 'kind'), 'the glare was already used this turn');
        },
```

Dice scripts: first test — attacker Power 2 rolls `1,1` (two swords), defender Defense 2 rolls `4,6` (shield, blank); the re-roll consumes `5` (shield). `a2` keeps seat-a able to act so the turn does not auto-pass after the attack lands. Second test — Power 1 `1` (sword), Defense 1 `6` (blank) → options `['reroll:0','keep']`; keep → 1 wound; `a1` was the only attacker and `b2`... seat-a has no other unit, so the turn passes.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the three new tests FAIL.

- [ ] **Step 3: Implement the handler and the glare check**

```php
final class MoonlightGlare extends Ability
{
    public function offersReroll(): bool
    {
        return true;
    }
}
```

Register `new MoonlightGlare('Moonlight Glare'),`.

In `StepRunner::strikeStep()`, replace the final `return [$state, [$land], null];` with:

```php
        $choice = $this->glareChoice($state, $land);
        if ($choice !== null) {
            $state['choiceCounter'] = (int)($state['choiceCounter'] ?? 0) + 1;
            $choice = ['choiceId' => 'choice-' . $state['choiceCounter']] + $choice;
        }
        return [$state, [$land], $choice];
```

(`choiceId` is prepended so the stored choice's keys match the test's expected order.) Then add:

```php
    /** A Moonlight Glare decision for whichever side of this strike has an unspent Duelist and an imperfect die. */
    private function glareChoice(array $state, array $land): ?array
    {
        foreach ([['attack', $land['attackerId'], $land['attackFaces'], 'sword'], ['defense', $land['targetId'], $land['defenseFaces'], 'shield']] as [$side, $unitId, $faces, $best]) {
            $unit = $state['units'][$unitId];
            if (!AbilityRegistry::any($unit, static fn(Ability $ability): bool => $ability->offersReroll())
                || in_array($unitId, $state['turn']['glareUsed'], true)) {
                continue;
            }
            $options = [];
            foreach ($faces as $index => $face) {
                if ($face !== $best) {
                    $options[] = "reroll:{$index}";
                }
            }
            if ($options === []) {
                continue;
            }
            return [
                'seatId' => $unit['seatId'],
                'kind' => 'moonlightGlare',
                'unitId' => $unitId,
                'side' => $side,
                'faces' => $faces,
                'options' => [...$options, 'keep'],
                'openedAt' => $this->now,
            ];
        }
        return null;
    }

    public function resolve(array $state, string $option, array &$events): array
    {
        $pending = $state['pending'];
        $choice = $pending['choice'];
        $steps = $pending['steps'];
        $state['pending'] = null;
        $events[] = MatchEngine::event('choiceResolved', [], ['choiceId' => $choice['choiceId'], 'option' => $option]);
        if (str_starts_with($option, 'reroll:')) {
            $index = (int)substr($option, 7);
            $key = $choice['side'] === 'attack' ? 'attackFaces' : 'defenseFaces';
            $from = $steps[0][$key][$index];
            $to = (new CustomDice())->roll(1, $this->random)['faces'][0];
            $steps[0][$key][$index] = $to;
            $steps[0]['rerolled'] = ['side' => $choice['side'], 'index' => $index, 'from' => $from, 'to' => $to];
            $state['turn']['glareUsed'][] = $choice['unitId'];
        }
        return $this->run($state, $steps, $events);
    }
```

- [ ] **Step 4: Gate commands and legal actions on the pending choice**

In `BattleRules`:

```php
    public const CHOICE_TIMEOUT_SECONDS = 45;
```

Add `'resolveChoice'` to `KINDS` and messages:

```php
        'choicePending' => 'Waiting for a decision before the battle can continue.',
        'notYourChoice' => 'That decision belongs to the other commander.',
        'unknownChoice' => 'That decision is no longer open.',
        'choiceNotExpired' => 'Your opponent still has time to decide.',
```

At the top of `apply()` (after the `KINDS` check, **before** the `notYourTurn` check):

```php
        if ($state['pending'] !== null || $kind === 'resolveChoice') {
            return $this->resolveChoice($state, $seatId, $kind, $payload, $random);
        }
```

and:

```php
    private function resolveChoice(array $state, string $seatId, string $kind, array $payload, RandomSource $random): array
    {
        $choice = $state['pending']['choice'] ?? null;
        if ($choice === null || $kind !== 'resolveChoice') {
            self::fail($choice === null ? 'unknownChoice' : 'choicePending');
        }
        $option = self::text($payload, 'option');
        if (self::text($payload, 'choiceId') !== $choice['choiceId'] || !in_array($option, $choice['options'], true)) {
            self::fail('unknownChoice');
        }
        if ($seatId !== $choice['seatId']) {
            if ($option !== 'keep') {
                self::fail('notYourChoice');
            }
            if ($this->now - $choice['openedAt'] < self::CHOICE_TIMEOUT_SECONDS) {
                self::fail('choiceNotExpired');
            }
        }
        $events = [];
        $state = (new StepRunner($random, $this->now))->resolve($state, $option, $events);
        $active = $state['turn']['activeSeatId'];
        if ($state['phase'] === 'active' && $state['pending'] === null && !$this->canAct($state, $active)) {
            [$state, $passed] = self::passTurn($state);
            $events = array_merge($events, $passed);
        }
        return ['state' => $state, 'events' => $events];
    }
```

Change the auto-pass at the end of `apply()` to skip while a choice is pending:

```php
        if ($state['phase'] === 'active' && $state['pending'] === null && !$this->canAct($state, $seatId)) {
```

At the top of `legalActions()` (after the phase check, before the `activeSeatId !== $seatId` check):

```php
        if ($state['pending'] !== null) {
            $choice = $state['pending']['choice'];
            $mayKeep = $this->now - $choice['openedAt'] >= self::CHOICE_TIMEOUT_SECONDS;
            return $seatId === $choice['seatId'] || $mayKeep
                ? [['kind' => 'resolveChoice', 'unitId' => $choice['unitId'], 'targetHexes' => []]]
                : [];
        }
```

Note `legalActions()` currently starts with `if ($state['phase'] !== 'active' || $state['turn']['activeSeatId'] !== $seatId) return [];` — split it so the pending check sits between the phase test and the seat test.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add api/src/Rules/Vanilla api/tests/VanillaAbilityTest.php
git commit -m "Add Moonlight Glare with a resumable pending choice"
```

---

### Task 10: Project abilities, effects and choices from GameService

**Files:**
- Modify: `api/src/Game/GameService.php`
- Test: `api/tests/VanillaMatchServiceTest.php`, `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `MatchEngine::normalize`, `Stats::effective`, `Effects::forUnit`, `AbilityRegistry::isPlayable`, `BattleRules::CHOICE_TIMEOUT_SECONDS`.
- Produces (JSON projection, camelCase):
  - Unit: `abilities: [{name, type, description, playable, usedThisTurn}]`, `effects: [{name, stat, amount, expires: 'nextTurn'|'endOfTurn'}]`, `effectiveMove`, `effectiveRange`, `effectivePower`, `effectiveDefense`.
  - Draft hero/army unit cards: `abilities: [{name, type, description, playable, usedThisTurn: false}]`.
  - Legal action: `ability: string|null` on every entry.
  - `pendingChoice`: owner only — `{choiceId, seatId, kind, options, unitId, side, faces, expiresAt}` (ISO-8601 UTC, `openedAt + 45`).
  - `pendingChoiceSummary`: everyone — `{choiceId, seatId, unitId, keepableAt}` or null.

- [ ] **Step 1: Write the failing tests**

In `api/tests/VanillaMatchServiceTest.php`, test `'a drafted match projects units turn state and battle actions'`: update the unit-key contract assertion to

```php
            expect_same(
                ['unitId', 'seatId', 'isHero', 'name', 'image', 'move', 'range', 'power', 'defense', 'hp', 'wounds', 'hex', 'activatedThisTurn',
                    'abilities', 'effects', 'effectiveMove', 'effectiveRange', 'effectivePower', 'effectiveDefense'],
                array_keys($projection['units'][0]),
                'unit projections match the shared contract'
            );
```

and insert these assertions right after the `'the host opens round one'` assertion (`$projection` is the active-match projection there):

```php
            $captain = array_values(array_filter($projection['units'], static fn(array $unit): bool => $unit['name'] === 'Test Captain'))[0];
            expect_same(
                [['name' => 'Shield Wall', 'type' => 'Ability', 'description' => 'Grant one adjacent unit +1 Defense until the start of your next turn', 'playable' => true, 'usedThisTurn' => false]],
                $captain['abilities'],
                'units project their abilities'
            );
            expect_same([], $captain['effects'], 'no effects yet');
            expect_same($captain['defense'], $captain['effectiveDefense'], 'effective stats match base with no modifiers');
            foreach ($projection['legalActions'] as $action) {
                expect_true(array_key_exists('ability', $action), 'every legal action carries an ability key');
            }
            expect_same(null, $projection['pendingChoiceSummary'], 'nothing is pending');
```

Append to `vanilla_ability_tests()` an old-state compatibility test (Review Focus 5):

```php
        'a match saved before abilities existed still plays' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 3]),
                vanilla_unit('b2', 'seat-b', '2,0'),
            ]);
            unset($state['effects'], $state['pending']);
            foreach (['number', 'attacks', 'moveSpent', 'movedAfterAttack', 'abilitiesUsed', 'glareUsed'] as $key) {
                unset($state['turn'][$key]);
            }
            $actions = (new MatchEngine(test_catalog()))->legalActions($state, 'seat-a');
            expect_true(in_array('attackUnit', array_column($actions, 'kind'), true), 'legal actions still list the attack');
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]);
            expect_same(1, $result['state']['units']['b1']['wounds'], 'the attack resolves on plain stats');
        },
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the match-service test FAILS (missing `abilities` key in the projected unit); the compatibility test PASSES already (normalize from Task 1) — that is fine, it pins the behaviour.

- [ ] **Step 3: Implement the projection**

In `GameService::buildProjection()`:

1. First line after `$state = $this->loadState($game['id']);` add `$state = $state === null ? null : MatchEngine::normalize($state);`
2. Legal actions: wrap the `match` result:

```php
        $legalActions = array_map(
            static fn(array $action): array => $action + ['ability' => null],
            $legalActions
        );
```

(`+` keeps an existing `ability`, adds `null` otherwise; key order puts `ability` last, which the JSON consumer ignores.)

3. Replace `'pendingChoice' => null,` with:

```php
            'pendingChoice' => $this->pendingChoiceProjection($state, $viewer['seatId']),
            'pendingChoiceSummary' => $this->pendingChoiceSummary($state),
```

4. Replace the unit mapping closure with a call to a new method: `'units' => $state === null ? [] : array_values(array_map(fn(array $unit): array => $this->unitProjection($state, $unit), self::orderedUnits($state))),` and add:

```php
    private function unitProjection(array $state, array $unit): array
    {
        $id = $unit['unitId'];
        $alive = $unit['hex'] !== null && $state['phase'] === 'active';
        $effective = static fn(string $stat): int => $alive ? Stats::effective($state, $id, $stat) : (int)$unit[$stat];
        return [
            'unitId' => $id,
            'seatId' => $unit['seatId'],
            'isHero' => $unit['isHero'],
            'name' => $unit['name'],
            'image' => $unit['image'],
            'move' => $unit['move'],
            'range' => $unit['range'],
            'power' => $unit['power'],
            'defense' => $unit['defense'],
            'hp' => $unit['hp'],
            'wounds' => $unit['wounds'],
            'hex' => $unit['hex'],
            'activatedThisTurn' => in_array($id, $state['turn']['activated'], true),
            'abilities' => self::abilityCards($unit['abilities'], $state['turn']['abilitiesUsed'][$id] ?? []),
            'effects' => array_map(static fn(array $effect): array => [
                'name' => $effect['name'],
                'stat' => $effect['stat'],
                'amount' => $effect['amount'],
                'expires' => $effect['expires']['at'] === Effects::UNTIL_END_OF_TURN ? 'endOfTurn' : 'nextTurn',
            ], Effects::forUnit($state, $id)),
            'effectiveMove' => $effective('move'),
            'effectiveRange' => $effective('range'),
            'effectivePower' => $effective('power'),
            'effectiveDefense' => $effective('defense'),
        ];
    }

    private static function abilityCards(array $abilities, array $used = []): array
    {
        return array_map(static fn(array $ability): array => [
            'name' => $ability['name'],
            'type' => $ability['type'],
            'description' => $ability['description'],
            'playable' => AbilityRegistry::isPlayable($ability['name']),
            'usedThisTurn' => in_array($ability['name'], $used, true),
        ], $abilities);
    }

    private function pendingChoiceProjection(?array $state, ?string $seatId): ?array
    {
        $choice = $state['pending']['choice'] ?? null;
        if ($choice === null || $choice['seatId'] !== $seatId) {
            return null;
        }
        return [
            'choiceId' => $choice['choiceId'],
            'seatId' => $choice['seatId'],
            'kind' => $choice['kind'],
            'options' => $choice['options'],
            'unitId' => $choice['unitId'],
            'side' => $choice['side'],
            'faces' => $choice['faces'],
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $choice['openedAt'] + BattleRules::CHOICE_TIMEOUT_SECONDS),
        ];
    }

    private function pendingChoiceSummary(?array $state): ?array
    {
        $choice = $state['pending']['choice'] ?? null;
        return $choice === null ? null : [
            'choiceId' => $choice['choiceId'],
            'seatId' => $choice['seatId'],
            'unitId' => $choice['unitId'],
            'keepableAt' => gmdate('Y-m-d\TH:i:s\Z', $choice['openedAt'] + BattleRules::CHOICE_TIMEOUT_SECONDS),
        ];
    }
```

5. In `draftProjection()`, give cards ability entries:

```php
            'availableHeroes' => array_map(
                static fn(array $hero): array => ['abilities' => self::abilityCards($hero['abilities'])] + $hero + ['takenBySeatId' => $takenBy($draft['heroBySeat'], $hero['name'])],
                $catalog->heroes()
            ),
            'availableArmies' => array_map(
                static fn(array $army): array => ['units' => array_map(
                    static fn(array $unit): array => ['abilities' => self::abilityCards($unit['abilities'])] + $unit,
                    $army['units']
                )] + $army + ['takenBySeatId' => $takenBy($draft['armyBySeat'], $army['name'])],
                $catalog->armies()
            ),
```

Add imports to `GameService.php`: `BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry`, `BloodAndBanners\Rules\Vanilla\BattleRules`, `BloodAndBanners\Rules\Vanilla\Effects`, `BloodAndBanners\Rules\Vanilla\MatchEngine`, `BloodAndBanners\Rules\Vanilla\Stats` (skip any already imported).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 5: Add a real-catalog smoke test**

Append to `vanilla_ability_tests()`:

```php
        'every ability in gameData.json is either implemented or explicitly deferred' => static function (): void {
            $deferred = ['Teleport', 'Gust of Wind', 'Knockback', 'Stunning Blow', 'Flying', 'Lava Walker'];
            $catalog = new \BloodAndBanners\Rules\Vanilla\Catalog();
            $names = [];
            foreach ($catalog->heroes() as $hero) {
                $names = array_merge($names, array_column($hero['abilities'], 'name'));
            }
            foreach ($catalog->armies() as $army) {
                foreach ($army['units'] as $unit) {
                    $names = array_merge($names, array_column($unit['abilities'], 'name'));
                }
            }
            foreach (array_unique($names) as $name) {
                expect_same(!in_array($name, $deferred, true), AbilityRegistry::isPlayable($name), "{$name} playability");
            }
        },
```

Run: `php api/tests/run.php` — Expected: PASS. If a name fails, the registry key does not match `gameData.json` exactly (check apostrophes in `Warlord's Command`).

- [ ] **Step 6: Commit**

```bash
git add api/src/Game/GameService.php api/tests
git commit -m "Project abilities, effects, effective stats and pending choices"
```

---

### Task 11: Client contracts

**Files:**
- Modify: `src/BloodAndBanners.Contracts/MatchProjection.cs`, `src/BloodAndBanners.Contracts/GameProjection.cs`
- Modify: `tests/fixtures/game-projection.json`
- Test: `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`

**Interfaces:**
- Produces (C#):
  - `public sealed record AbilityProjection(string Name, string Type, string Description) { public bool Playable { get; init; } public bool UsedThisTurn { get; init; } }`
  - `public sealed record EffectProjection(string Name, string Stat, int Amount, string Expires);`
  - `UnitProjection` init props: `IReadOnlyList<AbilityProjection> Abilities = []`, `IReadOnlyList<EffectProjection> Effects = []`, `int? EffectiveMove`, `int? EffectiveRange`, `int? EffectivePower`, `int? EffectiveDefense`.
  - `UnitCard`, `HeroCard` init prop `IReadOnlyList<AbilityProjection> Abilities = []`.
  - `LegalAction` init prop `string? Ability`.
  - `PendingChoiceProjection` init props `string? UnitId`, `string? Side`, `IReadOnlyList<string> Faces = []`, `DateTimeOffset? ExpiresAt`.
  - `public sealed record PendingChoiceSummary(string ChoiceId, string SeatId, string UnitId, DateTimeOffset KeepableAt);`
  - `GameProjection` init prop `PendingChoiceSummary? PendingChoiceSummary`.

- [ ] **Step 1: Extend the fixture and write the failing test**

In `tests/fixtures/game-projection.json`, add to the single unit object:

```json
"abilities": [{"name": "Shield Wall", "type": "Ability", "description": "Grant one adjacent unit +1 Defense", "playable": true, "usedThisTurn": false}],
"effects": [{"name": "Rune of Warding", "stat": "defense", "amount": 2, "expires": "nextTurn"}],
"effectiveMove": 3, "effectiveRange": 1, "effectivePower": 5, "effectiveDefense": 7
```

add `"ability": null` to the single legal action, and a top-level `"pendingChoiceSummary": {"choiceId": "choice-1", "seatId": "seat-blue", "unitId": "unit-duelist", "keepableAt": "2026-10-11T12:00:45Z"}`.

Append to `GameProjection_fixture_round_trips_with_camel_case_contract` (before the serialization block):

```csharp
        var unit = projection.Units.Single();
        Assert.Equal("Shield Wall", unit.Abilities.Single().Name);
        Assert.True(unit.Abilities.Single().Playable);
        Assert.Equal(2, unit.Effects.Single().Amount);
        Assert.Equal(7, unit.EffectiveDefense);
        Assert.Null(projection.LegalActions.Single().Ability);
        Assert.Equal("choice-1", projection.PendingChoiceSummary?.ChoiceId);
        Assert.Equal(new DateTimeOffset(2026, 10, 11, 12, 0, 45, TimeSpan.Zero), projection.PendingChoiceSummary?.KeepableAt);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests`
Expected: build error — `UnitProjection` has no `Abilities`.

- [ ] **Step 3: Implement the records**

`MatchProjection.cs` — add the new records at the top of the namespace and init properties:

```csharp
public sealed record AbilityProjection(string Name, string Type, string Description)
{
    public bool Playable { get; init; }
    public bool UsedThisTurn { get; init; }
}

public sealed record EffectProjection(string Name, string Stat, int Amount, string Expires);
```

`UnitCard(...)` and `HeroCard(...)` each get a body:

```csharp
{
    public IReadOnlyList<AbilityProjection> Abilities { get; init; } = [];
}
```

`UnitProjection(...)` gets:

```csharp
{
    public IReadOnlyList<AbilityProjection> Abilities { get; init; } = [];
    public IReadOnlyList<EffectProjection> Effects { get; init; } = [];
    public int? EffectiveMove { get; init; }
    public int? EffectiveRange { get; init; }
    public int? EffectivePower { get; init; }
    public int? EffectiveDefense { get; init; }
}
```

`GameProjection.cs`:

```csharp
public sealed record PendingChoiceProjection(
    string ChoiceId,
    string SeatId,
    string Kind,
    IReadOnlyList<string> Options)
{
    public string? UnitId { get; init; }
    public string? Side { get; init; }
    public IReadOnlyList<string> Faces { get; init; } = [];
    public DateTimeOffset? ExpiresAt { get; init; }
}

public sealed record PendingChoiceSummary(string ChoiceId, string SeatId, string UnitId, DateTimeOffset KeepableAt);

public sealed record LegalAction(
    string Kind,
    string? UnitId,
    IReadOnlyList<string> TargetHexes)
{
    public string? Ability { get; init; }
}
```

and in `GameProjection`'s body add `public PendingChoiceSummary? PendingChoiceSummary { get; init; }`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test`
Expected: all tests PASS (UI tests unaffected — new properties have defaults).

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.Contracts tests/fixtures/game-projection.json tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs
git commit -m "Add ability, effect and choice fields to the client contracts"
```

---

### Task 12: Ability lists and effective stats on unit and draft cards

**Files:**
- Create: `src/BloodAndBanners.UI/Components/AbilityList.razor`, `AbilityList.razor.css`
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Modify: `src/BloodAndBanners.UI/Components/DraftTable.razor`, `DraftTable.razor.css`
- Test: `tests/BloodAndBanners.UI.Tests/AbilityListTests.cs` (create), `tests/BloodAndBanners.UI.Tests/DraftTableTests.cs`, `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`

**Interfaces:**
- Consumes: contracts from Task 11.
- Produces: `<AbilityList Abilities="..." Actions="..." ActiveAbility="..." OnChoose="..." />` — `Actions` are the selected unit's `specialAttack`/`useAbility` legal actions; `OnChoose` is `EventCallback<LegalAction>`; `ActiveAbility` is the ability name currently in targeting mode (or null). With no `Actions` it renders read-only (for draft cards inside `<button>`s it renders no buttons).
  - Markup hooks used by later tasks/tests: `li[data-ability='<name>']`, `.ability-type`, `button[data-use-ability='<name>']` with `aria-pressed`, `.ability-used`, `.ability-deferred`.
  - `GameTable` unit card: `[data-testid=selected-unit] [data-stat='defense']` text like `Defense 3 → 4`, `title` listing effect sources.

- [ ] **Step 1: Write the failing tests**

Create `tests/BloodAndBanners.UI.Tests/AbilityListTests.cs`:

```csharp
namespace BloodAndBanners.UI.Tests;

using BloodAndBanners.Contracts;
using BloodAndBanners.UI.Components;
using Bunit;

public sealed class AbilityListTests : BunitContext
{
    private static readonly AbilityProjection Fireball = new("Fireball", "Attack", "Power 4, Range 4, splash") { Playable = true };
    private static readonly AbilityProjection Evasion = new("Evasion", "Essence", "Roll d20") { Playable = true };
    private static readonly AbilityProjection Teleport = new("Teleport", "Attack", "Move a friendly unit") { Playable = false };
    private static readonly AbilityProjection Runes = new("Rune of Warding", "Ability", "+2 Defense") { Playable = true, UsedThisTurn = true };

    [Fact]
    public void Lists_each_ability_with_its_type_and_state()
    {
        var cut = Render<AbilityList>(parameters => parameters.Add(component => component.Abilities, [Fireball, Evasion, Teleport, Runes]));

        Assert.Equal("Attack", cut.Find("li[data-ability='Fireball'] .ability-type").TextContent);
        Assert.Contains("Power 4, Range 4, splash", cut.Find("li[data-ability='Fireball']").TextContent);
        Assert.Contains("Not yet in the digital game", cut.Find("li[data-ability='Teleport'] .ability-deferred").TextContent);
        Assert.Contains("Used", cut.Find("li[data-ability='Rune of Warding'] .ability-used").TextContent);
        Assert.Empty(cut.FindAll("button"));
    }

    [Fact]
    public void Usable_actions_become_buttons_that_report_the_chosen_action()
    {
        LegalAction? chosen = null;
        var action = new LegalAction("specialAttack", "u1", ["2,0"]) { Ability = "Fireball" };
        var cut = Render<AbilityList>(parameters => parameters
            .Add(component => component.Abilities, [Fireball, Evasion])
            .Add(component => component.Actions, [action])
            .Add(component => component.ActiveAbility, "Fireball")
            .Add(component => component.OnChoose, (LegalAction picked) => chosen = picked));

        var button = cut.Find("button[data-use-ability='Fireball']");
        Assert.Equal("true", button.GetAttribute("aria-pressed"));
        Assert.Empty(cut.FindAll("button[data-use-ability='Evasion']"));
        button.Click();
        Assert.Same(action, chosen);
    }
}
```

In `GameTableTests.cs`, add:

```csharp
    [Fact]
    public void The_unit_card_shows_effective_stats_and_abilities()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [
                projection.Units[0] with
                {
                    EffectiveDefense = 4,
                    Effects = [new EffectProjection("Shield Wall", "defense", 1, "nextTurn")],
                    Abilities = [new AbilityProjection("Evasion", "Essence", "Roll d20") { Playable = true }],
                },
                projection.Units[1],
                projection.Units[2],
            ],
        };

        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        var defense = cut.Find("[data-testid=selected-unit] [data-stat='defense']");
        Assert.Equal("Defense 2 → 4", defense.TextContent.Trim());
        Assert.Contains("Shield Wall +1 until your next turn", defense.GetAttribute("title"));
        Assert.NotNull(cut.Find("[data-testid=selected-unit] li[data-ability='Evasion']"));
    }
```

In `DraftTableTests.cs`, add:

```csharp
    [Fact]
    public void Draft_cards_list_hero_and_unit_abilities()
    {
        var projection = DraftFixtures.Projection(viewerSeat: "seat-red", picking: "seat-red");
        var draft = projection.Draft! with
        {
            AvailableHeroes = [projection.Draft!.AvailableHeroes[0] with { Abilities = [new AbilityProjection("Shield Wall", "Ability", "+1 Defense") { Playable = true }] }],
        };

        var cut = Render<DraftTable>(parameters => parameters.Add(component => component.Projection, projection with { Draft = draft }));

        Assert.Contains("Shield Wall", cut.Find("button[data-hero='Thorgrim Shattershield'] li[data-ability='Shield Wall']").TextContent);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: build error — `AbilityList` does not exist.

- [ ] **Step 3: Implement AbilityList**

`src/BloodAndBanners.UI/Components/AbilityList.razor`:

```razor
@namespace BloodAndBanners.UI.Components
@using BloodAndBanners.Contracts

@if (Abilities.Count > 0)
{
    <ul class="ability-list">
        @foreach (var ability in Abilities)
        {
            var action = Actions.FirstOrDefault(candidate => candidate.Ability == ability.Name);
            <li data-ability="@ability.Name" class="@(ability.Playable ? null : "deferred")">
                <span class="ability-heading">
                    <span class="ability-type type-@ability.Type.ToLowerInvariant()">@ability.Type</span>
                    @if (action is not null)
                    {
                        <button type="button"
                                class="ability-button"
                                data-use-ability="@ability.Name"
                                aria-pressed="@(ActiveAbility == ability.Name ? "true" : "false")"
                                disabled="@IsBusy"
                                @onclick="() => OnChoose.InvokeAsync(action)">@ability.Name</button>
                    }
                    else
                    {
                        <strong>@ability.Name</strong>
                    }
                    @if (ability.UsedThisTurn)
                    {
                        <span class="ability-used">Used</span>
                    }
                    @if (!ability.Playable)
                    {
                        <span class="ability-deferred">Not yet in the digital game</span>
                    }
                </span>
                <span class="ability-text">@ability.Description</span>
            </li>
        }
    </ul>
}

@code {
    [Parameter, EditorRequired]
    public IReadOnlyList<AbilityProjection> Abilities { get; set; } = [];

    [Parameter]
    public IReadOnlyList<LegalAction> Actions { get; set; } = [];

    [Parameter]
    public string? ActiveAbility { get; set; }

    [Parameter]
    public bool IsBusy { get; set; }

    [Parameter]
    public EventCallback<LegalAction> OnChoose { get; set; }
}
```

`AbilityList.razor.css`:

```css
.ability-list { margin: .5rem 0 0; padding: 0; list-style: none; display: grid; gap: .45rem; }
.ability-list li { display: grid; gap: .15rem; }
.ability-heading { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; }
.ability-type { padding: 0 .3rem; font-size: .66rem; letter-spacing: .05em; text-transform: uppercase; border-radius: .2rem; color: #1f201d; background: #c9c2b2; }
.ability-type.type-attack { background: #e2a29a; }
.ability-type.type-ability { background: #f0cf7a; }
.ability-type.type-essence { background: #9cc3df; }
.ability-text { font-size: .82rem; line-height: 1.35; color: #b9b2a2; }
.ability-button { padding: .2rem .55rem; color: #f8f0dc; background: var(--oxblood, #681e24); border: 1px solid #b18b48; border-radius: .2rem; font-weight: 600; }
.ability-button[aria-pressed="true"] { color: #1f201d; background: #ffd700; border-color: #ffd700; }
.ability-used, .ability-deferred { font-size: .7rem; color: #8b8578; }
.deferred .ability-text { color: #8b8578; }
```

- [ ] **Step 4: Show effective stats and abilities on the GameTable unit card**

Replace the `<article class="unit-card" ...>` block in `GameTable.razor` with:

```razor
                    <article class="unit-card" data-testid="selected-unit">
                        <h3>@selected.Name</h3>
                        <p class="unit-stats">
                            @foreach (var (stat, label, baseValue, effective) in StatRows(selected))
                            {
                                <span data-stat="@stat" title="@EffectSources(selected, stat)" class="@(effective > baseValue ? "raised" : effective < baseValue ? "lowered" : null)">
                                    @label @baseValue@(effective != baseValue ? $" → {effective}" : null)
                                </span>
                            }
                        </p>
                        <p>Wounds @selected.Wounds / @selected.Hp</p>
                        <AbilityList Abilities="selected.Abilities"
                                     Actions="AbilityActions(selected.UnitId)"
                                     ActiveAbility="@_targeting?.Ability"
                                     IsBusy="IsBusy"
                                     OnChoose="StartTargeting" />
                    </article>
```

Add to the `@code` block:

```csharp
    private sealed record Targeting(string Kind, string Ability, IReadOnlyList<string> TargetHexes);

    private Targeting? _targeting;

    private static IEnumerable<(string Stat, string Label, int Base, int Effective)> StatRows(UnitProjection unit) =>
    [
        ("move", "Move", unit.Move, unit.EffectiveMove ?? unit.Move),
        ("range", "Range", unit.Range, unit.EffectiveRange ?? unit.Range),
        ("power", "Power", unit.Power, unit.EffectivePower ?? unit.Power),
        ("defense", "Defense", unit.Defense, unit.EffectiveDefense ?? unit.Defense),
    ];

    private static string? EffectSources(UnitProjection unit, string stat)
    {
        var sources = unit.Effects
            .Where(effect => effect.Stat == stat)
            .Select(effect => $"{effect.Name} {(effect.Amount > 0 ? "+" : "")}{effect.Amount} {(effect.Expires == "endOfTurn" ? "until end of turn" : "until your next turn")}")
            .ToList();
        return sources.Count == 0 ? null : string.Join("; ", sources);
    }

    private IReadOnlyList<LegalAction> AbilityActions(string unitId) => Projection.LegalActions
        .Where(action => action.UnitId == unitId && (action.Kind is "specialAttack" or "useAbility"))
        .ToList();

    private void StartTargeting(LegalAction action)
    {
        _targeting = _targeting?.Ability == action.Ability ? null : new Targeting(action.Kind, action.Ability!, action.TargetHexes);
    }
```

Note on the stat label test: the span text must be exactly `Defense 2 → 4` after trimming — keep the Razor on one line as shown. The "until your next turn" wording is from the effect's own seat's perspective; the card shows it unchanged for both players (matches the spec's "Shield Wall +1 until your next turn" example).

Make units with only ability actions selectable — change `Selectable()`'s kind test to `(action.Kind is "moveUnit" or "attackUnit" or "specialAttack" or "useAbility")`, so a spent hero with an unused Shield Wall, or a Sorcerer whose only option is Chain Lightning, can still be picked.

Add to `GameTable.razor.css`:

```css
.unit-stats { display: flex; flex-wrap: wrap; gap: .2rem .7rem; }
.unit-stats .raised { color: #a6d98f; }
.unit-stats .lowered { color: #e2a29a; }
```

- [ ] **Step 5: Show abilities on draft cards**

In `DraftTable.razor`, inside the hero `<button>` after the `stat-line` span add `<AbilityList Abilities="hero.Abilities" />`; in the army unit `<li>` after the `<small>` add `<AbilityList Abilities="unit.Abilities" />`. Add to `DraftTable.razor.css`:

```css
.draft-card ::deep .ability-list { text-align: left; }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `dotnet test`
Expected: all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Show abilities and effective stats on unit and draft cards"
```

---

### Task 13: Targeting mode, Fireball splash preview and effect markers

**Files:**
- Create: `src/BloodAndBanners.UI/Services/HexMath.cs`
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Test: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`, `tests/BloodAndBanners.UI.Tests/BoardLayoutTests.cs`

**Interfaces:**
- Consumes: `_targeting` / `StartTargeting` (Task 12).
- Produces:
  - `HexMath.Distance(string a, string b): int`, `HexMath.Neighbours(string key): IReadOnlyList<string>` (axial `"q,r"` keys).
  - Board classes: `.hex.ability-target` (enemy target), `.hex.ally-target` (ally target), `.hex.splash` (Fireball preview). Banner `[data-testid=targeting-banner]` with `button[data-action=cancelTargeting]`.
  - Commands: `specialAttack {unitId, ability, targetUnitIds: [id]}`, `useAbility {unitId, ability, targetUnitId}`.
  - Token markers: `.effect-marker.buff` (▲) / `.effect-marker.debuff` (▼).

- [ ] **Step 1: Write the failing tests**

`BoardLayoutTests.cs`, add:

```csharp
    [Fact]
    public void Hex_math_measures_axial_distance_and_lists_neighbours()
    {
        Assert.Equal(3, HexMath.Distance("0,0", "3,0"));
        Assert.Equal(2, HexMath.Distance("0,0", "1,1"));
        Assert.Equal(["1,0", "1,-1", "0,-1", "-1,0", "-1,1", "0,1"], HexMath.Neighbours("0,0"));
    }
```

`GameTableTests.cs`, add (extend `BattleFixtures` units by hand in the test):

```csharp
    [Fact]
    public void Choosing_a_special_attack_targets_only_its_hexes_and_sends_it()
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Fireball", "Attack", "Power 4") { Playable = true }] }, projection.Units[1], projection.Units[2]],
            LegalActions = [.. projection.LegalActions, new LegalAction("specialAttack", "s1-u3", ["3,4"]) { Ability = "Fireball" }],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Fireball']").Click();

        Assert.Contains("Fireball", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("ability-target", cut.Find("[data-hex='3,4']").ClassList);
        Assert.DoesNotContain("reachable", cut.Find("[data-hex='2,4']").ClassList);

        cut.Find("[data-hex='3,4']").MouseEnter();
        Assert.Contains("splash", cut.Find("[data-hex='2,4']").ClassList);
        Assert.Contains("splash", cut.Find("[data-hex='4,4']").ClassList);

        cut.Find("[data-hex='3,4']").Click();

        var command = sent.Single();
        Assert.Equal("specialAttack", command.Kind);
        var payload = JsonSerializer.SerializeToElement(command.Payload);
        Assert.Equal("Fireball", payload.GetProperty("ability").GetString());
        Assert.Equal("s2-u1", payload.GetProperty("targetUnitIds")[0].GetString());
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
    }

    [Fact]
    public void Targeting_can_be_cancelled_and_abilities_send_a_single_target()
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Rune of Distraction", "Ability", "-2 Defense") { Playable = true }] }, projection.Units[1], projection.Units[2]],
            LegalActions = [.. projection.LegalActions, new LegalAction("useAbility", "s1-u3", ["3,4"]) { Ability = "Rune of Distraction" }],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Rune of Distraction']").Click();
        cut.Find("button[data-action=cancelTargeting]").Click();
        Assert.Empty(cut.FindAll("[data-testid=targeting-banner]"));
        Assert.Contains("attackable", cut.Find("[data-hex='3,4']").ClassList);

        cut.Find("button[data-use-ability='Rune of Distraction']").Click();
        cut.Find("[data-hex='3,4']").Click();
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("useAbility", sent.Single().Kind);
        Assert.Equal("s2-u1", payload.GetProperty("targetUnitId").GetString());
    }

    [Fact]
    public void Tokens_mark_buffs_and_debuffs()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [
                projection.Units[0] with { Effects = [new EffectProjection("Shield Wall", "defense", 1, "nextTurn")] },
                projection.Units[1] with { Effects = [new EffectProjection("Rune of Distraction", "defense", -2, "nextTurn")] },
                projection.Units[2],
            ],
        };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));

        Assert.Single(cut.FindAll("[data-hex='1,4'] .effect-marker.buff"));
        Assert.Single(cut.FindAll("[data-hex='3,4'] .effect-marker.debuff"));
        Assert.Contains("Shield Wall", cut.Find("[data-hex='1,4']").GetAttribute("aria-label"));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: build error (`HexMath` missing) then failures.

- [ ] **Step 3: Implement HexMath**

```csharp
namespace BloodAndBanners.UI.Services;

/// <summary>Axial hex arithmetic on "q,r" keys, matching the server's Hex class.</summary>
public static class HexMath
{
    private static readonly (int Q, int R)[] Directions = [(1, 0), (1, -1), (0, -1), (-1, 0), (-1, 1), (0, 1)];

    public static int Distance(string from, string to)
    {
        var (q1, r1) = Parse(from);
        var (q2, r2) = Parse(to);
        var dq = q2 - q1;
        var dr = r2 - r1;
        return (Math.Abs(dq) + Math.Abs(dr) + Math.Abs(dq + dr)) / 2;
    }

    public static IReadOnlyList<string> Neighbours(string key)
    {
        var (q, r) = Parse(key);
        return Directions.Select(d => $"{q + d.Q},{r + d.R}").ToList();
    }

    private static (int Q, int R) Parse(string key)
    {
        var parts = key.Split(',');
        return (int.Parse(parts[0], System.Globalization.CultureInfo.InvariantCulture), int.Parse(parts[1], System.Globalization.CultureInfo.InvariantCulture));
    }
}
```

- [ ] **Step 4: Implement targeting in GameTable**

Add the banner at the top of the battlefield panel, right after the `board-controls` div:

```razor
        @if (_targeting is { } aiming)
        {
            <div class="targeting-banner" data-testid="targeting-banner" role="status">
                <span>@aiming.Ability: choose a target</span>
                <button type="button" data-action="cancelTargeting" @onclick="CancelTargeting">Cancel</button>
            </div>
        }
```

Give the battlefield panel an Escape handler: change `<section class="battlefield-panel" aria-label="Game board">` to `<section class="battlefield-panel" aria-label="Game board" @onkeydown="OnBoardKey">`.

Track the hovered hex: extend the hex `<g>` handlers so `@onmouseenter` and `@onfocus` also set `_hoveredHex = key` and `@onmouseleave`/`@onblur` clear it:

```razor
                   @onmouseenter="() => Hover(hex)"
                   @onmouseleave="Unhover"
                   @onfocus="() => Hover(hex)"
                   @onblur="Unhover"
```

with

```csharp
    private string? _hoveredHex;

    private void Hover(HexProjection hex)
    {
        _hoveredUnitId = hex.OccupantId;
        _hoveredHex = HexKey(hex);
    }

    private void Unhover()
    {
        _hoveredUnitId = null;
        _hoveredHex = null;
    }

    private void CancelTargeting() => _targeting = null;

    private void OnBoardKey(KeyboardEventArgs args)
    {
        if (args.Key == "Escape")
        {
            _targeting = null;
        }
    }

    private bool TargetsAlly(string key) =>
        UnitById(Projection.Board.Hexes.FirstOrDefault(hex => HexKey(hex) == key)?.OccupantId)?.SeatId == Projection.Viewer.SeatId;

    private IReadOnlyList<string> SplashHexes =>
        _targeting?.Ability == "Fireball" && _hoveredHex is { } hovered && _targeting.TargetHexes.Contains(hovered)
            ? HexMath.Neighbours(hovered)
            : [];
```

Replace `HexState()`:

```csharp
    private string? HexState(string key)
    {
        if (key == SelectedHex)
        {
            return "selected";
        }
        if (_targeting is { } aiming)
        {
            var state = aiming.TargetHexes.Contains(key) ? (TargetsAlly(key) ? "ally-target" : "ability-target") : null;
            return SplashHexes.Contains(key) ? $"{state} splash".Trim() : state;
        }
        if (Targets("attackUnit").Contains(key))
        {
            return "attackable";
        }
        if (Targets("moveUnit").Contains(key))
        {
            return "reachable";
        }
        return FocusHexes.Contains(key) ? "focus" : null;
    }
```

At the start of `SelectHex()` (after the busy/command guard) handle targeting:

```csharp
        if (_targeting is { } aiming && _selectedUnitId is { } caster)
        {
            var aimed = HexKey(hex);
            if (aiming.TargetHexes.Contains(aimed) && hex.OccupantId is { } targetId)
            {
                _targeting = null;
                if (aiming.Kind == "specialAttack")
                {
                    await Send("specialAttack", new { unitId = caster, ability = aiming.Ability, targetUnitIds = new[] { targetId } });
                }
                else
                {
                    await Send("useAbility", new { unitId = caster, ability = aiming.Ability, targetUnitId = targetId });
                }
            }
            return;
        }
```

(the existing `var key = HexKey(hex);` further down stays). Clear `_targeting` in `OnParametersSet()` when the selected unit changes or no longer has that action:

```csharp
        if (_targeting is { } aiming && !AbilityActions(_selectedUnitId ?? string.Empty).Any(action => action.Ability == aiming.Ability))
        {
            _targeting = null;
        }
```

Also leave targeting mode when a new error arrives (but not on every render while the same error is still shown). Add a field `private string? _lastError;` and at the end of `OnParametersSet()`:

```csharp
        if (ErrorMessage != _lastError)
        {
            if (!string.IsNullOrEmpty(ErrorMessage))
            {
                _targeting = null;
            }
            _lastError = ErrorMessage;
        }
```

- [ ] **Step 5: Effect markers on tokens and in labels**

Inside the unit-token `<g>`, after the hero flag block:

```razor
                            @if (unit is not null && unit.Effects.Any(effect => effect.Amount > 0))
                            {
                                <!text class="effect-marker buff" x="@Num(tile.X + radius * .85)" y="@Num(tokenY + radius * .55)">▲</!text>
                            }
                            @if (unit is not null && unit.Effects.Any(effect => effect.Amount < 0))
                            {
                                <!text class="effect-marker debuff" x="@Num(tile.X - radius * .85)" y="@Num(tokenY + radius * .55)">▼</!text>
                            }
```

Extend `HexLabel()` — append effect names:

```csharp
        (unit is null || unit.Effects.Count == 0 ? string.Empty : ", " + string.Join(", ", unit.Effects.Select(effect => $"{effect.Name} {(effect.Amount > 0 ? "+" : "")}{effect.Amount} {effect.Stat}"))) +
```

(insert before the activation-state `switch`).

- [ ] **Step 6: Styles**

Append to `GameTable.razor.css`:

```css
.targeting-banner { position: sticky; z-index: 3; top: .75rem; display: inline-flex; align-items: center; gap: .6rem; margin: .75rem; padding: .4rem .7rem; color: #1f201d; background: #ffd700; border-radius: .2rem; font-weight: 600; }
.targeting-banner button { min-height: 2rem; padding: .2rem .6rem; color: #f6f0df; background: #373830; border: 0; }
.hex.ability-target .hex-outline { fill: rgba(255, 107, 90, .3); stroke: #ff6b5a; stroke-width: 3.5; }
.hex.ally-target .hex-outline { fill: rgba(240, 207, 122, .3); stroke: #f0cf7a; stroke-width: 3.5; }
.hex.splash .hex-outline { fill: rgba(255, 140, 60, .35); stroke: #ff8c3c; stroke-dasharray: 5 3; stroke-width: 2.5; }
.effect-marker { font: 700 11px Arial, sans-serif; text-anchor: middle; dominant-baseline: central; paint-order: stroke; stroke: #000; stroke-width: 2; }
.effect-marker.buff { fill: #a6d98f; }
.effect-marker.debuff { fill: #ff8a7a; }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `dotnet test`
Expected: all tests PASS.

- [ ] **Step 8: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Add targeting mode, Fireball splash preview and effect markers"
```

---

### Task 14: Chain Lightning picking

**Files:**
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Test: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`

**Interfaces:**
- Consumes: `_targeting`, `HexMath.Distance`.
- Produces: `_chain` (ordered unit ids); markers `.chain-mark` with text `1`–`3` on picked hexes; `button[data-action=strikeChain]` sends `specialAttack {unitId, ability: "Chain Lightning", targetUnitIds: [...]}`. Constant `ChainReach = 5`, max 3 targets.

- [ ] **Step 1: Write the failing test**

Add a fixture-local projection with a sorcerer at `0,0` and enemies at `2,0`, `4,0`, `5,0`, `7,0`:

```csharp
    [Fact]
    public void Chain_lightning_picks_targets_in_order_within_the_remaining_reach()
    {
        var sent = new List<GameCommandRequest>();
        var hexes = Enumerable.Range(0, 8).Select(q => new HexProjection(q, 0, "plains", "grass", 0, false,
            q switch { 0 => "s1-u5", 2 => "s2-u1", 4 => "s2-u2", 5 => "s2-u3", 7 => "s2-u4", _ => null })).ToList();
        UnitProjection Enemy(string id, string hex) => new(id, "seat-blue", false, id, "", 2, 1, 3, 2, 5, 0, hex, false);
        var projection = BattleFixtures.Projection() with
        {
            Board = new BoardProjection(8, 1, hexes),
            Units = [
                new UnitProjection("s1-u5", "seat-red", false, "Sorcerer", "", 3, 5, 3, 2, 3, 0, "0,0", false)
                {
                    Abilities = [new AbilityProjection("Chain Lightning", "Attack", "Power 3, up to 3 units") { Playable = true }],
                },
                Enemy("s2-u1", "2,0"), Enemy("s2-u2", "4,0"), Enemy("s2-u3", "5,0"), Enemy("s2-u4", "7,0"),
            ],
            LegalActions = [new LegalAction("specialAttack", "s1-u5", ["2,0", "4,0", "5,0"]) { Ability = "Chain Lightning" }, new LegalAction("endTurn", null, [])],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='0,0']").Click();
        cut.Find("button[data-use-ability='Chain Lightning']").Click();
        cut.Find("[data-hex='2,0']").Click();

        Assert.Equal("1", cut.Find("[data-hex='2,0'] .chain-mark").TextContent);
        Assert.Contains("ability-target", cut.Find("[data-hex='4,0']").ClassList);
        Assert.Contains("ability-target", cut.Find("[data-hex='5,0']").ClassList);
        Assert.DoesNotContain("ability-target", cut.Find("[data-hex='7,0']").ClassList);

        cut.Find("[data-hex='4,0']").Click();
        Assert.Contains("ability-target", cut.Find("[data-hex='5,0']").ClassList);
        Assert.Empty(sent);

        cut.Find("button[data-action=strikeChain]").Click();
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal(["s2-u1", "s2-u2"], payload.GetProperty("targetUnitIds").EnumerateArray().Select(id => id.GetString()).ToArray());
    }
```

(`2,0` costs 2 of the 5; from there `4,0` is 2 more (total 4), `5,0` is 3 (total 5), `7,0` is 5 (too far). After picking `4,0`, `5,0` is 1 more (total 5) — still valid.)

- [ ] **Step 2: Run test to verify it fails**

Run: `dotnet test tests/BloodAndBanners.UI.Tests --filter Chain_lightning`
Expected: FAIL — clicking `2,0` sends the attack immediately.

- [ ] **Step 3: Implement**

In `@code`:

```csharp
    private const int ChainReach = 5;
    private const int ChainMaxTargets = 3;
    private readonly List<string> _chain = [];

    private bool Chaining => _targeting?.Ability == "Chain Lightning";

    /// <summary>Hexes the next Chain Lightning link may hit: first hop from the server's list, later hops by remaining reach.</summary>
    private IReadOnlyList<string> ChainCandidates()
    {
        if (_targeting is null || _selectedUnitId is null || _chain.Count >= ChainMaxTargets)
        {
            return [];
        }
        if (_chain.Count == 0)
        {
            return _targeting.TargetHexes;
        }
        var caster = UnitById(_selectedUnitId)!;
        var path = new[] { caster.Hex! }.Concat(_chain.Select(id => UnitById(id)!.Hex!)).ToList();
        var spent = path.Zip(path.Skip(1), HexMath.Distance).Sum();
        var last = path[^1];
        return Projection.Units
            .Where(unit => unit.Hex is not null && unit.SeatId != caster.SeatId && !_chain.Contains(unit.UnitId))
            .Where(unit => spent + HexMath.Distance(last, unit.Hex!) <= ChainReach)
            .Select(unit => unit.Hex!)
            .ToList();
    }

    private async Task StrikeChain()
    {
        if (_selectedUnitId is { } caster && _chain.Count > 0)
        {
            var targets = _chain.ToArray();
            _chain.Clear();
            _targeting = null;
            await Send("specialAttack", new { unitId = caster, ability = "Chain Lightning", targetUnitIds = targets });
        }
    }
```

In `SelectHex()`'s targeting block, before the single-target send, add:

```csharp
            if (Chaining)
            {
                if (ChainCandidates().Contains(aimed) && hex.OccupantId is { } link)
                {
                    _chain.Add(link);
                }
                return;
            }
```

In `HexState()`'s targeting branch, use `Chaining ? ChainCandidates() : aiming.TargetHexes` as the target list. Clear `_chain` whenever `_targeting` changes (in `StartTargeting`, `CancelTargeting`, `OnBoardKey`, and where `OnParametersSet` resets `_targeting`).

In the targeting banner, when chaining show the count and the Strike button:

```razor
                @if (Chaining)
                {
                    <span>@_chain.Count / @ChainMaxTargets picked</span>
                    <button type="button" data-action="strikeChain" disabled="@(_chain.Count == 0 || IsBusy)" @onclick="StrikeChain">Strike</button>
                }
```

On the board, inside each hex `<g>` after the elevation label:

```razor
                    @if (hex.OccupantId is { } occupant && _chain.IndexOf(occupant) is >= 0 and var link)
                    {
                        <!text class="chain-mark" x="@Num(tile.X + BoardLayout.Size * .42)" y="@Num(tile.Y - BoardLayout.Size * .5)">@(link + 1)</!text>
                    }
```

CSS:

```css
.chain-mark { fill: #fff3aa; font: 700 14px Georgia, serif; text-anchor: middle; dominant-baseline: middle; paint-order: stroke; stroke: #1f201d; stroke-width: 3; pointer-events: none; }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Pick Chain Lightning targets in order on the board"
```

---

### Task 15: Moonlight Glare prompt, waiting view and polling

**Files:**
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Modify: `src/BloodAndBanners.UI/Components/GameExperience.razor`
- Test: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`, `tests/BloodAndBanners.UI.Tests/UnitTest1.cs`

**Interfaces:**
- Consumes: `PendingChoiceProjection` (`Faces`, `Side`, `Options`), `PendingChoiceSummary`, legal action `resolveChoice`.
- Produces: choice buttons send `resolveChoice {choiceId, option}` through `OnCommand` (the unused `OnChoice` parameter is removed). Markup: `[data-testid=glare-prompt]`, `button[data-choice='reroll:1']`, `button[data-choice='keep']`, `[data-testid=choice-waiting]`, `button[data-action=keepForThem]`.

- [ ] **Step 1: Write the failing tests**

```csharp
    [Fact]
    public void The_glare_owner_picks_a_die_to_reroll_or_keeps()
    {
        var sent = new List<GameCommandRequest>();
        var projection = BattleFixtures.Projection() with
        {
            PendingChoice = new PendingChoiceProjection("choice-1", "seat-red", "moonlightGlare", ["reroll:1", "keep"])
            {
                UnitId = "s1-u3", Side = "defense", Faces = ["shield", "blank"],
            },
            PendingChoiceSummary = new PendingChoiceSummary("choice-1", "seat-red", "s1-u3", DateTimeOffset.UtcNow.AddSeconds(45)),
            LegalActions = [new LegalAction("resolveChoice", "s1-u3", [])],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, projection)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        var prompt = cut.Find("[data-testid=glare-prompt]");
        Assert.Contains("Moonlight Glare", prompt.TextContent);
        Assert.Contains("defense", prompt.TextContent);
        Assert.True(cut.Find("button[data-choice='reroll:0']").HasAttribute("disabled"));

        cut.Find("button[data-choice='reroll:1']").Click();

        Assert.Equal("resolveChoice", sent.Single().Kind);
        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("choice-1", payload.GetProperty("choiceId").GetString());
        Assert.Equal("reroll:1", payload.GetProperty("option").GetString());
    }

    [Fact]
    public void The_other_player_waits_and_may_keep_for_them_once_allowed()
    {
        var sent = new List<GameCommandRequest>();
        var waiting = BattleFixtures.Projection() with
        {
            PendingChoiceSummary = new PendingChoiceSummary("choice-1", "seat-blue", "s2-u1", DateTimeOffset.UtcNow.AddSeconds(30)),
            LegalActions = [],
        };
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, waiting)
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        Assert.Contains("Waiting for your opponent's decision", cut.Find("[data-testid=choice-waiting]").TextContent);
        Assert.Empty(cut.FindAll("button[data-action=keepForThem]"));
        Assert.Empty(cut.FindAll("button[data-action=endTurn]"));

        cut.Render(parameters => parameters.Add(component => component.Projection, waiting with { LegalActions = [new LegalAction("resolveChoice", "s2-u1", [])] }));
        cut.Find("button[data-action=keepForThem]").Click();

        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("keep", payload.GetProperty("option").GetString());
        Assert.Equal("choice-1", payload.GetProperty("choiceId").GetString());
    }
```

The repo uses bUnit 2.11, where `cut.Render(parameters => ...)` re-renders with new parameters (it replaced v1's `SetParametersAndRender`).

In `UnitTest1.cs` `Pending_choice_replaces_normal_actions_with_server_owned_options`, the generic prompt stays — no change needed except it must still pass.

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: the two new tests FAIL.

- [ ] **Step 3: Implement the prompt and waiting view**

In `GameTable.razor`, replace the rail's first branch:

```razor
        @if (Projection.PendingChoice is { Kind: "moonlightGlare" } glare && glare.SeatId == Projection.Viewer.SeatId)
        {
            <section class="choice-prompt" role="dialog" aria-modal="false" aria-labelledby="choice-title" data-testid="glare-prompt">
                <h2 id="choice-title">Moonlight Glare</h2>
                <p>@(UnitById(glare.UnitId)?.Name ?? "Your Duelist") may re-roll one @glare.Side die.</p>
                <div class="choice-dice">
                    @for (var index = 0; index < glare.Faces.Count; index++)
                    {
                        var option = $"reroll:{index}";
                        var face = glare.Faces[index];
                        <button type="button"
                                class="die face-@face"
                                data-choice="@option"
                                aria-label="Re-roll @glare.Side die @(index + 1) (@face)"
                                disabled="@(!glare.Options.Contains(option) || IsBusy)"
                                @onclick="() => Resolve(glare.ChoiceId, option)">@FaceSymbol(face)</button>
                    }
                </div>
                <button type="button" data-choice="keep" disabled="@IsBusy" @onclick="() => Resolve(glare.ChoiceId, "keep")">Keep the roll</button>
            </section>
        }
        else if (Projection.PendingChoice is not null && Projection.PendingChoice.SeatId == Projection.Viewer.SeatId)
        {
            @* existing generic prompt, with its buttons calling Resolve(Projection.PendingChoice.ChoiceId, option) *@
        }
        else if (Projection.PendingChoiceSummary is { } waitingOn && Projection.Viewer.CanCommand)
        {
            <section class="choice-waiting" data-testid="choice-waiting" role="status">
                <h2>Moonlight Glare</h2>
                <p>Waiting for your opponent's decision…</p>
                @if (Projection.LegalActions.Any(action => action.Kind == "resolveChoice"))
                {
                    <button type="button" data-action="keepForThem" disabled="@IsBusy" @onclick="() => Resolve(waitingOn.ChoiceId, "keep")">Keep the roll for them</button>
                }
            </section>
        }
        else if (Projection.Viewer.CanCommand)
```

(Keep the existing generic prompt markup in the second branch — replace only its `@onclick="() => OnChoice.InvokeAsync(option)"` with `@onclick="() => Resolve(Projection.PendingChoice.ChoiceId, option)"`.)

In `@code`: delete the `OnChoice` parameter and add:

```csharp
    private Task Resolve(string choiceId, string option) => Send("resolveChoice", new { choiceId, option });

    private static string FaceSymbol(string face) => face switch
    {
        "sword" => "⚔",
        "shield" => "⛨",
        _ => "·",
    };
```

CSS:

```css
.choice-dice { display: flex; gap: .4rem; margin-bottom: .6rem; }
.die { width: 2.6rem; height: 2.6rem; font-size: 1.3rem; color: #1f201d; background: #ede8da; border: 2px solid #b18b48; border-radius: .35rem; }
.die:disabled { opacity: .55; }
.choice-waiting { border-left: .3rem solid #9cc3df; padding-left: .9rem; }
```

- [ ] **Step 4: Poll fast while a choice is pending**

In `GameExperience.razor` change the cadence call's second argument to:

```csharp
                current.PendingChoiceSummary is not null || current.Draft?.PickingSeatId == current.Viewer.SeatId,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `dotnet test`
Expected: all tests PASS.

- [ ] **Step 6: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Add the Moonlight Glare prompt, waiting view and keep-for-them"
```

---

### Task 16: Combat log lines and the README walkthrough

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`
- Modify: `README.md`
- Test: `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`

**Interfaces:**
- Consumes: event payloads from Tasks 3–9.
- Produces: one line per new event kind; `attackResolved` lines name the ability and any re-roll.

- [ ] **Step 1: Write the failing tests**

Look at the top of `CombatLogFormatterTests.cs` for its existing helper that builds a `GameEvent` and a projection (reuse it; if it builds events inline, follow that style). Add:

```csharp
    [Theory]
    [InlineData("evaded", """{"unitId":"s2-u1","attackerId":"s1-u3","roll":14,"evaded":true}""", "Grunt evades (rolled 14).")]
    [InlineData("evaded", """{"unitId":"s2-u1","attackerId":"s1-u3","roll":4,"evaded":false}""", "Grunt fails to evade (rolled 4).")]
    [InlineData("survivedDefeat", """{"unitId":"s2-u1","ability":"One Last Horn"}""", "Grunt refuses to fall: One Last Horn.")]
    [InlineData("chaosRolled", """{"unitId":"s1-u3","roll":6,"struck":false}""", "Chaos Strike falters (rolled 6).")]
    [InlineData("chaosRolled", """{"unitId":"s1-u3","roll":15,"struck":true}""", "Chaos Strike surges (rolled 15)!")]
    [InlineData("splashDamage", """{"ability":"Fireball","hits":[{"unitId":"s2-u1","faces":["sword"],"wounds":1},{"unitId":"s2-u2","faces":["blank"],"wounds":0}]}""", "Fireball scorches Grunt for 1 and Brute for 0.")]
    [InlineData("abilityUsed", """{"unitId":"s1-u3","ability":"Shield Wall","targetUnitId":"s2-u1"}""", "Ranger uses Shield Wall on Grunt.")]
    [InlineData("effectApplied", """{"name":"Rune of Distraction","sourceUnitId":"s1-u3","targetUnitId":"s2-u1","stat":"defense","amount":-2,"expires":{"seatId":"seat-red","at":"turnStart"}}""", "Grunt gets -2 Defense (Rune of Distraction).")]
    [InlineData("effectExpired", """{"name":"Shield Wall","targetUnitId":"s2-u1"}""", "Shield Wall fades from Grunt.")]
    [InlineData("choiceOpened", """{"choiceId":"choice-1","seatId":"seat-blue","kind":"moonlightGlare","unitId":"s2-u1"}""", "Grunt may re-roll a die (Moonlight Glare).")]
    [InlineData("choiceResolved", """{"choiceId":"choice-1","option":"keep"}""", "The roll stands.")]
    [InlineData("attackResolved", """{"unitId":"s1-u3","targetUnitId":"s2-u1","ability":"Fireball","attackFaces":["sword"],"defenseFaces":["shield"],"rerolled":{"side":"defense","index":0,"from":"blank","to":"shield"},"wounds":0}""", "Ranger → Grunt (Fireball): ⚔ vs 🛡 — 0 wounds. Re-rolled a blank: shield.")]
    [InlineData("unitMoved", """{"unitId":"s1-u3","from":"1,4","to":"2,4","disengagement":[{"byUnitId":"s2-u1","faces":[],"wounds":0,"evasionRoll":15,"evaded":true}]}""", "Ranger moves to 2,4. It evades Grunt's parting strike (rolled 15).")]
    public void New_ability_events_have_readable_lines(string kind, string payload, string expected)
    {
        Assert.Equal(expected, CombatLogFormatter.Describe(Event(kind, payload), BattleFixtures.Projection()));
    }

    [Fact]
    public void A_glare_reroll_is_reported_on_the_attack_not_the_choice()
    {
        Assert.Null(CombatLogFormatter.Describe(Event("choiceResolved", """{"choiceId":"choice-1","option":"reroll:1"}"""), BattleFixtures.Projection()));
    }
```

`Event(kind, json)` is the file's existing helper. Existing lines keep their format: faces render as `⚔`/`🛡`/`⬚` and wounds as `N wounds` (no trailing period).

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests --filter New_ability_events`
Expected: FAIL (formatter returns null for the new kinds).

- [ ] **Step 3: Implement**

In `DescribeCore()` replace the `attackResolved` arm and add the new arms:

```csharp
            "attackResolved" => Attack(projection, payload),
            "evaded" => payload.GetProperty("evaded").GetBoolean()
                ? $"{Unit(projection, Text(payload, "unitId"))} evades (rolled {payload.GetProperty("roll").GetInt32()})."
                : $"{Unit(projection, Text(payload, "unitId"))} fails to evade (rolled {payload.GetProperty("roll").GetInt32()}).",
            "survivedDefeat" => $"{Unit(projection, Text(payload, "unitId"))} refuses to fall: {Text(payload, "ability")}.",
            "chaosRolled" => payload.GetProperty("struck").GetBoolean()
                ? $"Chaos Strike surges (rolled {payload.GetProperty("roll").GetInt32()})!"
                : $"Chaos Strike falters (rolled {payload.GetProperty("roll").GetInt32()}).",
            "splashDamage" => $"{Text(payload, "ability")} scorches {JoinAnd(payload.GetProperty("hits").EnumerateArray().Select(hit => $"{Unit(projection, Text(hit, "unitId"))} for {hit.GetProperty("wounds").GetInt32()}"))}.",
            "abilityUsed" => $"{Unit(projection, Text(payload, "unitId"))} uses {Text(payload, "ability")} on {Unit(projection, Text(payload, "targetUnitId"))}.",
            "effectApplied" => $"{Unit(projection, Text(payload, "targetUnitId"))} gets {Signed(payload.GetProperty("amount").GetInt32())} {Title(Text(payload, "stat"))} ({Text(payload, "name")}).",
            "effectExpired" => $"{Text(payload, "name")} fades from {Unit(projection, Text(payload, "targetUnitId"))}.",
            "choiceOpened" => $"{Unit(projection, Text(payload, "unitId"))} may re-roll a die (Moonlight Glare).",
            "choiceResolved" => Text(payload, "option") == "keep" ? "The roll stands." : null,
```

and helpers:

```csharp
    private static string Attack(GameProjection projection, JsonElement payload)
    {
        var ability = payload.TryGetProperty("ability", out var name) && name.ValueKind == JsonValueKind.String ? $" ({name.GetString()})" : string.Empty;
        var line = $"{Unit(projection, Text(payload, "unitId"))} → {Unit(projection, Text(payload, "targetUnitId"))}{ability}: " +
            $"{Faces(payload.GetProperty("attackFaces"))} vs {Faces(payload.GetProperty("defenseFaces"))} — " +
            Wounds(payload.GetProperty("wounds").GetInt32());
        if (payload.TryGetProperty("rerolled", out var reroll) && reroll.ValueKind == JsonValueKind.Object)
        {
            line += $". Re-rolled a {Text(reroll, "from")}: {Text(reroll, "to")}.";
        }
        return line;
    }

    private static string Signed(int amount) => amount > 0 ? $"+{amount}" : amount.ToString(System.Globalization.CultureInfo.InvariantCulture);

    private static string Title(string value) => string.IsNullOrEmpty(value) ? value : char.ToUpperInvariant(value[0]) + value[1..];

    private static string JoinAnd(IEnumerable<string> parts)
    {
        var list = parts.ToList();
        return list.Count <= 1 ? string.Concat(list) : string.Join(", ", list[..^1]) + " and " + list[^1];
    }
```

In `Moved()`, render evaded disengagement hits:

```csharp
        foreach (var hit in payload.GetProperty("disengagement").EnumerateArray())
        {
            if (hit.TryGetProperty("evaded", out var evaded) && evaded.GetBoolean())
            {
                line += $" It evades {Unit(projection, Text(hit, "byUnitId"))}'s parting strike (rolled {hit.GetProperty("evasionRoll").GetInt32()}).";
                continue;
            }
            line += $" {Unit(projection, Text(hit, "byUnitId"))} strikes as it disengages: {Faces(hit.GetProperty("faces"))} — {Wounds(hit.GetProperty("wounds").GetInt32())}.";
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test`
Expected: all tests PASS.

- [ ] **Step 5: Extend the README walkthrough**

In `README.md`, after the "Play a two-browser Vanilla duel" steps, add:

```markdown
### Try the abilities

1. Draft **Malachar the Voidcaller** with **Sol Invictus** in one browser and **Thorgrim Shattershield** with **Amethyst Thornguard** in the other.
2. Select the Wizard: its card lists **Fireball** as a button. Choose it, hover an enemy to see the splash hexes, then click the enemy. The log reports the strike and the scorched neighbours.
3. Select Thorgrim and use **Shield Wall** on an adjacent ally; the ally's card shows `Defense 3 → 4` and its token gets a ▲.
4. Attack the Elvish Duelist (or let it attack). If one of its dice is not a hit/block, its owner sees the **Moonlight Glare** prompt; the other browser shows "Waiting for your opponent's decision…" and, after 45 seconds, a **Keep the roll for them** button.
5. Abilities not yet implemented (Teleport, Gust of Wind, Knockback, Stunning Blow, Flying, Lava Walker) appear on cards marked "Not yet in the digital game".
```

- [ ] **Step 6: Run the whole suite**

Run: `php api/tests/run.php` and `dotnet test`
Expected: both report 0 failures.

- [ ] **Step 7: Commit**

```bash
git add src/BloodAndBanners.UI/Services/CombatLogFormatter.cs tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs README.md
git commit -m "Describe ability events in the battle log and document the walkthrough"
```
