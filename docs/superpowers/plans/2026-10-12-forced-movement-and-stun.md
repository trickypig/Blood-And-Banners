# Forced Movement and Stun Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Knockback, Stunning Blow, Gust of Wind and Teleport become playable, on top of a general unit-conditions framework (Stunned first) and a forced-movement (push) module.

**Architecture:** Conditions live in `state.conditions` with handler classes (`Conditions/Condition`, `Conditions/Stunned`, `Conditions/ConditionRegistry`) and a `UnitConditions` service that `Stats`, `Movement`, `Wounds` and `BattleRules::passTurn` call. `Forced` resolves pushes step by step (collisions, walls, edges, falls) and teleport destinations. Abilities gain `afterHit` / `onFailedSave` hooks; the step runner gains `save` and `teleport` steps. The Blazor table gains condition display, two-step Teleport targeting and a Gust push preview.

**Tech Stack:** PHP 8.3 (`php api/tests/run.php`), .NET 10 Blazor + bUnit/xUnit (`dotnet test tests/BloodAndBanners.UI.Tests`, `dotnet test tests/BloodAndBanners.Contracts.Tests`).

**Spec:** `docs/superpowers/specs/2026-10-12-forced-movement-and-stun-design.md`

## Global Constraints

- Pushes go straight away from the pusher (the axial direction pusher → pushed), one hex at a time.
- Push step outcomes, in this order of checks: off the board → blocked `edge`; sight blocker → blocked `wall`; next hex 2+ levels higher → blocked `wall`; next hex occupied → blocked `unit`; otherwise move in, and if it dropped 2+ levels, fall damage of 1 attack die per level dropped. A 1-level drop is free.
- Blocked `edge`/`wall`: the pushed unit takes 1 attack die (each sword is a Wound, no defense). Blocked `unit`: the pushed unit and the occupant each take 1 attack die.
- Forced movement (pushes, teleports) never triggers disengagement strikes.
- Knockback / Stunning Blow save: d20 after each of the owner's strikes that lands (attack and defense dice rolled) on a target that survives; 11+ resists; Knockback 2–10 pushes 1 hex, a 1 pushes 2; Stunning Blow below 11 stuns. No save when evaded or when the target died.
- Stunned: −2 Power, cannot move (including Gust of Wind), makes no disengagement strikes; lasts until the end of the stunned unit's seat's next turn (`afterTurn` with `Turn::nextTurnNumberOf`); reapplying refreshes; can still be pushed and teleported.
- Gust of Wind: adjacent enemy with printed Move ≥ 3; only while Thalion has not moved or attacked in his current activation (or has not activated yet); counts as his move; pushes 2 hexes.
- Teleport: friendly unit within 3 of Malachar (self allowed); destination empty, on the board, not a sight blocker, and `distance + intdiv(|Δelevation|, 2) ≤ 3`; counts as Malachar's attack; locked by Fragile Focus.
- After this slice only **Flying** and **Lava Walker** are deferred.
- New error codes: `invalidDestination`, `unitStunned`, `gustNeedsStillness`.
- Old saved matches: `MatchEngine::normalize` adds `conditions: []`.
- Commits end with the session's `Co-Authored-By` / `Claude-Session` trailer lines.

Refinements of the spec made while planning (same behaviour):

- The service class is named `UnitConditions` (in `BloodAndBanners\Rules\Vanilla`) to avoid sharing a name with the `Conditions\` namespace.
- `TargetedAbility::apply` gains a `RandomSource $random` parameter (Gust's collisions roll dice) and a new `unavailable(state, unitId): ?string` hook (Gust's stunned / stillness errors); `SpecialAttack::validate` / `steps` gain `array $options = []` (Teleport's `toHex`). Save outcomes are delegated to the ability via `onFailedSave` rather than hard-coded in the runner.
- The Gust preview marks path hexes with a `push-path` class and a `»` marker rather than direction-specific arrows.

## Review Focus

1. A push whose collision or fall damage defeats the last enemy (or the pusher's own last unit via a friendly collision) — resolution must stop, the match must complete, and no later steps (a second Dual-Strike-style save, the rest of the push) may run. Pinned in Task 2.
2. Knockback pushing a unit that a Moonlight Glare choice paused on — the save must happen after the choice resolves, from the saved `land` step. Pinned in Task 3.
3. A stun applied to a unit that is then defeated, or a stunned unit whose stun expires on the same turn boundary as an effect — no orphan conditions, events in a stable order. Pinned in Task 1.
4. Teleport destinations when the board has holes (scenario maps are not full rectangles) and when the chosen ally is the caster — the client's computed destinations must match the server's. Pinned in Task 5 (server) and Task 9 (client, same fixture shape).
5. Gust of Wind on a target whose push path immediately hits the edge — Thalion's move is still spent and the collision die is rolled. Pinned in Task 4.

---

## File Structure

| File | Responsibility |
|---|---|
| `api/src/Rules/Vanilla/Conditions/Condition.php` (create) | Base condition with no-op hooks. |
| `api/src/Rules/Vanilla/Conditions/Stunned.php` (create) | −2 Power, roots, no parting strikes. |
| `api/src/Rules/Vanilla/Conditions/ConditionRegistry.php` (create) | Name → handler. |
| `api/src/Rules/Vanilla/UnitConditions.php` (create) | Apply, query, stat deltas, turn-boundary hooks and expiry, clear on defeat. |
| `api/src/Rules/Vanilla/Forced.php` (create) | Push resolution, direction between adjacent hexes, teleport destinations. |
| `api/src/Rules/Vanilla/Abilities/Knockback.php`, `StunningBlow.php`, `GustOfWind.php`, `Teleport.php` (create) | The four abilities. |
| `api/src/Rules/Vanilla/Abilities/Ability.php`, `SpecialAttack.php`, `TargetedAbility.php`, `StatBlessing.php`, `FixedStrike.php`, `ChaosStrike.php`, `Fireball.php`, `MysticShield.php`, `ChainLightning.php`, `AbilityRegistry.php` (modify) | New hooks / signatures; registrations. |
| `api/src/Rules/Vanilla/StepRunner.php`, `BattleRules.php`, `Stats.php`, `Movement.php`, `Wounds.php`, `MatchEngine.php` (modify) | Integration. |
| `api/src/Game/GameService.php` (modify) | Project `conditions`. |
| `src/BloodAndBanners.Contracts/MatchProjection.cs` (modify) | `ConditionProjection`, `UnitProjection.Conditions`. |
| `src/BloodAndBanners.UI/Services/ConditionText.cs` (create) | Client wording and stat deltas for conditions. |
| `src/BloodAndBanners.UI/Services/HexMath.cs` (modify) | `Direction`, `Step`. |
| `src/BloodAndBanners.UI/Components/GameTable.razor` (+`.css`) (modify) | Condition display, Teleport two-step targeting, Gust preview. |
| `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs` (modify) | New event lines. |
| `api/tests/VanillaForcedTest.php` (create, registered in `api/tests/run.php`) | PHP tests for this slice. |

---

### Task 1: Conditions framework and Stunned

**Files:**
- Create: `api/src/Rules/Vanilla/Conditions/Condition.php`, `Stunned.php`, `ConditionRegistry.php`, `api/src/Rules/Vanilla/UnitConditions.php`
- Modify: `api/src/Rules/Vanilla/Stats.php`, `Movement.php`, `Wounds.php`, `MatchEngine.php`, `BattleRules.php`
- Create: `api/tests/VanillaForcedTest.php`; Modify: `api/tests/run.php`, `api/tests/VanillaTestSupport.php`

**Interfaces:**
- Produces:
  - `abstract class Conditions\Condition` — `public readonly string $name`; `statDelta(array $state, string $unitId, string $stat, array $context): int`, `blocksMovement(): bool`, `blocksDisengagementStrike(): bool`, `onTurnStart(array $state, string $unitId, array &$events): array`, `onTurnEnd(array $state, string $unitId, array &$events): array` (all no-ops).
  - `Conditions\ConditionRegistry::get(string $name): ?Condition`.
  - `UnitConditions::afterTurn(int $turnNumber): array`, `apply(array $state, string $name, string $unitId, ?string $sourceUnitId, array $expires, array &$events, array $data = []): array`, `forUnit(array $state, string $unitId): array`, `has(array $state, string $unitId, string $name): bool`, `any(array $state, string $unitId, callable $test): bool` (test receives a `Condition`), `statDelta(array $state, string $unitId, string $stat, array $context): int`, `clearUnit(array $state, string $unitId): array`, `atTurnEnd(array $state, array &$events): array`, `atTurnStart(array $state, array &$events): array`.
  - Events `conditionApplied {name, unitId, sourceUnitId, expires}`, `conditionExpired {name, unitId}`.
  - `BattleRules` private `rooted(array $state, string $unitId): bool`; error `unitStunned`.

- [ ] **Step 1: Write the failing tests**

Create `api/tests/VanillaForcedTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

/** Stun $unitId until the end of its seat's next turn, as Stunning Blow does. */
function stun(array $state, string $unitId, string $sourceUnitId = 'nobody'): array
{
    $events = [];
    $seat = $state['units'][$unitId]['seatId'];
    return UnitConditions::apply($state, 'Stunned', $unitId, $sourceUnitId, UnitConditions::afterTurn(Turn::nextTurnNumberOf($state, $seat)), $events);
}

function vanilla_forced_tests(): array
{
    return [
        'a stunned unit is weakened, rooted and makes no parting strike until its next turn ends' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '1,0'),
                vanilla_unit('b1', 'seat-b', '0,0', ['power' => 3]),
            ]);
            $state = stun($state, 'b1', 'a1');
            expect_same(1, Stats::effective($state, 'b1', 'power'), 'Stunned costs 2 Power');
            expect_same([['name' => 'Stunned', 'unitId' => 'b1', 'sourceUnitId' => 'a1', 'expires' => ['kind' => 'afterTurn', 'turnNumber' => 2], 'data' => []]], $state['conditions'], 'the stun lasts through seat-b\'s next turn (turn 2)');

            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0']);
            expect_same([], $moved['events'][0]['payload']['disengagement'], 'a stunned enemy makes no parting strike');

            $theirTurn = vanilla_command($moved['state'], 'seat-a', 'endTurn')['state'];
            expect_same(2, $theirTurn['turn']['number'], 'seat-b\'s turn is number two');
            expect_true(!in_array('moveUnit', array_column((new MatchEngine(test_catalog()))->legalActions($theirTurn, 'seat-b'), 'kind'), true), 'a stunned unit has no move');
            expect_game_error('unitStunned', static fn() => vanilla_command($theirTurn, 'seat-b', 'moveUnit', ['unitId' => 'b1', 'to' => '1,0']));

            $over = vanilla_command($theirTurn, 'seat-b', 'endTurn');
            expect_same(['conditionExpired', 'turnEnded'], array_column($over['events'], 'kind'), 'the stun lifts as seat-b\'s turn ends');
            expect_same([], $over['state']['conditions'], 'no conditions remain');
            expect_same(3, Stats::effective($over['state'], 'b1', 'power'), 'Power is back');
        },

        'conditions refresh instead of stacking and survive a JSON round trip' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0', ['power' => 4])]);
            $events = [];
            $state = UnitConditions::apply($state, 'Stunned', 'b1', 'a1', UnitConditions::afterTurn(2), $events);
            $state = UnitConditions::apply($state, 'Stunned', 'b1', 'a1', UnitConditions::afterTurn(4), $events);
            expect_same(['conditionApplied', 'conditionApplied'], array_column($events, 'kind'), 'each application is announced');
            expect_same(1, count($state['conditions']), 'one stun per unit');
            expect_same(4, $state['conditions'][0]['expires']['turnNumber'], 'the later expiry wins');
            expect_same(2, Stats::effective($state, 'b1', 'power'), 'stuns do not stack');
            $saved = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_same(true, UnitConditions::has($saved, 'b1', 'Stunned'), 'the stun survives saving');
        },

        'a defeated unit loses its conditions' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 1]),
                vanilla_unit('b2', 'seat-b', '2,0'),
            ]);
            $state = stun($state, 'b1', 'a1');
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]);
            expect_same([], $result['state']['conditions'], 'the fallen are no longer stunned');
        },

        'old saved states gain an empty condition list' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            unset($state['conditions']);
            expect_same([], MatchEngine::normalize($state)['conditions'], 'conditions default to empty');
        },
    ];
}
```

In `api/tests/run.php`: add `require __DIR__ . '/VanillaForcedTest.php';` after the `VanillaAbilityTest.php` require, and `vanilla_forced_tests(), ` after `vanilla_ability_tests(), ` in the `array_merge`. In `api/tests/VanillaTestSupport.php` `vanilla_battle()`, add `'conditions' => [],` next to `'effects' => [],`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the four new tests FAIL (class `UnitConditions` not found / missing `conditions` key).

- [ ] **Step 3: Implement the condition classes**

`api/src/Rules/Vanilla/Conditions/Condition.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

/** A named state on a unit (Stunned now; Unsteady, Slipping, Poisoned later). Hooks default to "no effect". */
abstract class Condition
{
    public function __construct(public readonly string $name)
    {
    }

    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return 0;
    }

    public function blocksMovement(): bool
    {
        return false;
    }

    public function blocksDisengagementStrike(): bool
    {
        return false;
    }

    public function onTurnStart(array $state, string $unitId, array &$events): array
    {
        return $state;
    }

    public function onTurnEnd(array $state, string $unitId, array &$events): array
    {
        return $state;
    }
}
```

`Stunned.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class Stunned extends Condition
{
    public function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        return $stat === 'power' ? -2 : 0;
    }

    public function blocksMovement(): bool
    {
        return true;
    }

    public function blocksDisengagementStrike(): bool
    {
        return true;
    }
}
```

`ConditionRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Conditions;

final class ConditionRegistry
{
    /** @var array<string, Condition>|null */
    private static ?array $handlers = null;

    public static function get(string $name): ?Condition
    {
        if (self::$handlers === null) {
            self::$handlers = [];
            foreach ([new Stunned('Stunned')] as $handler) {
                self::$handlers[$handler->name] = $handler;
            }
        }
        return self::$handlers[$name] ?? null;
    }
}
```

`api/src/Rules/Vanilla/UnitConditions.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Conditions\ConditionRegistry;

/**
 * Conditions on units. Expiry kinds: afterTurn {turnNumber} (ends when that turn ends), turnStart/turnEnd {seatId}
 * (as for effects), whileOnTerrain and untilCleared (reserved for terrain; never expired by turn boundaries).
 */
final class UnitConditions
{
    public static function afterTurn(int $turnNumber): array
    {
        return ['kind' => 'afterTurn', 'turnNumber' => $turnNumber];
    }

    public static function apply(array $state, string $name, string $unitId, ?string $sourceUnitId, array $expires, array &$events, array $data = []): array
    {
        $state['conditions'] = array_values(array_filter(
            $state['conditions'],
            static fn(array $condition): bool => !($condition['name'] === $name && $condition['unitId'] === $unitId)
        ));
        $state['conditions'][] = ['name' => $name, 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'expires' => $expires, 'data' => $data];
        $hex = $state['units'][$unitId]['hex'];
        $events[] = MatchEngine::event('conditionApplied', $hex === null ? [] : [$hex], [
            'name' => $name, 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'expires' => $expires,
        ]);
        return $state;
    }

    public static function forUnit(array $state, string $unitId): array
    {
        return array_values(array_filter(
            $state['conditions'] ?? [],
            static fn(array $condition): bool => $condition['unitId'] === $unitId
        ));
    }

    public static function has(array $state, string $unitId, string $name): bool
    {
        return in_array($name, array_column(self::forUnit($state, $unitId), 'name'), true);
    }

    public static function any(array $state, string $unitId, callable $test): bool
    {
        foreach (self::forUnit($state, $unitId) as $condition) {
            $handler = ConditionRegistry::get($condition['name']);
            if ($handler !== null && $test($handler)) {
                return true;
            }
        }
        return false;
    }

    public static function statDelta(array $state, string $unitId, string $stat, array $context): int
    {
        $delta = 0;
        foreach (self::forUnit($state, $unitId) as $condition) {
            $delta += ConditionRegistry::get($condition['name'])?->statDelta($state, $unitId, $stat, $context) ?? 0;
        }
        return $delta;
    }

    /** Silently drop a unit's conditions (it left the battle). */
    public static function clearUnit(array $state, string $unitId): array
    {
        $state['conditions'] = array_values(array_filter(
            $state['conditions'] ?? [],
            static fn(array $condition): bool => $condition['unitId'] !== $unitId
        ));
        return $state;
    }

    /** Run turn-end hooks, then expire conditions bound to the ending turn. Call before the turn record changes. */
    public static function atTurnEnd(array $state, array &$events): array
    {
        $state = self::runHooks($state, 'onTurnEnd', $events);
        $number = $state['turn']['number'];
        $seat = $state['turn']['activeSeatId'];
        return self::expireWhere($state, static fn(array $expires): bool =>
            ($expires['kind'] === 'afterTurn' && $expires['turnNumber'] === $number)
            || ($expires['kind'] === 'turnEnd' && $expires['seatId'] === $seat), $events);
    }

    /** Run turn-start hooks, then expire conditions bound to the starting seat. Call after the new turn is set. */
    public static function atTurnStart(array $state, array &$events): array
    {
        $seat = $state['turn']['activeSeatId'];
        $state = self::expireWhere($state, static fn(array $expires): bool =>
            $expires['kind'] === 'turnStart' && $expires['seatId'] === $seat, $events);
        return self::runHooks($state, 'onTurnStart', $events);
    }

    private static function runHooks(array $state, string $hook, array &$events): array
    {
        foreach ($state['conditions'] as $condition) {
            $handler = ConditionRegistry::get($condition['name']);
            if ($handler instanceof Condition && $state['units'][$condition['unitId']]['hex'] !== null) {
                $state = $handler->{$hook}($state, $condition['unitId'], $events);
            }
        }
        return $state;
    }

    private static function expireWhere(array $state, callable $matches, array &$events): array
    {
        $kept = [];
        foreach ($state['conditions'] as $condition) {
            if ($matches($condition['expires'])) {
                $events[] = MatchEngine::event('conditionExpired', [], ['name' => $condition['name'], 'unitId' => $condition['unitId']]);
                continue;
            }
            $kept[] = $condition;
        }
        $state['conditions'] = $kept;
        return $state;
    }
}
```

- [ ] **Step 4: Integrate**

`Stats::effective` — change the return to include conditions:

```php
        $value += UnitConditions::statDelta($state, $unitId, $stat, $context);
        return max(0, $value);
```

`Movement::disengagedFrom` — skip enemies that cannot strike; at the top of the loop body after the seat/hex check add:

```php
            if (UnitConditions::any($state, $other['unitId'], static fn(Condition $condition): bool => $condition->blocksDisengagementStrike())) {
                continue;
            }
```

with `use BloodAndBanners\Rules\Vanilla\Conditions\Condition;`.

`Wounds::apply` — on defeat, after `$state['units'][$unitId]['hex'] = null;` add `$state = UnitConditions::clearUnit($state, $unitId);`.

`MatchEngine` — `start()` state gains `'conditions' => [],` (next to `'effects' => []`); `normalize()` gains `$state['conditions'] ??= [];`.

`BattleRules`:

- Message `'unitStunned' => 'A stunned unit cannot move.',`.
- Helper:

```php
    private static function rooted(array $state, string $unitId): bool
    {
        return UnitConditions::any($state, $unitId, static fn(Condition $condition): bool => $condition->blocksMovement());
    }
```

- `canMove()` first line: `if (self::rooted($state, $unitId)) { return false; }`.
- `move()`: right after `$state = $this->activate($state, $seatId, $unitId);` add `if (self::rooted($state, $unitId)) { self::fail('unitStunned'); }`.
- `passTurn()`: after the `Effects::expire(... UNTIL_END_OF_TURN ...)` line add `$state = UnitConditions::atTurnEnd($state, $events);`; after the final `Effects::expire(... UNTIL_NEXT_TURN ...)` line add `$state = UnitConditions::atTurnStart($state, $events);`.
- Add `use BloodAndBanners\Rules\Vanilla\Conditions\Condition;`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api
git commit -m "Add a unit conditions framework with Stunned"
```

---

### Task 2: Forced movement

**Files:**
- Create: `api/src/Rules/Vanilla/Forced.php`
- Test: `api/tests/VanillaForcedTest.php`

**Interfaces:**
- Consumes: `Wounds::apply`, `Hex::parse/key/distance`, `Hex::DIRECTIONS`.
- Produces:
  - `Forced::directionFrom(string $fromHex, string $toHex): ?array` — `[dq, dr]` for adjacent hexes, else null.
  - `Forced::push(array $state, string $unitId, array $direction, int $hexes, ?string $sourceUnitId, RandomSource $random, array &$events): array` — emits `unitPushed {unitId, sourceUnitId, from, to, path, collisions: [{unitId, faces, wounds}], falls: [{levels, faces, wounds}], blocked: 'unit'|'wall'|'edge'|null}` (focus hexes = path), then any defeat/victory events caused by the damage.
  - `Forced::teleportDestinations(array $state, string $unitId): array` — sorted hex keys.

- [ ] **Step 1: Write the failing tests**

Add `use BloodAndBanners\Rules\Vanilla\Forced;` to the file's `use` lines and append to `vanilla_forced_tests()`:

```php
        'a free push moves the unit straight back without dice' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 2, 'a1', new SequenceRandom([]), $events);
            expect_same('3,0', $state['units']['b1']['hex'], 'two hexes back');
            expect_same(
                ['unitId' => 'b1', 'sourceUnitId' => 'a1', 'from' => '1,0', 'to' => '3,0', 'path' => ['1,0', '2,0', '3,0'], 'collisions' => [], 'falls' => [], 'blocked' => null],
                $events[0]['payload'],
                'the push is reported'
            );
            expect_same(['1,0', '2,0', '3,0'], $events[0]['focusHexes'], 'the path is focused');
        },

        'pushing into a unit stops the push and hurts both' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 5]),
                vanilla_unit('a2', 'seat-a', '2,0', ['hp' => 5]),
            ]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 1, 'a1', new SequenceRandom([1, 4]), $events);
            expect_same('1,0', $state['units']['b1']['hex'], 'blocked in place');
            expect_same('unit', $events[0]['payload']['blocked'], 'blocked by a unit');
            expect_same([['unitId' => 'b1', 'faces' => ['sword'], 'wounds' => 1], ['unitId' => 'a2', 'faces' => ['shield'], 'wounds' => 0]], $events[0]['payload']['collisions'], 'each rolls one die');
            expect_same(1, $state['units']['b1']['wounds'], 'the pushed unit is hurt');
        },

        'walls, sight blockers and the board edge stop a push and hurt the pushed unit' => static function (): void {
            $cases = [
                'wall' => [vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 2]), 'wall'],
                'blocker' => [vanilla_board(vanilla_line(3), ['2,0']), 'wall'],
                'edge' => [vanilla_board(vanilla_line(2)), 'edge'],
            ];
            foreach ($cases as $label => [$board, $blocked]) {
                $state = vanilla_battle($board, [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 5])]);
                $events = [];
                $state = Forced::push($state, 'b1', [1, 0], 1, 'a1', new SequenceRandom([1]), $events);
                expect_same($blocked, $events[0]['payload']['blocked'], "{$label} blocks");
                expect_same('1,0', $state['units']['b1']['hex'], "{$label} holds the unit in place");
                expect_same(1, $state['units']['b1']['wounds'], "{$label} costs a die of damage");
            }
        },

        'falling two or more levels costs a die per level and the push continues' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 3, '1,0' => 3, '2,0' => 1, '3,0' => 1]), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 5]),
            ]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 2, 'a1', new SequenceRandom([1, 1]), $events);
            expect_same('3,0', $state['units']['b1']['hex'], 'over the ledge and on');
            expect_same([['levels' => 2, 'faces' => ['sword', 'sword'], 'wounds' => 2]], $events[0]['payload']['falls'], 'two levels, two dice');
            expect_same(2, $state['units']['b1']['wounds'], 'the fall hurts');

            $gentle = vanilla_battle(vanilla_board(['0,0' => 1, '1,0' => 1, '2,0' => 0]), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')]);
            $events = [];
            $gentle = Forced::push($gentle, 'b1', [1, 0], 1, 'a1', new SequenceRandom([]), $events);
            expect_same([], $events[0]['payload']['falls'], 'a one-level drop is a normal step');
        },

        'a unit killed mid-push stops there, and a lethal push can end the match' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 3, '1,0' => 3, '2,0' => 1, '3,0' => 1, '4,0' => 1]), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 1]),
                vanilla_unit('b2', 'seat-b', '4,0'),
            ]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 2, 'a1', new SequenceRandom([1, 6]), $events);
            expect_same(['unitPushed', 'unitDefeated'], array_column($events, 'kind'), 'the push is reported before the fall kills');
            expect_same(['1,0', '2,0'], $events[0]['payload']['path'], 'the dead do not slide on');

            $last = vanilla_battle(vanilla_board(vanilla_line(2)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 1])]);
            $events = [];
            $last = Forced::push($last, 'b1', [1, 0], 1, 'a1', new SequenceRandom([1]), $events);
            expect_same(['unitPushed', 'unitDefeated', 'matchCompleted'], array_column($events, 'kind'), 'the edge finishes the war');
            expect_same('completed', $last['phase'], 'the match is over');
        },

        'directions and teleport destinations' => static function (): void {
            expect_same([1, 0], Forced::directionFrom('0,0', '1,0'), 'adjacent hexes give a direction');
            expect_same([0, -1], Forced::directionFrom('2,3', '2,2'), 'any of the six');
            expect_same(null, Forced::directionFrom('0,0', '2,0'), 'non-adjacent hexes give none');

            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 2, '3,0' => 4, '4,0' => 0, '0,1' => 0], ['0,1']), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(['2,0'], Forced::teleportDestinations($state, 'a1'), 'occupied, blocked and too-high-or-far hexes are excluded');
        },
```

Trace of the destination case: `1,0` occupied; `2,0` cost 2 + ⌊2/2⌋ = 3 ✓; `3,0` cost 3 + 2 = 5 ✗; `4,0` cost 4 ✗; `0,1` is a sight blocker ✗.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the six new tests FAIL (class `Forced` not found).

- [ ] **Step 3: Implement**

`api/src/Rules/Vanilla/Forced.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use BloodAndBanners\Rules\CustomDice;
use BloodAndBanners\Rules\RandomSource;

/** Forced movement: pushes (with collisions, walls, edges and falls) and teleport destinations. Never triggers disengagement. */
final class Forced
{
    private const TELEPORT_REACH = 3;

    public static function directionFrom(string $fromHex, string $toHex): ?array
    {
        [$q1, $r1] = Hex::parse($fromHex);
        [$q2, $r2] = Hex::parse($toHex);
        $direction = [$q2 - $q1, $r2 - $r1];
        return in_array($direction, Hex::DIRECTIONS, true) ? $direction : null;
    }

    public static function push(array $state, string $unitId, array $direction, int $hexes, ?string $sourceUnitId, RandomSource $random, array &$events): array
    {
        $dice = new CustomDice();
        $from = $state['units'][$unitId]['hex'];
        $path = [$from];
        $collisions = [];
        $falls = [];
        $blocked = null;
        $consequences = [];
        for ($step = 0; $step < $hexes && $state['phase'] === 'active'; $step++) {
            $here = $state['units'][$unitId]['hex'];
            if ($here === null) {
                break;
            }
            [$q, $r] = Hex::parse($here);
            $next = Hex::key($q + $direction[0], $r + $direction[1]);
            $hex = $state['board'][$next] ?? null;
            if ($hex === null || $hex['sightBlocker'] || $hex['elevation'] - $state['board'][$here]['elevation'] >= 2) {
                $blocked = $hex === null ? 'edge' : 'wall';
                $roll = $dice->roll(1, $random);
                $collisions[] = ['unitId' => $unitId, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
                $state = Wounds::apply($state, $unitId, $roll['swords'], $consequences);
                break;
            }
            $occupant = self::occupantAt($state, $next);
            if ($occupant !== null) {
                $blocked = 'unit';
                $rolls = [];
                foreach ([$unitId, $occupant] as $hitId) {
                    $roll = $dice->roll(1, $random);
                    $rolls[$hitId] = $roll['swords'];
                    $collisions[] = ['unitId' => $hitId, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
                }
                foreach ($rolls as $hitId => $wounds) {
                    $state = Wounds::apply($state, (string)$hitId, $wounds, $consequences);
                }
                break;
            }
            $drop = $state['board'][$here]['elevation'] - $hex['elevation'];
            $state['units'][$unitId]['hex'] = $next;
            $path[] = $next;
            if ($drop >= 2) {
                $roll = $dice->roll($drop, $random);
                $falls[] = ['levels' => $drop, 'faces' => $roll['faces'], 'wounds' => $roll['swords']];
                $state = Wounds::apply($state, $unitId, $roll['swords'], $consequences);
            }
        }
        $events[] = MatchEngine::event('unitPushed', $path, [
            'unitId' => $unitId,
            'sourceUnitId' => $sourceUnitId,
            'from' => $from,
            'to' => $path[count($path) - 1],
            'path' => $path,
            'collisions' => $collisions,
            'falls' => $falls,
            'blocked' => $blocked,
        ]);
        foreach ($consequences as $event) {
            $events[] = $event;
        }
        return $state;
    }

    /** Empty, walkable hexes the unit could be teleported to. */
    public static function teleportDestinations(array $state, string $unitId): array
    {
        $origin = $state['units'][$unitId]['hex'];
        $elevation = $state['board'][$origin]['elevation'];
        $destinations = [];
        foreach ($state['board'] as $key => $hex) {
            $key = (string)$key;
            if ($key === $origin || $hex['sightBlocker'] || self::occupantAt($state, $key) !== null) {
                continue;
            }
            if (Hex::distance($origin, $key) + intdiv(abs($hex['elevation'] - $elevation), 2) <= self::TELEPORT_REACH) {
                $destinations[] = $key;
            }
        }
        sort($destinations, SORT_STRING);
        return $destinations;
    }

    private static function occupantAt(array $state, string $hex): ?string
    {
        foreach ($state['units'] as $unitId => $unit) {
            if ($unit['hex'] === $hex) {
                return (string)$unitId;
            }
        }
        return null;
    }
}
```

Note the collision case rolls both dice before applying either, so a defeat of the first unit cannot skip the second unit's roll.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add api
git commit -m "Resolve pushes with collisions, walls, edges and falls"
```

---

### Task 3: Knockback and Stunning Blow

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/Knockback.php`, `StunningBlow.php`
- Modify: `api/src/Rules/Vanilla/Abilities/Ability.php`, `AbilityRegistry.php`, `api/src/Rules/Vanilla/StepRunner.php`
- Test: `api/tests/VanillaForcedTest.php`, `api/tests/VanillaAbilityTest.php` (catalog smoke list)

**Interfaces:**
- Consumes: `Forced::push`, `Forced::directionFrom`, `UnitConditions::apply/afterTurn`, `Turn::nextTurnNumberOf`.
- Produces:
  - `Ability::afterHit(array $state, string $ownerId, string $targetId): array` (steps; default `[]`).
  - `Ability::onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array` (state; default unchanged).
  - `StepRunner::save(string $unitId, string $sourceUnitId, string $ability): array`; event `saveRolled {unitId, sourceUnitId, ability, roll, saved}`.
  - `land` now returns `[state, steps]`.

- [ ] **Step 1: Write the failing tests**

Append to `vanilla_forced_tests()` (add `ability_unit` is already available from `VanillaAbilityTest.php`):

```php
        'Knockback drives a target back 1 hex on a failed save and 2 on a natural 1' => static function (): void {
            $setup = static fn(): array => vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $attack = ['unitId' => 'a1', 'targetUnitId' => 'b1'];
            $pushed = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [1, 5]);
            expect_same(['attackResolved', 'saveRolled', 'unitPushed'], array_slice(array_column($pushed['events'], 'kind'), 0, 3), 'hit, save, push');
            expect_same(['unitId' => 'b1', 'sourceUnitId' => 'a1', 'ability' => 'Knockback', 'roll' => 5, 'saved' => false], $pushed['events'][1]['payload'], 'the save is reported');
            expect_same('2,0', $pushed['state']['units']['b1']['hex'], 'one hex back');
            $flung = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [1, 1]);
            expect_same('3,0', $flung['state']['units']['b1']['hex'], 'a natural 1 means two hexes');
            $held = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [1, 11]);
            expect_same('1,0', $held['state']['units']['b1']['hex'], '11 or more resists');
            $glancing = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [6, 4]);
            expect_same(['attackResolved', 'saveRolled', 'unitPushed'], array_slice(array_column($glancing['events'], 'kind'), 0, 3), 'a blow without wounds still forces a save');
        },

        'no save after an evaded or lethal attack' => static function (): void {
            $evader = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                ability_unit('b1', 'seat-b', '1,0', ['Evasion'], ['hp' => 9]),
            ]);
            $missed = vanilla_command($evader, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [15]);
            expect_same(['evaded'], array_slice(array_column($missed['events'], 'kind'), 0, 1), 'evaded');
            expect_true(!in_array('saveRolled', array_column($missed['events'], 'kind'), true), 'no save without an attack');
            $doomed = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 1]),
                vanilla_unit('b2', 'seat-b', '3,0'),
            ]);
            $killed = vanilla_command($doomed, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]);
            expect_true(!in_array('saveRolled', array_column($killed['events'], 'kind'), true), 'the dead make no save');
        },

        'Knockback waits for a Moonlight Glare decision before its save' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                ability_unit('b1', 'seat-b', '1,0', ['Moonlight Glare'], ['defense' => 1, 'hp' => 9]),
                vanilla_unit('a2', 'seat-a', '4,0'),
            ]);
            $opened = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 6], 1000);
            expect_same(['choiceOpened'], array_column($opened['events'], 'kind'), 'paused before the save');
            $kept = vanilla_command($opened['state'], 'seat-b', 'resolveChoice', ['choiceId' => 'choice-1', 'option' => 'keep'], [3], 1001);
            expect_same(['choiceResolved', 'attackResolved', 'saveRolled', 'unitPushed'], array_column($kept['events'], 'kind'), 'the save follows the resumed attack');
        },

        'Stunning Blow stuns on a failed save until the end of the victim\'s next turn' => static function (): void {
            $setup = static fn(): array => vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Stunning Blow'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $stunned = vanilla_command($setup(), 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [6, 10]);
            expect_same(['attackResolved', 'saveRolled', 'conditionApplied'], array_slice(array_column($stunned['events'], 'kind'), 0, 3), 'stunned');
            expect_same(['kind' => 'afterTurn', 'turnNumber' => 2], $stunned['events'][2]['payload']['expires'], 'through seat-b\'s next turn');
            $resisted = vanilla_command($setup(), 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [6, 11]);
            expect_same([], $resisted['state']['conditions'], '11 or more resists');
        },
```

In `api/tests/VanillaAbilityTest.php`, test `'every ability in gameData.json is either implemented or explicitly deferred'`: change the deferred list to `['Teleport', 'Gust of Wind', 'Flying', 'Lava Walker']`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the four new tests FAIL (Knockback/Stunning Blow have no handler), and the smoke test FAILS on `Knockback playability`.

- [ ] **Step 3: Implement the hooks and handlers**

`Ability.php` — add (with `use BloodAndBanners\Rules\RandomSource;`):

```php
    /** Steps to run after this unit's strike lands on a surviving target (attack and defense dice were rolled). */
    public function afterHit(array $state, string $ownerId, string $targetId): array
    {
        return [];
    }

    /** What happens when the target of this ability's save fails it. */
    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        return $state;
    }
```

`Knockback.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\StepRunner;

/** After each hit, the target saves on 11+ or is pushed 1 hex away (2 on a natural 1). */
final class Knockback extends Ability
{
    public function afterHit(array $state, string $ownerId, string $targetId): array
    {
        return [StepRunner::save($targetId, $ownerId, $this->name)];
    }

    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        $direction = Forced::directionFrom($state['units'][$ownerId]['hex'], $state['units'][$targetId]['hex']);
        if ($direction === null) {
            return $state;
        }
        return Forced::push($state, $targetId, $direction, $roll === 1 ? 2 : 1, $ownerId, $random, $events);
    }
}
```

`StunningBlow.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

/** After each hit, the target saves on 11+ or is Stunned until the end of its seat's next turn. */
final class StunningBlow extends Ability
{
    public function afterHit(array $state, string $ownerId, string $targetId): array
    {
        return [StepRunner::save($targetId, $ownerId, $this->name)];
    }

    public function onFailedSave(array $state, string $ownerId, string $targetId, int $roll, RandomSource $random, array &$events): array
    {
        $seat = $state['units'][$targetId]['seatId'];
        return UnitConditions::apply($state, 'Stunned', $targetId, $ownerId, UnitConditions::afterTurn(Turn::nextTurnNumberOf($state, $seat)), $events);
    }
}
```

Register in `AbilityRegistry` `$list`: `new Knockback('Knockback'), new StunningBlow('Stunning Blow'),`.

- [ ] **Step 4: Save steps in the runner**

`StepRunner`:

```php
    public static function save(string $unitId, string $sourceUnitId, string $ability): array
    {
        return ['op' => 'save', 'unitId' => $unitId, 'sourceUnitId' => $sourceUnitId, 'ability' => $ability];
    }
```

In `execute()` replace the `'land'` arm with `'land' => [...$this->land($state, $step, $events), null],` and add `'save' => [$this->saveStep($state, $step, $events), [], null],`.

Change `land()` to return `[state, steps]` — replace its final `return Wounds::apply(...)` with:

```php
        $state = Wounds::apply($state, $step['targetId'], $wounds, $events);
        $attacker = $state['units'][$step['attackerId']];
        if ($state['phase'] !== 'active' || $attacker['hex'] === null || $state['units'][$step['targetId']]['hex'] === null) {
            return [$state, []];
        }
        $more = [];
        foreach (AbilityRegistry::abilitiesOf($attacker) as $ability) {
            $more = array_merge($more, $ability->afterHit($state, $step['attackerId'], $step['targetId']));
        }
        return [$state, $more];
```

and change its signature's return comment accordingly. Add:

```php
    private function saveStep(array $state, array $step, array &$events): array
    {
        $target = $state['units'][$step['unitId']];
        $source = $state['units'][$step['sourceUnitId']];
        if ($target['hex'] === null || $source['hex'] === null) {
            return $state;
        }
        $roll = $this->random->roll(20);
        $saved = $roll >= 11;
        $events[] = MatchEngine::event('saveRolled', [$target['hex']], [
            'unitId' => $step['unitId'], 'sourceUnitId' => $step['sourceUnitId'], 'ability' => $step['ability'], 'roll' => $roll, 'saved' => $saved,
        ]);
        if ($saved) {
            return $state;
        }
        $handler = AbilityRegistry::get($step['ability']);
        return $handler === null ? $state : $handler->onFailedSave($state, $step['sourceUnitId'], $step['unitId'], $roll, $this->random, $events);
    }
```

`resolve()` already re-runs the saved steps through `run()`, so a Glare pause before `land` keeps the save.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api
git commit -m "Add Knockback and Stunning Blow saves"
```

---

### Task 4: Gust of Wind

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/GustOfWind.php`
- Modify: `api/src/Rules/Vanilla/Abilities/TargetedAbility.php`, `StatBlessing.php`, `AbilityRegistry.php`, `api/src/Rules/Vanilla/BattleRules.php`
- Test: `api/tests/VanillaForcedTest.php`, `api/tests/VanillaAbilityTest.php` (smoke list)

**Interfaces:**
- Consumes: `Forced::push/directionFrom`, `UnitConditions::any`, `Targeting::unitsWithin`.
- Produces:
  - `TargetedAbility::apply(array $state, string $unitId, string $targetId, RandomSource $random, array &$events): array` (new `$random` parameter; `StatBlessing` updated).
  - `TargetedAbility::unavailable(array $state, string $unitId): ?string` (default null) — checked by `useAbility` (before activating) and by legal actions.
  - Errors `gustNeedsStillness`; `unitStunned` reused.

- [ ] **Step 1: Write the failing tests**

```php
        'Gust of Wind pushes an adjacent quick enemy 2 hexes instead of Thalion moving' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a-hero', 'seat-a', '1,0', ['Gust of Wind'], ['isHero' => true]),
                vanilla_unit('b1', 'seat-b', '2,0', ['move' => 3]),
                vanilla_unit('b2', 'seat-b', '0,0', ['move' => 2]),
            ]);
            $actions = array_values(array_filter((new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'), static fn(array $a): bool => $a['kind'] === 'useAbility'));
            expect_same([['kind' => 'useAbility', 'unitId' => 'a-hero', 'ability' => 'Gust of Wind', 'targetHexes' => ['2,0']]], $actions, 'only enemies with Move 3+ can be blown away');
            $gust = vanilla_command($state, 'seat-a', 'useAbility', ['unitId' => 'a-hero', 'ability' => 'Gust of Wind', 'targetUnitId' => 'b1']);
            expect_same(['unitPushed', 'abilityUsed'], array_slice(array_column($gust['events'], 'kind'), 0, 2), 'the wind blows');
            expect_same('4,0', $gust['state']['units']['b1']['hex'], 'two hexes back');
            expect_game_error('alreadyMoved', static fn() => vanilla_command($gust['state'], 'seat-a', 'moveUnit', ['unitId' => 'a-hero', 'to' => '2,0']));
        },

        'Gust of Wind needs Thalion still and unstunned, and a blocked gust still costs his move' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a-hero', 'seat-a', '1,0', ['Gust of Wind'], ['isHero' => true]),
                vanilla_unit('b1', 'seat-b', '0,0', ['move' => 3, 'hp' => 5]),
                vanilla_unit('b2', 'seat-b', '4,0'),
            ]);
            $gust = ['unitId' => 'a-hero', 'ability' => 'Gust of Wind', 'targetUnitId' => 'b1'];
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a-hero', 'to' => '2,0'], [6]);
            expect_game_error('gustNeedsStillness', static fn() => vanilla_command($moved['state'], 'seat-a', 'useAbility', $gust + ['targetUnitId' => 'b1']));
            expect_game_error('unitStunned', static fn() => vanilla_command(stun($state, 'a-hero'), 'seat-a', 'useAbility', $gust));
            $edge = vanilla_command($state, 'seat-a', 'useAbility', $gust, [1]);
            expect_same('edge', $edge['events'][0]['payload']['blocked'], 'b1 is already at the edge');
            expect_same(1, $edge['state']['units']['b1']['wounds'], 'and takes a die for it');
            expect_same(true, $edge['state']['turn']['moved'], 'Thalion\'s move is spent');
        },
```

Note the first stillness case: after moving to `2,0`, Thalion is no longer adjacent to `b1`, so the error must come from `unavailable` before target validation — the order in Step 4 guarantees it.

Change the smoke-test deferred list to `['Teleport', 'Flying', 'Lava Walker']`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the two new tests and the smoke test FAIL.

- [ ] **Step 3: Implement**

`TargetedAbility.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;

/** An Ability-type ability: used once per turn by an activated unit, on top of its move and attack. */
abstract class TargetedAbility extends Ability
{
    abstract public function targets(array $state, string $unitId): array;

    abstract public function apply(array $state, string $unitId, string $targetId, RandomSource $random, array &$events): array;

    /** Error code when the unit cannot use this ability right now, or null. */
    public function unavailable(array $state, string $unitId): ?string
    {
        return null;
    }
}
```

`StatBlessing::apply` signature becomes `apply(array $state, string $unitId, string $targetId, RandomSource $random, array &$events): array` (body unchanged; add `use BloodAndBanners\Rules\RandomSource;`).

`GustOfWind.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\Targeting;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

/** Instead of moving, push an adjacent enemy whose Move is above 2 back 2 hexes. */
final class GustOfWind extends TargetedAbility
{
    private const PUSH = 2;

    public function unavailable(array $state, string $unitId): ?string
    {
        if (UnitConditions::any($state, $unitId, static fn(Condition $condition): bool => $condition->blocksMovement())) {
            return 'unitStunned';
        }
        $turn = $state['turn'];
        if (!in_array($unitId, $turn['activated'], true)) {
            return null;
        }
        $still = $turn['activeUnitId'] === $unitId && !$turn['moved'] && $turn['moveSpent'] === 0 && $turn['attacks'] === 0;
        return $still ? null : 'gustNeedsStillness';
    }

    public function targets(array $state, string $unitId): array
    {
        $seatId = $state['units'][$unitId]['seatId'];
        return array_values(array_filter(
            Targeting::unitsWithin($state, $unitId, 1, false),
            static fn(string $id): bool => $state['units'][$id]['seatId'] !== $seatId && (int)$state['units'][$id]['move'] > 2
        ));
    }

    public function apply(array $state, string $unitId, string $targetId, RandomSource $random, array &$events): array
    {
        $state['turn']['moved'] = true;
        $direction = Forced::directionFrom($state['units'][$unitId]['hex'], $state['units'][$targetId]['hex']);
        return Forced::push($state, $targetId, $direction, self::PUSH, $unitId, $random, $events);
    }
}
```

Register `new GustOfWind('Gust of Wind'),`.

- [ ] **Step 4: Thread randomness and availability through BattleRules**

- Message `'gustNeedsStillness' => 'Gust of Wind must be used instead of moving.',`.
- Dispatch: `$state = $this->useAbility($state, $seatId, $payload, $random, $events);`
- `useAbility(array $state, string $seatId, array $payload, RandomSource $random, array &$events)`: after the `abilityAlreadyUsed` check add

```php
        $blocked = $ability->unavailable($state, $unitId);
        if ($blocked !== null) {
            self::fail($blocked);
        }
```

  call `$ability->apply($state, $unitId, $targetId, $random, $events)`, and make the event focus null-safe:

```php
        $events[] = MatchEngine::event('abilityUsed', array_values(array_filter([$unit['hex'], $state['units'][$targetId]['hex']])), [
```

- Legal actions: in the `useAbility` listing loop, also skip when `$ability->unavailable($state, $unitId) !== null`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api
git commit -m "Add Gust of Wind"
```

---

### Task 5: Teleport

**Files:**
- Create: `api/src/Rules/Vanilla/Abilities/Teleport.php`
- Modify: `SpecialAttack.php`, `FixedStrike.php`, `ChaosStrike.php`, `Fireball.php`, `MysticShield.php`, `ChainLightning.php`, `AbilityRegistry.php`, `api/src/Rules/Vanilla/StepRunner.php`, `BattleRules.php`
- Test: `api/tests/VanillaForcedTest.php`, `api/tests/VanillaAbilityTest.php`

**Interfaces:**
- Consumes: `Forced::teleportDestinations`, `Targeting::alliesWithin`.
- Produces:
  - `SpecialAttack::validate(array $state, string $unitId, array $targetIds, array $options = []): ?string`, `steps(array $state, string $unitId, array $targetIds, array $options = []): array` — every subclass gains the trailing `array $options = []` parameter.
  - `specialAttack` payload option `toHex`; error `invalidDestination`.
  - `StepRunner::teleport(string $casterId, string $unitId, string $toHex): array`; event `unitTeleported {unitId, casterId, from, to}` (focus `[from, to]`).

- [ ] **Step 1: Write the failing tests**

```php
        'Teleport moves a friendly unit (or Malachar) to a valid hex and uses his attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 2, '4,0' => 0, '5,0' => 0, '6,0' => 0]), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Teleport'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '1,0'),
                vanilla_unit('b1', 'seat-b', '6,0'),
            ]);
            $actions = array_values(array_filter((new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'), static fn(array $a): bool => $a['kind'] === 'specialAttack'));
            expect_same([['kind' => 'specialAttack', 'unitId' => 'a-hero', 'ability' => 'Teleport', 'targetHexes' => ['0,0', '1,0']]], $actions, 'Malachar and his ally can be moved');
            $payload = static fn(string $who, string $to): array => ['unitId' => 'a-hero', 'ability' => 'Teleport', 'targetUnitIds' => [$who], 'toHex' => $to];
            $blink = vanilla_command($state, 'seat-a', 'specialAttack', $payload('a2', '3,0'));
            expect_same(['unitId' => 'a2', 'casterId' => 'a-hero', 'from' => '1,0', 'to' => '3,0'], $blink['events'][0]['payload'], 'the blink is reported');
            expect_same('3,0', $blink['state']['units']['a2']['hex'], 'two hexes and two levels cost three');
            expect_same(null, $blink['state']['turn']['activeUnitId'], 'the spell was Malachar\'s attack');
            expect_game_error('invalidDestination', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('a2', '5,0')));
            expect_game_error('invalidDestination', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('a2', '6,0')));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('b1', '2,0')));
            $self = vanilla_command($state, 'seat-a', 'specialAttack', $payload('a-hero', '2,0'));
            expect_same('2,0', $self['state']['units']['a-hero']['hex'], 'Malachar may teleport himself');
            $locked = $state;
            $locked['units']['a-hero']['flags']['spellsLockedOnTurns'] = [$state['turn']['number']];
            expect_game_error('abilityLocked', static fn() => vanilla_command($locked, 'seat-a', 'specialAttack', $payload('a2', '2,0')));
        },
```

Trace from `1,0` (elevation 0): `3,0` costs 2 + ⌊2/2⌋ = 3 ✓; `4,0` costs 3 ✓; `5,0` costs 4 ✗; `6,0` is occupied by `b1` ✗. Legal-action targets are listed in unit-id order (`a-hero` sorts before `a2`).

In `api/tests/VanillaAbilityTest.php`:

- `'the registry only knows implemented abilities'`: replace `'Teleport'` with `'Flying'` in all three places (message `'Flying is deferred'`).
- `'special attacks reject unknown, deferred and locked abilities'`: replace `'Teleport'` with `'Flying'` in the unit's ability list and in the `abilityNotPlayable` line.
- Smoke test deferred list: `['Flying', 'Lava Walker']`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: the Teleport test and the smoke test FAIL.

- [ ] **Step 3: Widen the SpecialAttack signatures**

`SpecialAttack.php`:

```php
    abstract public function steps(array $state, string $unitId, array $targetIds, array $options = []): array;

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
```

Add `, array $options = []` to every `steps(...)` override (`FixedStrike`, `ChaosStrike`, `Fireball`, `MysticShield`, `ChainLightning`) and to `ChainLightning::validate`.

`BattleRules::specialAttack`: build `$options = ['toHex' => self::text($payload, 'toHex')];` and pass it to `$ability->validate($state, $unitId, $targets, $options)` and `$ability->steps($state, $unitId, $targets, $options)`. Add message `'invalidDestination' => 'That unit cannot be teleported there.',`.

- [ ] **Step 4: Implement Teleport**

`Teleport.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla\Abilities;

use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Targeting;

/** Move a friendly unit within 3 hexes (Malachar included) to an empty hex within 3 (2 levels count as 1 hex). */
final class Teleport extends SpecialAttack
{
    private const REACH = 3;

    public function targets(array $state, string $unitId): array
    {
        return array_values(array_filter(
            Targeting::alliesWithin($state, $unitId, self::REACH, true),
            static fn(string $id): bool => Forced::teleportDestinations($state, $id) !== []
        ));
    }

    public function validate(array $state, string $unitId, array $targetIds, array $options = []): ?string
    {
        if (count($targetIds) !== 1 || !in_array($targetIds[0], $this->targets($state, $unitId), true)) {
            return 'invalidAbilityTarget';
        }
        return in_array($options['toHex'] ?? '', Forced::teleportDestinations($state, $targetIds[0]), true) ? null : 'invalidDestination';
    }

    public function steps(array $state, string $unitId, array $targetIds, array $options = []): array
    {
        return [StepRunner::teleport($unitId, $targetIds[0], $options['toHex'])];
    }
}
```

Register `new Teleport('Teleport'),`.

`StepRunner`:

```php
    public static function teleport(string $casterId, string $unitId, string $toHex): array
    {
        return ['op' => 'teleport', 'casterId' => $casterId, 'unitId' => $unitId, 'toHex' => $toHex];
    }
```

`execute()` arm: `'teleport' => [$this->teleportStep($state, $step, $events), [], null],` and:

```php
    private function teleportStep(array $state, array $step, array &$events): array
    {
        $from = $state['units'][$step['unitId']]['hex'];
        if ($from === null) {
            return $state;
        }
        $state['units'][$step['unitId']]['hex'] = $step['toHex'];
        $events[] = MatchEngine::event('unitTeleported', [$from, $step['toHex']], [
            'unitId' => $step['unitId'], 'casterId' => $step['casterId'], 'from' => $from, 'to' => $step['toHex'],
        ]);
        return $state;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api
git commit -m "Add Teleport"
```

---

### Task 6: Project conditions

**Files:**
- Modify: `api/src/Game/GameService.php`
- Test: `api/tests/VanillaMatchServiceTest.php`

**Interfaces:**
- Consumes: `UnitConditions::forUnit`.
- Produces: unit projection key `conditions: [{name, expires}]` where `expires` is `endOfThisTurn` / `endOfNextTurn` (afterTurn on the current / a later turn), or the raw kind (`turnStart`, `turnEnd`, `whileOnTerrain`, `untilCleared`). Key order: `conditions` follows `effects`.

- [ ] **Step 1: Write the failing test**

In `'a drafted match projects units turn state and battle actions'`, change the unit-key contract list to insert `'conditions'` after `'effects'`:

```php
                    'abilities', 'effects', 'conditions', 'effectiveMove', 'effectiveRange', 'effectivePower', 'effectiveDefense'],
```

Append a service test (pattern from `'the defending seat resolves a Moonlight Glare choice…'`):

```php
        'a stunned unit projects its condition and its weakened Power' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $statement = $match['db']->prepare('SELECT state_json FROM game_states WHERE game_id = :game');
            $statement->execute(['game' => $match['gameId']]);
            $state = json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            $victim = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['seatId'] === $match['rival']['seat']))[0];
            $state['conditions'] = [[
                'name' => 'Stunned', 'unitId' => $victim['unitId'], 'sourceUnitId' => null,
                'expires' => ['kind' => 'afterTurn', 'turnNumber' => $state['turn']['number'] + 1], 'data' => [],
            ]];
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')
                ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);

            $view = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $unit = array_values(array_filter($view['units'], static fn(array $unit): bool => $unit['unitId'] === $victim['unitId']))[0];
            expect_same([['name' => 'Stunned', 'expires' => 'endOfNextTurn']], $unit['conditions'], 'the stun is visible to everyone');
            expect_same(max(0, $victim['power'] - 2), $unit['effectivePower'], 'its Power is shown reduced');
        },
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php api/tests/run.php`
Expected: both changed/new service tests FAIL (missing `conditions`).

- [ ] **Step 3: Implement**

In `GameService::unitProjection()` insert after the `'effects' => ...` entry:

```php
            'conditions' => array_map(static fn(array $condition): array => [
                'name' => $condition['name'],
                'expires' => self::conditionExpiry($state, $condition['expires']),
            ], UnitConditions::forUnit($state, $id)),
```

and add:

```php
    private static function conditionExpiry(array $state, array $expires): string
    {
        if ($expires['kind'] === 'afterTurn') {
            return $expires['turnNumber'] === $state['turn']['number'] ? 'endOfThisTurn' : 'endOfNextTurn';
        }
        return (string)$expires['kind'];
    }
```

with `use BloodAndBanners\Rules\Vanilla\UnitConditions;`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php api/tests/run.php`
Expected: `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add api
git commit -m "Project unit conditions"
```

---

### Task 7: Client contract for conditions

**Files:**
- Modify: `src/BloodAndBanners.Contracts/MatchProjection.cs`, `tests/fixtures/game-projection.json`, `tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs`

**Interfaces:**
- Produces: `public sealed record ConditionProjection(string Name, string Expires);` and `UnitProjection` init property `IReadOnlyList<ConditionProjection> Conditions { get; init; } = [];`.

- [ ] **Step 1: Write the failing test**

Add to the fixture's unit object: `"conditions": [{"name": "Stunned", "expires": "endOfNextTurn"}]`. In `GameProjection_fixture_round_trips_with_camel_case_contract`, after the `EffectiveDefense` assertion add:

```csharp
        Assert.Equal(new ConditionProjection("Stunned", "endOfNextTurn"), unit.Conditions.Single());
```

(add `using BloodAndBanners.Contracts;` at the top of the test file if the file uses fully-qualified names; otherwise write `global::BloodAndBanners.Contracts.ConditionProjection`).

- [ ] **Step 2: Run test to verify it fails**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests`
Expected: build error — `ConditionProjection` does not exist.

- [ ] **Step 3: Implement**

In `MatchProjection.cs` after `EffectProjection`:

```csharp
public sealed record ConditionProjection(string Name, string Expires);
```

and in `UnitProjection`'s body: `public IReadOnlyList<ConditionProjection> Conditions { get; init; } = [];`

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.Contracts.Tests` and `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.Contracts tests/fixtures/game-projection.json tests/BloodAndBanners.Contracts.Tests/UnitTest1.cs
git commit -m "Add unit conditions to the client contract"
```

---

### Task 8: Show conditions on the table

**Files:**
- Create: `src/BloodAndBanners.UI/Services/ConditionText.cs`
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Test: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`

**Interfaces:**
- Produces: `ConditionText.Summary(string name): string`, `ConditionText.StatDelta(string name, string stat): int`, `ConditionText.Expiry(string expires): string`. Markup: `[data-testid=selected-unit] [data-condition='Stunned']`, token `.condition-marker`.

- [ ] **Step 1: Write the failing test**

```csharp
    [Fact]
    public void A_stunned_unit_shows_its_condition_on_the_card_token_and_label()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [
                projection.Units[0] with { EffectivePower = 1, Conditions = [new ConditionProjection("Stunned", "endOfNextTurn")] },
                projection.Units[1],
                projection.Units[2],
            ],
        };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));
        cut.Find("[data-hex='1,4']").Click();

        Assert.Equal(
            "Stunned — can't move, no parting strikes, −2 Power, until the end of its next turn",
            cut.Find("[data-testid=selected-unit] [data-condition='Stunned']").TextContent.Trim());
        Assert.Contains("Stunned −2", cut.Find("[data-testid=selected-unit] [data-stat='power']").GetAttribute("title"));
        Assert.Single(cut.FindAll("[data-hex='1,4'] .condition-marker"));
        Assert.Contains("Stunned", cut.Find("[data-hex='1,4']").GetAttribute("aria-label"));
    }
```

The fixture's Ranger (`s1-u3`) has a `moveUnit` legal action, so it stays selectable.

- [ ] **Step 2: Run test to verify it fails**

Run: `dotnet test tests/BloodAndBanners.UI.Tests --filter A_stunned_unit`
Expected: FAIL (no `[data-condition]`).

- [ ] **Step 3: Implement**

`src/BloodAndBanners.UI/Services/ConditionText.cs`:

```csharp
namespace BloodAndBanners.UI.Services;

/// <summary>Player-facing wording for unit conditions; mirrors the server's condition handlers.</summary>
public static class ConditionText
{
    public static string Summary(string name) => name switch
    {
        "Stunned" => "can't move, no parting strikes, −2 Power",
        _ => string.Empty,
    };

    public static int StatDelta(string name, string stat) => (name, stat) switch
    {
        ("Stunned", "power") => -2,
        _ => 0,
    };

    public static string Expiry(string expires) => expires switch
    {
        "endOfThisTurn" => "until the end of this turn",
        "endOfNextTurn" => "until the end of its next turn",
        "turnStart" => "until its caster's next turn",
        "turnEnd" => "until the end of the turn",
        "whileOnTerrain" => "while it stays on this terrain",
        _ => "until cleared",
    };
}
```

In `GameTable.razor`, inside the unit card after the `<p class="unit-stats">…</p>` block:

```razor
                        @foreach (var condition in selected.Conditions)
                        {
                            var summary = ConditionText.Summary(condition.Name);
                            <p class="unit-condition" data-condition="@condition.Name">@condition.Name — @(string.IsNullOrEmpty(summary) ? null : summary + ", ")@ConditionText.Expiry(condition.Expires)</p>
                        }
```

Keep that `<p>` on one line so its trimmed text is exactly the asserted string.

`EffectSources` — append condition reasons:

```csharp
    private static string? EffectSources(UnitProjection unit, string stat)
    {
        var sources = unit.Effects
            .Where(effect => effect.Stat == stat)
            .Select(effect => $"{effect.Name} {(effect.Amount > 0 ? "+" : "")}{effect.Amount} {(effect.Expires == "endOfTurn" ? "until end of turn" : "until your next turn")}")
            .Concat(unit.Conditions
                .Where(condition => ConditionText.StatDelta(condition.Name, stat) != 0)
                .Select(condition => $"{condition.Name} {ConditionText.StatDelta(condition.Name, stat).ToString("+0;−0", System.Globalization.CultureInfo.InvariantCulture)} {ConditionText.Expiry(condition.Expires)}"))
            .ToList();
        return sources.Count == 0 ? null : string.Join("; ", sources);
    }
```

The `"+0;−0"` format uses the Unicode minus `−` so the title reads `Stunned −2 …`.

Token marker — after the debuff marker block:

```razor
                            @if (unit is not null && unit.Conditions.Count > 0)
                            {
                                <!text class="condition-marker" x="@Num(tile.X)" y="@Num(tokenY - radius * 1.05)">💫</!text>
                            }
```

`HexLabel` — before the activation-state `switch`, add:

```csharp
        (unit is null || unit.Conditions.Count == 0 ? string.Empty : ", " + string.Join(", ", unit.Conditions.Select(condition => condition.Name))) +
```

CSS:

```css
.unit-condition { margin: .25rem 0; padding: .25rem .5rem; color: #f6d6a8; background: #3b2a1a; border-left: .2rem solid #e0a35a; font-size: .85rem; }
.condition-marker { font-size: 13px; text-anchor: middle; dominant-baseline: central; pointer-events: none; }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Show unit conditions on the card, token and label"
```

---

### Task 9: Teleport targeting and the Gust preview

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/HexMath.cs`, `src/BloodAndBanners.UI/Components/GameTable.razor`, `GameTable.razor.css`
- Test: `tests/BloodAndBanners.UI.Tests/GameTableTests.cs`, `tests/BloodAndBanners.UI.Tests/BoardLayoutTests.cs`

**Interfaces:**
- Produces: `HexMath.Direction(string from, string to): (int Q, int R)?`, `HexMath.Step(string key, (int Q, int R) direction): string`. Board classes `destination` (Teleport step 2) and `push-path`; banner texts `Teleport: choose a unit` / `Teleport: choose where`; `button[data-action=teleportBack]`.

- [ ] **Step 1: Write the failing tests**

`BoardLayoutTests.cs`:

```csharp
    [Fact]
    public void Hex_math_finds_directions_and_steps()
    {
        Assert.Equal((1, 0), HexMath.Direction("0,0", "1,0"));
        Assert.Null(HexMath.Direction("0,0", "2,0"));
        Assert.Equal("2,-1", HexMath.Step("1,0", (1, -1)));
    }
```

`GameTableTests.cs` — a local fixture with Malachar (`s1-hero`) at `0,0`, an ally at `1,0`, terrain heights and a gap:

```csharp
    private static GameProjection TeleportBoard()
    {
        var hexes = new List<HexProjection>
        {
            new(0, 0, "plains", "grass", 0, false, "s1-hero"),
            new(1, 0, "plains", "grass", 0, false, "s1-u1"),
            new(2, 0, "plains", "grass", 0, false, null),
            new(3, 0, "stone", "crags", 2, false, null),
            new(4, 0, "plains", "grass", 0, false, null),
            new(5, 0, "plains", "grass", 0, false, null),
            new(6, 0, "plains", "grass", 0, false, "s2-u1"),
            new(0, 1, "stone", "crags", 0, true, null),
        };
        UnitProjection Unit(string id, string seat, string hex, bool hero = false) => new(id, seat, hero, id, "", 3, 1, 3, 2, 5, 0, hex, false);
        return BattleFixtures.Projection() with
        {
            Board = new BoardProjection(7, 2, hexes),
            Units = [
                Unit("s1-hero", "seat-red", "0,0", hero: true) with { Abilities = [new AbilityProjection("Teleport", "Attack", "Move a friendly unit") { Playable = true }] },
                Unit("s1-u1", "seat-red", "1,0"),
                Unit("s2-u1", "seat-blue", "6,0"),
            ],
            LegalActions = [new LegalAction("specialAttack", "s1-hero", ["0,0", "1,0"]) { Ability = "Teleport" }, new LegalAction("endTurn", null, [])],
        };
    }

    [Fact]
    public void Teleport_picks_a_unit_then_a_destination_and_sends_both()
    {
        var sent = new List<GameCommandRequest>();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, TeleportBoard())
            .Add(component => component.OnCommand, (GameCommandRequest request) => sent.Add(request)));

        cut.Find("[data-hex='0,0']").Click();
        cut.Find("button[data-use-ability='Teleport']").Click();
        Assert.Contains("Teleport: choose a unit", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("ally-target", cut.Find("[data-hex='1,0']").ClassList);

        cut.Find("[data-hex='1,0']").Click();
        Assert.Contains("Teleport: choose where", cut.Find("[data-testid=targeting-banner]").TextContent);
        Assert.Contains("destination", cut.Find("[data-hex='3,0']").ClassList);
        Assert.Contains("destination", cut.Find("[data-hex='4,0']").ClassList);
        Assert.DoesNotContain("destination", cut.Find("[data-hex='5,0']").ClassList);
        Assert.DoesNotContain("destination", cut.Find("[data-hex='0,1']").ClassList);
        Assert.DoesNotContain("destination", cut.Find("[data-hex='6,0']").ClassList);

        cut.Find("button[data-action=teleportBack]").Click();
        Assert.Contains("Teleport: choose a unit", cut.Find("[data-testid=targeting-banner]").TextContent);
        cut.Find("[data-hex='1,0']").Click();
        cut.Find("[data-hex='3,0']").Click();

        var payload = JsonSerializer.SerializeToElement(sent.Single().Payload);
        Assert.Equal("specialAttack", sent.Single().Kind);
        Assert.Equal("Teleport", payload.GetProperty("ability").GetString());
        Assert.Equal("s1-u1", payload.GetProperty("targetUnitIds")[0].GetString());
        Assert.Equal("3,0", payload.GetProperty("toHex").GetString());
    }

    [Fact]
    public void Hovering_a_gust_target_previews_its_push_path()
    {
        var projection = BattleFixtures.Projection();
        projection = projection with
        {
            Units = [projection.Units[0] with { Abilities = [new AbilityProjection("Gust of Wind", "Ability", "Push back") { Playable = true }] }, projection.Units[1], projection.Units[2]],
            LegalActions = [.. projection.LegalActions, new LegalAction("useAbility", "s1-u3", ["2,4"]) { Ability = "Gust of Wind" }],
            Board = projection.Board with
            {
                Hexes = [
                    new HexProjection(1, 4, "plains", "roads", 0, false, "s1-u3"),
                    new HexProjection(2, 4, "plains", "roads", 0, false, "s2-u1"),
                    new HexProjection(3, 4, "plains", "roads", 0, false, null),
                    new HexProjection(4, 4, "stone", "stone-path", 1, false, null),
                ],
            },
        };
        projection = projection with { Units = [projection.Units[0], projection.Units[1] with { Hex = "2,4" }, projection.Units[2]] };
        var cut = Render<GameTable>(parameters => parameters.Add(component => component.Projection, projection));

        cut.Find("[data-hex='1,4']").Click();
        cut.Find("button[data-use-ability='Gust of Wind']").Click();
        cut.Find("[data-hex='2,4']").MouseEnter();

        Assert.Contains("push-path", cut.Find("[data-hex='3,4']").ClassList);
        Assert.Contains("push-path", cut.Find("[data-hex='4,4']").ClassList);
    }
```

Trace (Teleport, ally at `1,0`, elevation 0): `2,0` cost 1 ✓; `3,0` cost 2 + ⌊2/2⌋ = 3 ✓; `4,0` cost 3 ✓; `5,0` cost 4 ✗; `0,1` sight blocker ✗; `6,0` occupied ✗; `0,0` occupied ✗. (Gust: `3,4` is level, `4,4` is 1 level up — both walkable.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: build error (`HexMath.Direction`), then the new tests FAIL.

- [ ] **Step 3: HexMath additions**

```csharp
    public static (int Q, int R)? Direction(string from, string to)
    {
        var (q1, r1) = Parse(from);
        var (q2, r2) = Parse(to);
        var direction = (q2 - q1, r2 - r1);
        return Directions.Contains(direction) ? direction : null;
    }

    public static string Step(string key, (int Q, int R) direction)
    {
        var (q, r) = Parse(key);
        return $"{q + direction.Q},{r + direction.R}";
    }
```

- [ ] **Step 4: GameTable — Teleport two-step and Gust preview**

`@code` additions:

```csharp
    private const int TeleportReach = 3;
    private string? _teleportUnitId;

    private bool Teleporting => _targeting?.Ability == "Teleport";

    /// <summary>Same rule as the server's Forced::teleportDestinations.</summary>
    private IReadOnlyList<string> TeleportDestinations(string unitId)
    {
        var origin = UnitById(unitId)?.Hex;
        var start = Projection.Board.Hexes.FirstOrDefault(hex => HexKey(hex) == origin);
        if (origin is null || start is null)
        {
            return [];
        }
        return Projection.Board.Hexes
            .Where(hex => !hex.SightBlocker && hex.OccupantId is null && HexKey(hex) != origin)
            .Where(hex => HexMath.Distance(origin, HexKey(hex)) + Math.Abs(hex.Elevation - start.Elevation) / 2 <= TeleportReach)
            .Select(HexKey)
            .ToList();
    }

    private IReadOnlyList<string> PushPreview
    {
        get
        {
            if (_targeting?.Ability != "Gust of Wind" || _hoveredHex is not { } hovered || !_targeting.TargetHexes.Contains(hovered)
                || SelectedHex is not { } casterHex || HexMath.Direction(casterHex, hovered) is not { } direction)
            {
                return [];
            }
            var path = new List<string>();
            var current = Projection.Board.Hexes.First(hex => HexKey(hex) == hovered);
            for (var step = 0; step < 2; step++)
            {
                var nextKey = HexMath.Step(HexKey(current), direction);
                var next = Projection.Board.Hexes.FirstOrDefault(hex => HexKey(hex) == nextKey);
                if (next is null || next.SightBlocker || next.OccupantId is not null || next.Elevation - current.Elevation >= 2)
                {
                    break;
                }
                path.Add(nextKey);
                current = next;
            }
            return path;
        }
    }
```

`CancelTargeting()` also clears `_teleportUnitId`.

`SelectHex()` targeting block — handle Teleport before the chain branch:

```csharp
            if (Teleporting)
            {
                if (_teleportUnitId is { } traveller && TeleportDestinations(traveller).Contains(aimed))
                {
                    CancelTargeting();
                    await Send("specialAttack", new { unitId = caster, ability = "Teleport", targetUnitIds = new[] { traveller }, toHex = aimed });
                }
                else if (aiming.TargetHexes.Contains(aimed) && hex.OccupantId is { } candidate)
                {
                    _teleportUnitId = candidate;
                }
                return;
            }
```

`HexState()` targeting branch — replace its first two lines with:

```csharp
        if (_targeting is { } aiming)
        {
            if (Teleporting && _teleportUnitId is { } traveller)
            {
                return TeleportDestinations(traveller).Contains(key) ? "destination" : null;
            }
            var state = (Chaining ? ChainCandidates() : aiming.TargetHexes).Contains(key) ? (TargetsAlly(key) ? "ally-target" : "ability-target") : null;
            if (PushPreview.Contains(key))
            {
                return $"{state} push-path".Trim();
            }
            return SplashHexes.Contains(key) ? $"{state} splash".Trim() : state;
        }
```

Note: `HexState` returns `"selected"` for the caster's own hex before reaching this branch, so Malachar's self-teleport candidate is the selected hex; clicking it still works because `SelectHex` checks `aiming.TargetHexes`.

Banner — replace `<span>@aiming.Ability: choose a target</span>` with:

```razor
                <span>@BannerText(aiming)</span>
                @if (Teleporting && _teleportUnitId is not null)
                {
                    <button type="button" data-action="teleportBack" @onclick="() => _teleportUnitId = null">Back</button>
                }
```

and add

```csharp
    private string BannerText(Targeting aiming) => Teleporting
        ? (_teleportUnitId is null ? "Teleport: choose a unit" : "Teleport: choose where")
        : $"{aiming.Ability}: choose a target";
```

Push-path marker — inside each hex `<g>`, next to the chain mark:

```razor
                    @if (PushPreview.Contains(key))
                    {
                        <!text class="push-mark" x="@Num(tile.X)" y="@Num(tile.Y)">»</!text>
                    }
```

CSS:

```css
.hex.destination .hex-outline { fill: rgba(120, 170, 255, .3); stroke: #7aa8ff; stroke-width: 3; }
.hex.push-path .hex-outline { fill: rgba(156, 195, 223, .25); stroke: #9cc3df; stroke-dasharray: 4 3; stroke-width: 2.5; }
.push-mark { fill: #e8f2fa; font: 700 20px Georgia, serif; text-anchor: middle; dominant-baseline: central; paint-order: stroke; stroke: #1f201d; stroke-width: 3; pointer-events: none; }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `dotnet test tests/BloodAndBanners.UI.Tests`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/BloodAndBanners.UI tests/BloodAndBanners.UI.Tests
git commit -m "Add two-step Teleport targeting and a Gust of Wind push preview"
```

---

### Task 10: Combat log lines and README

**Files:**
- Modify: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`, `README.md`
- Test: `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`

**Interfaces:**
- Consumes: event payloads from Tasks 1–5.

- [ ] **Step 1: Write the failing tests**

```csharp
    [Theory]
    [InlineData("saveRolled", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","ability":"Knockback","roll":14,"saved":true}""", "Grunt resists Knockback (rolled 14).")]
    [InlineData("saveRolled", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","ability":"Stunning Blow","roll":3,"saved":false}""", "Grunt fails to resist Stunning Blow (rolled 3).")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"4,4","path":["2,4","3,4","4,4"],"collisions":[],"falls":[],"blocked":null}""", "Grunt is driven back to 4,4.")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"2,4","path":["2,4"],"collisions":[{"unitId":"s2-u1","faces":["sword"],"wounds":1},{"unitId":"s2-u2","faces":["shield"],"wounds":0}],"falls":[],"blocked":"unit"}""", "Grunt is driven back but slams into Brute. Grunt: 1 wound. Brute: 0 wounds.")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"2,4","path":["2,4"],"collisions":[{"unitId":"s2-u1","faces":["blank"],"wounds":0}],"falls":[],"blocked":"edge"}""", "Grunt is driven back but hits the edge of the battlefield. Grunt: 0 wounds.")]
    [InlineData("unitPushed", """{"unitId":"s2-u1","sourceUnitId":"s1-u3","from":"2,4","to":"3,4","path":["2,4","3,4"],"collisions":[],"falls":[{"levels":2,"faces":["sword","blank"],"wounds":1}],"blocked":null}""", "Grunt is driven back to 3,4. It falls 2 levels: 1 wound.")]
    [InlineData("conditionApplied", """{"name":"Stunned","unitId":"s2-u1","sourceUnitId":"s1-u3","expires":{"kind":"afterTurn","turnNumber":2}}""", "Grunt is stunned.")]
    [InlineData("conditionExpired", """{"name":"Stunned","unitId":"s2-u1"}""", "Grunt is no longer stunned.")]
    [InlineData("unitTeleported", """{"unitId":"s2-u1","casterId":"s1-u3","from":"2,4","to":"5,4"}""", "Ranger teleports Grunt to 5,4.")]
    [InlineData("unitTeleported", """{"unitId":"s1-u3","casterId":"s1-u3","from":"1,4","to":"3,4"}""", "Ranger teleports to 3,4.")]
    public void Forced_movement_and_condition_events_have_readable_lines(string kind, string payload, string expected)
    {
        Assert.Equal(expected, CombatLogFormatter.Describe(Event(kind, payload), BattleFixtures.Projection()));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test tests/BloodAndBanners.UI.Tests --filter Forced_movement_and_condition`
Expected: FAIL (null lines).

- [ ] **Step 3: Implement**

Add arms to `DescribeCore()`:

```csharp
            "saveRolled" => payload.GetProperty("saved").GetBoolean()
                ? $"{Unit(projection, Text(payload, "unitId"))} resists {Text(payload, "ability")} (rolled {payload.GetProperty("roll").GetInt32()})."
                : $"{Unit(projection, Text(payload, "unitId"))} fails to resist {Text(payload, "ability")} (rolled {payload.GetProperty("roll").GetInt32()}).",
            "unitPushed" => Pushed(projection, payload),
            "conditionApplied" => $"{Unit(projection, Text(payload, "unitId"))} is {Text(payload, "name").ToLowerInvariant()}.",
            "conditionExpired" => $"{Unit(projection, Text(payload, "unitId"))} is no longer {Text(payload, "name").ToLowerInvariant()}.",
            "unitTeleported" => Text(payload, "unitId") == Text(payload, "casterId")
                ? $"{Unit(projection, Text(payload, "casterId"))} teleports to {Text(payload, "to")}."
                : $"{Unit(projection, Text(payload, "casterId"))} teleports {Unit(projection, Text(payload, "unitId"))} to {Text(payload, "to")}.",
```

and the helper:

```csharp
    private static string Pushed(GameProjection projection, JsonElement payload)
    {
        var name = Unit(projection, Text(payload, "unitId"));
        var collisions = payload.GetProperty("collisions").EnumerateArray().ToList();
        var blocked = payload.GetProperty("blocked").ValueKind == JsonValueKind.String ? payload.GetProperty("blocked").GetString() : null;
        var line = blocked switch
        {
            "unit" => $"{name} is driven back but slams into {Unit(projection, Text(collisions[1], "unitId"))}.",
            "wall" => $"{name} is driven back but hits a wall.",
            "edge" => $"{name} is driven back but hits the edge of the battlefield.",
            _ => $"{name} is driven back to {Text(payload, "to")}.",
        };
        foreach (var fall in payload.GetProperty("falls").EnumerateArray())
        {
            line += $" It falls {fall.GetProperty("levels").GetInt32()} levels: {Wounds(fall.GetProperty("wounds").GetInt32())}.";
        }
        foreach (var hit in collisions)
        {
            line += $" {Unit(projection, Text(hit, "unitId"))}: {Wounds(hit.GetProperty("wounds").GetInt32())}.";
        }
        return line;
    }
```

(If a push moved the unit and then hit something, the `blocked` wording applies; the destination is still visible on the board.)

- [ ] **Step 4: README**

In `README.md` under "### Try the abilities", replace step 5 with:

```markdown
5. Pick **Deepstone Guard** or **Sellsword Marauders** to see **Knockback** and **Stunning Blow**: after a hit the target rolls a save, and a failed save drives it back (watch for collisions and falls) or stuns it (💫 on the token; it can't move until the end of its next turn). **Thalion Windrider**'s **Gust of Wind** blows an adjacent enemy two hexes back — hover the target to preview the path. **Malachar**'s **Teleport** is two clicks: the unit, then where it goes.
6. Only **Flying** and **Lava Walker** still show "Not yet in the digital game".
```

- [ ] **Step 5: Run the whole suite**

Run: `php api/tests/run.php`, `dotnet test tests/BloodAndBanners.UI.Tests`, `dotnet test tests/BloodAndBanners.Contracts.Tests`
Expected: all report 0 failures.

- [ ] **Step 6: Commit**

```bash
git add src/BloodAndBanners.UI/Services/CombatLogFormatter.cs tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs README.md
git commit -m "Describe pushes, saves, stuns and teleports in the battle log"
```
