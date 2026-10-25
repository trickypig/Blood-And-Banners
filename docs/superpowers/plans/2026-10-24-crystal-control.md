# Crystal Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crystals are claimed by finishing a move next to them and give their owner's units stacking combat bonuses; the battlefield and log show ownership; the static rule book documents map objects and crystals.

**Architecture:** Engine board tiles keep the hex `object`; state gains `crystals` (hex → seat). A new `Crystals` class claims at the end of the move command (and when a paused ice slide of that move resolves) and contributes stat sources to `Stats::breakdown`. The map projection adds `controlledBy`, drawn as a seat-coloured ring and logged via `crystalClaimed`.

**Tech Stack:** PHP rules engine (`php api/tests/run.php`), .NET 10 Blazor UI + Contracts (`dotnet test tests/BloodAndBanners.UI.Tests`, `dotnet test tests/BloodAndBanners.Contracts.Tests`), static `rulebook.html` + `data/gameData.json`.

**Spec:** `docs/superpowers/specs/2026-10-24-crystal-control-design.md`

## Global Constraints

- Crystal kinds: `fire-crystal` (Fire Crystal), `forest-crystal` (Forest Crystal), `ice-crystal` (Ice Crystal).
- Bonuses: Fire +2 Power attacking a Forest-biome creature; Forest +4 Defense defending against a water attack; Ice +1 Power attacking a Lava-biome creature and +2 Defense defending against a fire attack. Each owned crystal adds its bonus (stacking).
- Water attack: attacker biome `River`, or ability `Water Twister` / `Aquatic Surge`. Fire attack: attacker biome `Lava`, or `damageType` `fire`, or ability in Fireball, Flame Strike, Dragon Breath, Inferno, Lava Burst, Dragon's Breath.
- Claim only at the end of the `moveUnit` command (including a paused ice slide of that move when it resolves). Never from pushes, teleports, Sky Toss, Mycelium Network or start-of-activation slides.
- Claim needs: claiming unit not engaged (no enemy adjacent within 1 elevation) and no enemy of the claimer adjacent to the crystal. Void side units never claim. Re-claiming your own crystal does nothing and logs nothing. Ownership is per seat and persists.
- No migration for games in progress.

## Review Focus

1. A move that ends with a pending ice-collision choice must not claim until the choice resolves, and then claim from the final hex — pinned in Task 1 (claim only when `pending === null`; the resolveChoice hook is covered by a test with the pending state built by hand).
2. A crystal owned by an ally in Co-op is taken over when the other hero player claims it — pinned in Task 1.
3. Bonuses must not apply to a unit whose seat doesn't own the crystal, or outside an attack (plain stat display with empty context) — pinned in Task 2.
4. Reflected damage swaps attacker/defender in the defense context; crystal defense must follow the swapped context, not crash — covered by Task 2's context-driven design (no extra test; reviewer check).
5. The viewer's own claim reads "You claim …", not "You claims …" — pinned in Task 3.

---

### Task 1: Claiming crystals

**Files:**
- Create: `api/src/Rules/Vanilla/Crystals.php`
- Modify: `api/src/Rules/Vanilla/Board.php` (`fromScenario` keeps `object`)
- Modify: `api/src/Rules/Vanilla/BattleRules.php` (end of the move command, ~line 343 `return self::closeIfSpent($state, $unitId);`; and the `iceCollision` branch of `resolveChoice`, ~line 611)
- Create: `api/tests/CrystalTest.php`; Modify: `api/tests/run.php` (require + merge `crystal_tests()`)
- Modify: `api/tests/VanillaGeometryTest.php`, `api/tests/VanillaTerrainTest.php` (exact-board expectations gain `object`)

**Interfaces:**
- Produces: `Crystals::KINDS` (`array<string,string>` kind → display name); `Crystals::claim(array $state, string $unitId, array &$events): array`; board tiles gain `'object' => ?string`; state key `crystals` (`array<string,string>` hex → seatId); event `crystalClaimed` payload `{seatId, unitId, hex, kind, previousSeatId}`.

- [ ] **Step 1: Write the failing tests** — `api/tests/CrystalTest.php`:

```php
<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Crystals;

/** A nine-hex line with a crystal on 5,0; $units placed as given. */
function crystal_battle(array $units, string $kind = 'fire-crystal', bool $coop = false): array
{
    $state = $coop ? coop_battle($units) : vanilla_battle(vanilla_board(vanilla_line(9)), $units);
    $state['board']['5,0']['object'] = $kind;
    $state['board']['5,0']['impassable'] = true;
    return $state;
}

function crystal_events(array $events): array
{
    return array_values(array_filter($events, static fn(array $event): bool => $event['kind'] === 'crystalClaimed'));
}

function crystal_tests(): array
{
    return [
        'ending a move next to a crystal claims it for the mover' => static function (): void {
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '1,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '8,0')]);
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '4,0']);
            expect_same(['5,0' => 'seat-a'], $moved['state']['crystals'], 'seat-a owns the crystal');
            $claimed = crystal_events($moved['events']);
            expect_same(1, count($claimed), 'one claim event');
            expect_same(['seatId' => 'seat-a', 'unitId' => 'a1', 'hex' => '5,0', 'kind' => 'fire-crystal', 'previousSeatId' => null], $claimed[0]['payload'], 'claim payload');
        },

        'an engaged unit cannot claim' => static function (): void {
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '4,0'), vanilla_unit('b1', 'seat-b', '3,0')]);
            $events = [];
            $after = Crystals::claim($state, 'a1', $events);
            expect_same([], $after['crystals'] ?? [], 'ending next to an enemy blocks the claim');
            expect_same([], $events, 'no claim event');
        },

        'an enemy next to the crystal blocks the claim' => static function (): void {
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '1,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '6,0')]);
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '4,0']);
            expect_same([], $moved['state']['crystals'] ?? [], 'b1 guards the crystal');
        },

        'claiming takes a crystal from its owner and re-claiming your own does nothing' => static function (): void {
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '1,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '8,0')]);
            $state['crystals'] = ['5,0' => 'seat-b'];
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '4,0']);
            expect_same(['5,0' => 'seat-a'], $moved['state']['crystals'], 'taken from seat-b');
            expect_same('seat-b', crystal_events($moved['events'])[0]['payload']['previousSeatId'], 'previous owner logged');

            $state['crystals'] = ['5,0' => 'seat-a'];
            $again = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '4,0']);
            expect_same([], crystal_events($again['events']), 'no event for a crystal you already own');
        },

        'in co-op a hero player takes a crystal from an ally and the void lord never claims' => static function (): void {
            $state = crystal_battle([
                coop_hero('h1', 'hero-a', '1,0', ['move' => 3]),
                vanilla_unit('m1', 'void-lord', '8,0'),
            ], 'ice-crystal', true);
            $state['crystals'] = ['5,0' => 'hero-b'];
            $moved = vanilla_command($state, 'hero-a', 'moveUnit', ['unitId' => 'h1', 'to' => '4,0']);
            expect_same(['5,0' => 'hero-a'], $moved['state']['crystals'], 'per-player ownership: the ally loses it');

            $void = crystal_battle([
                coop_hero('h1', 'hero-a', '0,0'),
                vanilla_unit('m1', 'void-lord', '8,0', ['move' => 2]),
            ], 'ice-crystal', true);
            $void['turn'] = \BloodAndBanners\Rules\Vanilla\Turn::fresh($void, 'void-lord', 1);
            $events = [];
            $void['units']['m1']['hex'] = '6,0';
            $after = Crystals::claim($void, 'm1', $events);
            expect_same([], $after['crystals'] ?? [], 'the void lord cannot claim');
            expect_same([], $events, 'no claim event for the void lord');
        },

        'claim checks every adjacent crystal and ignores other objects' => static function (): void {
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '4,0'), vanilla_unit('b1', 'seat-b', '8,0')]);
            $state['board']['3,0']['object'] = 'forest-crystal';
            $state['board']['3,0']['impassable'] = true;
            $state['board']['5,0']['object'] = 'rune-of-magic';
            $events = [];
            $after = Crystals::claim($state, 'a1', $events);
            expect_same(['3,0' => 'seat-a'], $after['crystals'], 'only the crystal is claimed');
        },

        'a move that pauses on an ice choice claims once the choice resolves' => static function (): void {
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '4,0'), vanilla_unit('b1', 'seat-b', '8,0')]);
            $state['turn']['activeUnitId'] = 'a1';
            $state['turn']['activated'][] = 'a1';
            $state['pending'] = [
                'choice' => ['choiceId' => 'c1', 'kind' => 'iceCollision', 'seatId' => 'seat-a', 'unitId' => 'a1', 'options' => ['stop']],
                'context' => ['currentHex' => '4,0', 'direction' => [1, 0], 'remainingMove' => 0, 'path' => [], 'from' => '4,0', 'blockerUnitId' => 'b1'],
            ];
            $resolved = vanilla_command($state, 'seat-a', 'resolveChoice', ['choiceId' => 'c1', 'option' => 'stop']);
            expect_same(['5,0' => 'seat-a'], $resolved['state']['crystals'] ?? [], 'the claim waits for the slide to end');
        },

        'board tiles keep their map object' => static function (): void {
            $board = \BloodAndBanners\Rules\Vanilla\Board::fromScenario(['map' => ['hexes' => [
                ['q' => 0, 'r' => 0, 'biome' => 'plains', 'terrain' => 'grass', 'elevation' => 0, 'object' => 'ice-crystal', 'sightBlocker' => false],
            ]]]);
            expect_same('ice-crystal', $board['0,0']['object'], 'object kept');
        },
    ];
}
```

The ice-choice test depends on `IceMovement::resolveChoice` accepting the hand-built context; read `IceMovement::resolveChoice` (~line 125) for the exact context keys and the `stop` option name before writing it, and adjust the pending shape to what it requires (unit must be `Slipping` — add `$state['conditions'][] = ['unitId' => 'a1', 'name' => 'Slipping', 'data' => ['direction' => [1, 0]]]` in the shape `UnitConditions` stores). If a valid hand-built pending state is not reachable in under ~20 lines, replace this test with one driving a real slide: an ice tile at `3,0`..`4,0` from an existing ice test in `api/tests/VanillaIceTest.php` (copy its setup), with the crystal on the far side.

Add to `api/tests/run.php`: `require __DIR__ . '/CrystalTest.php';` after `VanillaIceTest.php`, and `crystal_tests()` to the `array_merge` list.

- [ ] **Step 2: Run to verify failure**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: the new tests FAIL (class `Crystals` not found / `crystals` key missing).

- [ ] **Step 3: Implement** — `api/src/Rules/Vanilla/Crystals.php`:

```php
<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

/**
 * Crystal landmarks. A unit that finishes its own move next to one, unengaged and with no enemy beside the crystal,
 * claims it for its player; the owner's units get the crystal's combat bonus until someone else claims it.
 */
final class Crystals
{
    public const KINDS = ['fire-crystal' => 'Fire Crystal', 'forest-crystal' => 'Forest Crystal', 'ice-crystal' => 'Ice Crystal'];

    /** Claims every crystal next to the unit that it may claim. Call only when the unit's own move has ended. */
    public static function claim(array $state, string $unitId, array &$events): array
    {
        $unit = $state['units'][$unitId];
        if ($unit['hex'] === null
            || ($state['sides'][Sides::ofUnit($state, $unitId)]['kind'] ?? null) === 'void'
            || self::engaged($state, $unitId)) {
            return $state;
        }
        $seatId = (string)$unit['seatId'];
        foreach (Hex::neighbors($unit['hex'], $state['board']) as $hex) {
            $kind = $state['board'][$hex]['object'] ?? null;
            if ($kind === null || !isset(self::KINDS[$kind]) || self::guarded($state, $unitId, $hex)) {
                continue;
            }
            $previous = $state['crystals'][$hex] ?? null;
            if ($previous === $seatId) {
                continue;
            }
            $state['crystals'][$hex] = $seatId;
            $events[] = MatchEngine::event('crystalClaimed', [$hex], [
                'seatId' => $seatId, 'unitId' => $unitId, 'hex' => $hex, 'kind' => $kind, 'previousSeatId' => $previous,
            ]);
        }
        return $state;
    }

    /** An enemy stands next to the unit within one level of height. */
    private static function engaged(array $state, string $unitId): bool
    {
        $here = $state['units'][$unitId]['hex'];
        foreach ($state['units'] as $otherId => $other) {
            if ($other['hex'] !== null
                && Sides::enemies($state, $unitId, (string)$otherId)
                && Hex::distance($here, $other['hex']) === 1
                && abs($state['board'][$here]['elevation'] - $state['board'][$other['hex']]['elevation']) <= 1) {
                return true;
            }
        }
        return false;
    }

    /** An enemy of the unit stands next to the crystal. */
    private static function guarded(array $state, string $unitId, string $crystal): bool
    {
        foreach ($state['units'] as $otherId => $other) {
            if ($other['hex'] !== null && Sides::enemies($state, $unitId, (string)$otherId) && Hex::distance($crystal, $other['hex']) === 1) {
                return true;
            }
        }
        return false;
    }
}
```

In `Board::fromScenario` add after `'spawnZone' => ...`:

```php
                'object' => isset($hex['object']) ? (string)$hex['object'] : null,
```

In `BattleRules` move command, replace the final `return self::closeIfSpent($state, $unitId);` (after the `unitMoved` event) with:

```php
        if ($state['pending'] === null) {
            $state = Crystals::claim($state, $unitId, $events);
        }
        return self::closeIfSpent($state, $unitId);
```

In `resolveChoice`'s `iceCollision` branch, right after `$activateAfter = (bool)($resolved['activateAfter'] ?? false);` add:

```php
            // The slide that paused was the unit's own move; now that it has ended, it may claim crystals.
            if (!$activateAfter && $state['pending'] === null && $state['phase'] === 'active' && $state['units'][$choice['unitId']]['hex'] !== null) {
                $state = Crystals::claim($state, $choice['unitId'], $events);
            }
```

- [ ] **Step 4: Fix exact-board expectations** — the board now carries `object`:
  - `api/tests/VanillaGeometryTest.php`: the `-1,5` expectation gains `'object' => null` and the `0,3` statue expectation gains `'object' => 'statue'` (insert after `'spawnZone' => null`).
  - `api/tests/VanillaTerrainTest.php` 'engine boards retain painted terrain…': expected array gains `'object' => null` after `'spawnZone' => null`.

- [ ] **Step 5: Run all PHP tests**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `N tests, 0 failures`.

- [ ] **Step 6: Commit**

```bash
git add api
git commit -m "Claim crystals by finishing a move next to them"
```

---

### Task 2: Crystal bonuses

**Files:**
- Modify: `api/src/Rules/Vanilla/Crystals.php` (add `statSources`)
- Modify: `api/src/Rules/Vanilla/Stats.php` (call it after `Terrain::statSources`)
- Test: `api/tests/CrystalTest.php`

**Interfaces:**
- Consumes: `Crystals::KINDS`, state `crystals`, board `object` (Task 1).
- Produces: `Crystals::statSources(array $state, string $unitId, string $stat, array $context): array` → `list<array{source:string, amount:int}>`.

- [ ] **Step 1: Write the failing tests** — append to `crystal_tests()`:

```php
        'crystal bonuses apply to the owner in the matching attack and stack' => static function (): void {
            $state = crystal_battle([
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('f1', 'seat-b', '1,0', ['biome' => 'Forest']),
                vanilla_unit('r1', 'seat-b', '2,0', ['biome' => 'River']),
                vanilla_unit('l1', 'seat-b', '3,0', ['biome' => 'Lava']),
                vanilla_unit('x1', 'seat-b', '4,0'),
            ]);
            $state['board']['6,0']['object'] = 'fire-crystal';
            $state['board']['7,0']['object'] = 'forest-crystal';
            $state['board']['8,0']['object'] = 'ice-crystal';
            $power = static fn(array $s, string $target): int => \BloodAndBanners\Rules\Vanilla\Stats::effective($s, 'a1', 'power', ['attackerId' => 'a1', 'defenderId' => $target]);
            $defense = static fn(array $s, string $attacker, array $extra = []): int => \BloodAndBanners\Rules\Vanilla\Stats::effective($s, 'a1', 'defense', ['attackerId' => $attacker, 'defenderId' => 'a1'] + $extra);
            $basePower = $power($state, 'x1');
            $baseDefense = $defense($state, 'x1');
            expect_same($basePower, $power($state, 'f1'), 'no bonus without owning a crystal');

            $state['crystals'] = ['5,0' => 'seat-a', '6,0' => 'seat-a', '7,0' => 'seat-a', '8,0' => 'seat-a'];
            expect_same($basePower + 4, $power($state, 'f1'), 'two fire crystals stack: +4 power vs Forest');
            expect_same($basePower, $power($state, 'x1'), 'no fire bonus vs a biome-less unit');
            expect_same($basePower + 1, $power($state, 'l1'), 'ice: +1 power vs Lava');
            expect_same($baseDefense + 4, $defense($state, 'r1'), 'forest: +4 defense vs a River attacker');
            expect_same($baseDefense + 4, $defense($state, 'x1', ['ability' => 'Water Twister']), 'forest: +4 vs Water Twister');
            expect_same($baseDefense + 2, $defense($state, 'l1'), 'ice: +2 defense vs a Lava attacker');
            expect_same($baseDefense + 2, $defense($state, 'x1', ['damageType' => 'fire']), 'ice: +2 vs fire damage');
            expect_same($baseDefense + 2, $defense($state, 'x1', ['ability' => 'Lava Burst']), 'ice: +2 vs a named fire ability');
            expect_same($baseDefense, $defense($state, 'x1'), 'no defense bonus vs an ordinary attack');

            $sources = \BloodAndBanners\Rules\Vanilla\Stats::breakdown($state, 'a1', 'power', ['attackerId' => 'a1', 'defenderId' => 'f1'])['modifiers'];
            expect_same([['source' => 'Fire Crystal', 'amount' => 2], ['source' => 'Fire Crystal', 'amount' => 2]], $sources, 'each crystal is a named source');
            expect_same((int)$state['units']['a1']['power'], \BloodAndBanners\Rules\Vanilla\Stats::effective($state, 'a1', 'power'), 'no bonus outside an attack');
            expect_same($basePower, \BloodAndBanners\Rules\Vanilla\Stats::effective($state, 'f1', 'power', ['attackerId' => 'f1', 'defenderId' => 'a1']), 'other seats get nothing');
        },
```

- [ ] **Step 2: Run to verify failure**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: FAIL on 'crystal bonuses apply…' (`+4 power` assertion).

- [ ] **Step 3: Implement** — add to `Crystals`:

```php
    private const WATER_ABILITIES = ['Water Twister', 'Aquatic Surge'];
    private const FIRE_ABILITIES = ['Fireball', 'Flame Strike', 'Dragon Breath', 'Inferno', 'Lava Burst', "Dragon's Breath"];

    /**
     * One named source per crystal the unit's player owns whose bonus fits this attack, so owning two of a kind stacks.
     * @return list<array{source: string, amount: int}>
     */
    public static function statSources(array $state, string $unitId, string $stat, array $context): array
    {
        $seatId = $state['units'][$unitId]['seatId'] ?? null;
        $sources = [];
        foreach ($state['crystals'] ?? [] as $hex => $owner) {
            $kind = $state['board'][(string)$hex]['object'] ?? null;
            if ($owner !== $seatId || $kind === null || !isset(self::KINDS[$kind])) {
                continue;
            }
            $amount = self::bonus($state, $kind, $unitId, $stat, $context);
            if ($amount !== 0) {
                $sources[] = ['source' => self::KINDS[$kind], 'amount' => $amount];
            }
        }
        return $sources;
    }

    private static function bonus(array $state, string $kind, string $unitId, string $stat, array $context): int
    {
        $attacking = $stat === 'power' && ($context['attackerId'] ?? null) === $unitId;
        $defending = $stat === 'defense' && ($context['defenderId'] ?? null) === $unitId;
        $foeId = $attacking ? ($context['defenderId'] ?? null) : ($defending ? ($context['attackerId'] ?? null) : null);
        if ($foeId === null || !isset($state['units'][$foeId])) {
            return 0;
        }
        $foeBiome = $state['units'][$foeId]['biome'] ?? null;
        $ability = $context['ability'] ?? null;
        $water = $foeBiome === 'River' || in_array($ability, self::WATER_ABILITIES, true);
        $fire = $foeBiome === 'Lava' || ($context['damageType'] ?? null) === 'fire' || in_array($ability, self::FIRE_ABILITIES, true);
        return match (true) {
            $kind === 'fire-crystal' && $attacking && $foeBiome === 'Forest' => 2,
            $kind === 'forest-crystal' && $defending && $water => 4,
            $kind === 'ice-crystal' && $attacking && $foeBiome === 'Lava' => 1,
            $kind === 'ice-crystal' && $defending && $fire => 2,
            default => 0,
        };
    }
```

In `Stats::breakdown`, after the `Terrain::statSources` loop:

```php
        foreach (Crystals::statSources($state, $unitId, $stat, $context) as $source) {
            $modifiers[] = $source;
        }
```

- [ ] **Step 4: Run all PHP tests**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`
Expected: `N tests, 0 failures`.

- [ ] **Step 5: Commit**

```bash
git add api
git commit -m "Give crystal owners their combat bonuses"
```

---

### Task 3: Projection, battlefield ring and log line

**Files:**
- Modify: `api/src/Game/GameService.php` (~1238 battlefield board + `mapProjection`)
- Modify: `api/tests/VanillaMatchServiceTest.php` (~line 170)
- Modify: `src/BloodAndBanners.Contracts/GameProjection.cs` (`HexProjection`)
- Modify: `src/BloodAndBanners.UI/Components/GameTable.razor` (~184 ring; `HexLabel` ~1854), `GameTable.razor.css`
- Modify: `src/BloodAndBanners.UI/Services/CombatLogFormatter.cs`
- Test: `tests/BloodAndBanners.UI.Tests/MapObjectTests.cs`, `tests/BloodAndBanners.UI.Tests/CombatLogFormatterTests.cs`

**Interfaces:**
- Consumes: state `crystals`, event `crystalClaimed` (Task 1).
- Produces: projection hex field `controlledBy` (`?string`); C# `HexProjection.ControlledBy` (`string?`, init).

- [ ] **Step 1: Write the failing tests**

PHP — in `VanillaMatchServiceTest.php` after the `occupied` assertion (~line 171):

```php
            expect_true(array_key_exists('controlledBy', $occupied[0]) && $occupied[0]['controlledBy'] === null, 'hexes project crystal control, null when unclaimed');
```

UI — append to `MapObjectTests`:

```csharp
    [Fact]
    public void A_claimed_crystal_shows_its_owner()
    {
        var fixture = BattleFixtures.Projection();
        var hexes = fixture.Board.Hexes.Select(hex => (hex.Q, hex.R) == (4, 4) ? hex with { Object = "fire-crystal", Impassable = true, ControlledBy = "seat-blue" } : hex).ToList();
        var cut = Render<GameTable>(parameters => parameters
            .Add(component => component.Projection, fixture with { Board = fixture.Board with { Hexes = hexes } })
            .Add(component => component.ImageBaseUrl, Images));

        var ring = cut.Find("[data-hex='4,4'] .crystal-ring");
        Assert.Equal("seat-blue", ring.GetAttribute("data-controlled-by"));
        Assert.Contains("seat-2", ring.ClassList);
        Assert.Contains("controlled by Rival", cut.Find("[data-hex='4,4']").GetAttribute("aria-label"));
    }
```

Check before writing: the ring must render inside the `[data-hex='…']` group; confirm by reading `GameTable.razor` ~137-190 that `<HexMapObject>` sits inside the element carrying `data-hex` (it does in the editor test path `[data-hex='0,0'] [data-map-object]`; the battlefield test `The_battlefield_draws_each_category_on_its_hex` finds `[data-map-object]` globally — if the battlefield's `data-hex` element does not contain it, find the ring with `cut.Find(".crystal-ring")` instead).

`CombatLogFormatterTests` — add:

```csharp
    [Theory]
    [InlineData("seat-red", null, "ice-crystal", "You claim the Ice Crystal.")]
    [InlineData("seat-blue", "seat-red", "fire-crystal", "Rival claims the Fire Crystal from you.")]
    public void Crystal_claims_name_the_player_and_the_crystal(string seatId, string? previous, string kind, string expected)
    {
        var payload = JsonSerializer.Serialize(new { seatId, unitId = "s1-u3", hex = "4,4", kind, previousSeatId = previous });
        Assert.Equal(expected, CombatLogFormatter.Describe(Event("crystalClaimed", payload), BattleFixtures.Projection()));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"` → FAIL on the `controlledBy` assertion.
Run: `dotnet test tests/BloodAndBanners.UI.Tests --filter "A_claimed_crystal|Crystal_claims"` → build error, `ControlledBy` not defined.

- [ ] **Step 3: Implement**

PHP `GameService`: give `mapProjection` a third parameter `array $crystals = []` and add to each hex after `'object' => …`:

```php
                'controlledBy' => isset($crystals["{$hex['q']},{$hex['r']}"]) ? (string)$crystals["{$hex['q']},{$hex['r']}"] : null,
```

and pass `$state['crystals'] ?? []` from the battlefield caller (~line 1246): `return self::mapProjection($definition, static fn(…) => …, $state['crystals'] ?? []);`. `scenarioBoard` keeps the default.

C# `HexProjection`:

```csharp
    /// <summary>The seat that controls the crystal on this hex, or null when unclaimed or not a crystal.</summary>
    public string? ControlledBy { get; init; }
```

`GameTable.razor`, immediately before `<HexMapObject Tile="tile" … />` (~184):

```razor
                    @if (hex.ControlledBy is { } controller)
                    {
                        <ellipse class="crystal-ring @SeatClass(controller)" data-controlled-by="@controller"
                                 cx="@Num(tile.X)" cy="@Num(tile.Y + HexHalfHeight * .5)" rx="@Num(BoardLayout.Size * .55)" ry="@Num(BoardLayout.Size * .2)" />
                    }
```

`HexLabel`: after the map-object switch line add:

```csharp
        (hex.ControlledBy is { } controller ? $", controlled by {SeatName(controller)}" : string.Empty) +
```

`GameTable.razor.css`:

```css
.crystal-ring { fill: none; stroke-width: 3; pointer-events: none; }
.crystal-ring.seat-1 { stroke: #e2a29a; }
.crystal-ring.seat-2 { stroke: #9cc3df; }
.crystal-ring.seat-3 { stroke: #a8d79b; }
.crystal-ring.seat-4 { stroke: #e6cf7c; }
```

`CombatLogFormatter.DescribeCore` switch — add `"crystalClaimed" => CrystalClaimed(projection, payload),` and:

```csharp
    private static string CrystalClaimed(GameProjection projection, JsonElement payload)
    {
        var seat = Seat(projection, Text(payload, "seatId"));
        var from = payload.TryGetProperty("previousSeatId", out var previous) && previous.ValueKind == JsonValueKind.String
            ? $" from {Object(Seat(projection, previous.GetString()!))}"
            : string.Empty;
        return $"{seat} {(seat == "You" ? "claim" : "claims")} the {MapObjectArt.Display(Text(payload, "kind"))}{from}.";
    }
```

(Add `using BloodAndBanners.UI.Services;` only if `MapObjectArt` isn't already in scope — the formatter lives in that namespace.)

- [ ] **Step 4: Run tests**

Run: `php api/tests/run.php 2>&1 | grep -E "FAIL|tests,"`, `dotnet test tests/BloodAndBanners.UI.Tests`, `dotnet test tests/BloodAndBanners.Contracts.Tests`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add api src tests
git commit -m "Show who controls each crystal on the battlefield and in the log"
```

---

### Task 4: Rule book

**Files:**
- Modify: `rulebook.html` (rules nav ~1144, new section before `terrain-objects-rules` ~1494, rewrite `terrain-objects-rules` ~1494-1513, Terrain Objects tab text ~1559, `displayTerrainObjects` card ~2179, `.object-card` CSS ~655)
- Modify: `data/gameData.json` (`terrainObjects`, ~line 1789)

No automated tests exist for the static pages; verification is a browser look (Step 4).

- [ ] **Step 1: Map Objects section** — insert before `<div class="section">` that holds `<h2 id="terrain-objects-rules">`:

```html
            <div class="section">
                <h2 id="map-objects">Map Objects</h2>
                <div class="rule-item">
                    <p>Each hex holds at most one thing: a starting position or one map object.</p>
                    <ul>
                        <li><strong>Obstacles</strong> (trees, rocks) fill their hex. Nothing may enter or pass through it, flying units included, and they block line of sight.</li>
                        <li><strong>Landmarks</strong> (shrines and crystals) fill their hex. Nothing may enter or pass through it, flying units included, but units can see and attack past them.</li>
                        <li><strong>Nexus Points</strong> (Rune of Magic, Rune of Strength, Rune of Warding) block nothing; a unit may stand on one. Their powers are not yet in play.</li>
                        <li><strong>Items</strong> (Power, Speed and Health Potions) block nothing; a unit may stand on one. Picking them up is not yet in play.</li>
                    </ul>
                </div>
            </div>
```

and add `<li><a href="#map-objects" onclick="selectSubsection(event, 'map-objects')">Map Objects</a></li>` to the rules nav just before the Terrain Objects entry.

- [ ] **Step 2: Rewrite crystal rules** — replace the three `rule-item`s in the `terrain-objects-rules` section with:

```html
                <div class="rule-item">
                    <div class="rule-title">Claiming Crystals</div>
                    <p>Fire, Forest and Ice Crystals are landmarks that can be claimed. When one of your units finishes its move on a hex next to a crystal, it claims that crystal for you if:</p>
                    <ul>
                        <li>the unit is not engaged (no enemy unit next to it within one level of height), and</li>
                        <li>no enemy unit is next to the crystal.</li>
                    </ul>
                    <p>Only a unit's own move claims a crystal; being pushed, teleported or thrown next to one does not. The Void Lord can never claim crystals.</p>
                </div>
                <div class="rule-item">
                    <div class="rule-title">Crystal Benefits</div>
                    <p>Every unit you control gets the benefit of each crystal you control. Benefits stack: two Fire Crystals give +4 Power.</p>
                    <ul>
                        <li><strong>Fire Crystal:</strong> +2 Power when attacking Forest biome creatures</li>
                        <li><strong>Forest Crystal:</strong> +4 Defense when defending against water attacks (attacks by River creatures, Water Twister and Aquatic Surge)</li>
                        <li><strong>Ice Crystal:</strong> +1 Power when attacking Lava biome creatures. +2 Defense when defending against fire attacks (attacks by Lava creatures, and fire attacks such as Fireball, Flame Strike and dragon breath)</li>
                    </ul>
                </div>
                <div class="rule-item">
                    <div class="rule-title">Keeping Control</div>
                    <p>You keep a crystal for the rest of the game, even if your units move away or are defeated, until another player claims it — including an ally in Co-op.</p>
                </div>
```

Replace the Terrain Objects tab `lore-text` paragraph (~1559) with:

```html
                <p class="lore-text">Crystals are landmarks scattered across the battlefield. Finish a unit's move next to one, while that unit is not engaged and no enemy stands beside the crystal, and you claim it. You keep it until another player claims it, and every unit you control gains its benefit.</p>
```

- [ ] **Step 3: Data and cards** — in `data/gameData.json` replace the `terrainObjects` array (4-space indentation, same field names) with:

```json
    "terrainObjects": [
        {
            "name": "Fire Crystal",
            "biome": "Lava",
            "rarity": "rare",
            "image": "/images/landmarks/fire-crystal-1.png",
            "description": "A glowing crystal of pure flame essence",
            "benefit": {
                "name": "Essence of Flame",
                "type": "AttackBonus",
                "value": 2,
                "target": "Forest Creatures",
                "description": "+2 Power when attacking Forest biome creatures"
            }
        },
        {
            "name": "Forest Crystal",
            "biome": "Forest",
            "rarity": "rare",
            "image": "/images/landmarks/forest-crystal-1.png",
            "description": "A living crystal pulsing with nature's energy",
            "benefit": {
                "name": "Essence of Nature",
                "type": "DefenseBonus",
                "value": 4,
                "target": "Water Attacks",
                "description": "+4 Defense when defending against water attacks"
            }
        },
        {
            "name": "Ice Crystal",
            "biome": "Ice",
            "rarity": "rare",
            "image": "/images/landmarks/ice-crystal-1.png",
            "description": "A shard of frozen winter that never thaws",
            "benefit": {
                "name": "Essence of Frost",
                "type": "AttackAndDefenseBonus",
                "value": 1,
                "target": "Lava Creatures and Fire Attacks",
                "description": "+1 Power when attacking Lava biome creatures. +2 Defense when defending against fire attacks"
            }
        }
    ]
```

(Keep whatever follows the array — a closing `}` or a comma — exactly as it was.) Validate: `python -I -c "import json;json.load(open('data/gameData.json',encoding='utf-8'))"`.

In `displayTerrainObjects`, put the image first in the card template:

```js
                objectCard.innerHTML = `
                    ${obj.image ? `<img src="${obj.image}" alt="" class="object-image">` : ''}
                    <div class="object-name">${obj.name}</div>
```

and add CSS next to `.object-card`: `.object-image { display: block; width: 72px; height: 72px; object-fit: contain; margin: 0 auto 8px; }`.

- [ ] **Step 4: Check by eye** — serve the worktree root (`python -I -m http.server <port> --bind 127.0.0.1 --directory <worktree>`), open `/rulebook.html`, confirm the Map Objects section, the rewritten Terrain Objects rules, the nav link, and the Terrain Objects tab cards with crystal images. Stop the server afterwards.

- [ ] **Step 5: Commit**

```bash
git add rulebook.html data/gameData.json
git commit -m "Document map objects and crystal control in the rule book"
```

---

## Deploy notes (not part of this plan's tasks)

The rule book and its data ship with the static site; images already moved in the previous slice must deploy first. The APK needs rebuilding for the client changes.
