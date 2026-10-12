<?php
declare(strict_types=1);

use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\IceMovement;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\TerrainResolver;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class IceSequenceRandom implements RandomSource
{
    public int $rolls = 0;

    public function __construct(private array $values)
    {
    }

    public function roll(int $sides): int
    {
        $this->rolls++;
        if ($this->values === []) {
            throw new RuntimeException('ice test random sequence exhausted');
        }
        $value = array_shift($this->values);
        if ($value < 1 || $value > $sides) {
            throw new RuntimeException("ice test roll {$value} is outside d{$sides}");
        }
        return $value;
    }
}

function ice_paint(array $keys): array
{
    return array_fill_keys($keys, ['biome' => 'ice', 'terrain' => 'ice']);
}

function ice_condition(array $state, string $unitId, array $direction, array &$events): array
{
    $state = UnitConditions::apply($state, 'Unsteady', $unitId, null, ['kind' => 'whileOnTerrain'], $events);
    return UnitConditions::apply(
        $state,
        'Slipping',
        $unitId,
        null,
        ['kind' => 'untilCleared'],
        $events,
        ['direction' => $direction]
    );
}

function ice_named_condition(array $state, string $unitId, string $name): ?array
{
    foreach (UnitConditions::forUnit($state, $unitId) as $condition) {
        if ($condition['name'] === $name) {
            return $condition;
        }
    }
    return null;
}

function vanilla_ice_tests(): array
{
    return [
        'ice entry applies movement-specific conditions and preserves the first direction' => static function (): void {
            $board = vanilla_board(vanilla_line(4), [], ice_paint(['1,0', '2,0', '3,0']));
            $state = vanilla_battle($board, [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 4]),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $events = [];
            $entered = TerrainResolver::enter($state, 'a1', '0,0', '1,0', 'voluntary', [1, 0], new SequenceRandom([]), $events);
            expect_true(UnitConditions::has($entered['state'], 'a1', 'Unsteady'), 'ordinary ice entry applies Unsteady');
            expect_same([1, 0], ice_named_condition($entered['state'], 'a1', 'Slipping')['data']['direction'] ?? null, 'first entry locks its direction');

            $events = [];
            $refreshed = TerrainResolver::enter($entered['state'], 'a1', '1,0', '2,0', 'slip', [0, 1], new SequenceRandom([]), $events);
            expect_same(2, count(UnitConditions::forUnit($refreshed['state'], 'a1')), 'ice conditions refresh instead of stacking');
            expect_same([1, 0], ice_named_condition($refreshed['state'], 'a1', 'Slipping')['data']['direction'] ?? null, 're-entry keeps the original locked direction');

            $events = [];
            $teleported = TerrainResolver::enter($state, 'a1', '0,0', '1,0', 'teleport', null, new SequenceRandom([]), $events);
            expect_true(UnitConditions::has($teleported['state'], 'a1', 'Unsteady'), 'teleport arrival applies Unsteady');
            expect_true(!UnitConditions::has($teleported['state'], 'a1', 'Slipping'), 'teleport has no direction and does not apply Slipping');

            $events = [];
            $pushed = Forced::push($state, 'a1', [1, 0], 1, 'b1', new SequenceRandom([]), $events);
            expect_same([1, 0], ice_named_condition($pushed, 'a1', 'Slipping')['data']['direction'] ?? null, 'push supplies the locked direction');
            expect_same(0, $pushed['turn']['moveSpent'], 'push does not spend the unit Move');

            $flying = $state;
            $flying['units']['a1'] = terrain_unit('a1', 'seat-a', '0,0', ['Flying'], ['move' => 4]);
            $events = [];
            $ignored = TerrainResolver::enter($flying, 'a1', '0,0', '1,0', 'voluntary', [1, 0], new SequenceRandom([]), $events);
            expect_same([], UnitConditions::forUnit($ignored['state'], 'a1'), 'Flying receives no ice conditions');
        },

        'Unsteady weakens Power and both ice conditions suppress disengagement strikes' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '0,1' => 0, '2,0' => 0]), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 4]),
                vanilla_unit('b1', 'seat-b', '0,1'),
            ]);
            foreach (['Unsteady', 'Slipping'] as $name) {
                $conditionEvents = [];
                $conditioned = UnitConditions::apply(
                    $state,
                    $name,
                    'b1',
                    null,
                    ['kind' => $name === 'Unsteady' ? 'whileOnTerrain' : 'untilCleared'],
                    $conditionEvents,
                    $name === 'Slipping' ? ['direction' => [1, 0]] : []
                );
                expect_same([], Movement::disengagedFromPath($conditioned, 'a1', ['2,0']), "{$name} prevents the parting strike");
            }
            $events = [];
            $unsteady = UnitConditions::apply($state, 'Unsteady', 'a1', null, ['kind' => 'whileOnTerrain'], $events);
            expect_same(3, Stats::effective($unsteady, 'a1', 'power'), 'Unsteady lowers Power by one');
        },

        'ice conditions clear across push and teleport movement boundaries' => static function (): void {
            $board = vanilla_board(vanilla_line(4), [], ice_paint(['0,0', '1,0']));
            $state = vanilla_battle($board, [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', null),
            ]);
            $conditionEvents = [];
            $state = ice_condition($state, 'a1', [1, 0], $conditionEvents);

            $teleportEvents = [];
            $teleported = TerrainResolver::enter($state, 'a1', '0,0', '1,0', 'teleport', null, new SequenceRandom([]), $teleportEvents);
            expect_true(UnitConditions::has($teleported['state'], 'a1', 'Unsteady'), 'teleporting onto ice retains Unsteady');
            expect_true(!UnitConditions::has($teleported['state'], 'a1', 'Slipping'), 'teleporting discards the old travel direction');

            $pushEvents = [];
            $pushed = Forced::push($state, 'a1', [1, 0], 3, 'b1', new SequenceRandom([]), $pushEvents);
            expect_same('3,0', $pushed['units']['a1']['hex'], 'the push crosses ice and ends on stable ground');
            expect_same([], UnitConditions::forUnit($pushed, 'a1'), 'leaving ice through a push clears both conditions');
        },

        'voluntary ice movement executes the exact projected slide path' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5), [], ice_paint(['1,0', '2,0'])), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '4,0'),
            ]);
            $result = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'path' => ['1,0', '2,0', '3,0']]);
            expect_same('3,0', $result['state']['units']['a1']['hex'], 'the command follows the projected ice continuation');
            expect_same([], UnitConditions::forUnit($result['state'], 'a1'), 'the stable exit clears ice conditions');
            expect_true(in_array('unitSlid', array_column($result['events'], 'kind'), true), 'the forced portion has its own authoritative event');
            $moved = array_values(array_filter($result['events'], static fn(array $event): bool => $event['kind'] === 'unitMoved'));
            expect_same(['1,0', '2,0', '3,0'], $moved[0]['payload']['path'] ?? null, 'the movement event preserves the full submitted path');
        },

        'straight ice continuation exits onto stable ground and clears both conditions' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5), [], ice_paint(['0,0', '1,0', '2,0'])), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 4]),
                vanilla_unit('b1', 'seat-b', '4,0'),
            ]);
            $conditionEvents = [];
            $state = ice_condition($state, 'a1', [1, 0], $conditionEvents);
            $events = [];
            $result = IceMovement::continue($state, 'a1', 3, new IceSequenceRandom([]), $events);
            expect_same(['1,0', '2,0', '3,0'], $result['path'], 'the slider continues straight through ice and onto level ground');
            expect_same('3,0', $result['state']['units']['a1']['hex'], 'the unit finishes on the stable exit');
            expect_same(0, $result['remainingMove'], 'each actual step consumes Move');
            expect_same([], UnitConditions::forUnit($result['state'], 'a1'), 'leaving ice clears Unsteady and Slipping');
            expect_same('exit', $events[array_key_last($events)]['payload']['stopped'] ?? null, 'the slide event identifies a stable exit');
        },

        'ice stops before higher ice prohibited climbs walls and edges' => static function (): void {
            $cases = [
                'higher ice' => [vanilla_board(['0,0' => 0, '1,0' => 1], [], ice_paint(['0,0', '1,0'])), '0,0'],
                'two-level climb' => [vanilla_board(['0,0' => 0, '1,0' => 2], [], ice_paint(['0,0'])), '0,0'],
                'wall' => [vanilla_board(['0,0' => 0, '1,0' => 0], ['1,0'], ice_paint(['0,0'])), '0,0'],
                'edge' => [vanilla_board(['0,0' => 0], [], ice_paint(['0,0'])), '0,0'],
            ];
            foreach ($cases as $label => [$board, $origin]) {
                $state = vanilla_battle($board, [
                    vanilla_unit('a1', 'seat-a', $origin, ['move' => 3]),
                    vanilla_unit('b1', 'seat-b', null),
                ]);
                $conditionEvents = [];
                $state = ice_condition($state, 'a1', [1, 0], $conditionEvents);
                $events = [];
                $result = IceMovement::continue($state, 'a1', 3, new IceSequenceRandom([]), $events);
                expect_same([], $result['path'], "{$label} stops before the blocked step");
                expect_true(UnitConditions::has($result['state'], 'a1', 'Unsteady'), "{$label} leaves Unsteady while still on ice");
                expect_true(!UnitConditions::has($result['state'], 'a1', 'Slipping'), "{$label} clears Slipping");
            }
        },

        'ice allows a one-level stable exit and rolls each descended level before continuing' => static function (): void {
            $up = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 1], [], ice_paint(['0,0'])), [
                vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', null),
            ]);
            $conditionEvents = [];
            $up = ice_condition($up, 'a1', [1, 0], $conditionEvents);
            $events = [];
            $upResult = IceMovement::continue($up, 'a1', 1, new IceSequenceRandom([]), $events);
            expect_same(['1,0'], $upResult['path'], 'a one-level non-ice climb is a legal exit');
            expect_same([], UnitConditions::forUnit($upResult['state'], 'a1'), 'the raised stable exit clears ice conditions');

            $down = vanilla_battle(vanilla_board(['0,0' => 2, '1,0' => 0, '2,0' => 0], [], ice_paint(['0,0', '1,0'])), [
                vanilla_unit('a1', 'seat-a', '0,0', ['hp' => 9]), vanilla_unit('b1', 'seat-b', null),
            ]);
            $conditionEvents = [];
            $down = ice_condition($down, 'a1', [1, 0], $conditionEvents);
            $events = [];
            $random = new IceSequenceRandom([4, 4]);
            $downResult = IceMovement::continue($down, 'a1', 2, $random, $events);
            expect_same(2, $random->rolls, 'a two-level descent rolls two dice');
            expect_same(['1,0', '2,0'], $downResult['path'], 'an ice landing continues to the stable exit');
            expect_same(0, $downResult['state']['units']['a1']['wounds'], 'shield faces cause no fall wounds');
        },

        'Slipping persists across turns and activation resolves it before normal actions' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4), [], ice_paint(['0,0', '1,0'])), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3, 'range' => 1]),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $conditionEvents = [];
            $state = ice_condition($state, 'a1', [1, 0], $conditionEvents);
            $afterA = vanilla_command($state, 'seat-a', 'endTurn');
            $afterB = vanilla_command($afterA['state'], 'seat-b', 'endTurn');
            expect_true(UnitConditions::has($afterB['state'], 'a1', 'Slipping'), 'an unactivated slider survives turn changes');

            $rules = new \BloodAndBanners\Rules\Vanilla\BattleRules(0);
            $before = array_values(array_filter($rules->legalActions($afterB['state'], 'seat-a'), static fn(array $action): bool => ($action['unitId'] ?? null) === 'a1'));
            expect_same(['activateUnit'], array_values(array_unique(array_column($before, 'kind'))), 'a fresh slider offers activation instead of direct actions');

            $activated = vanilla_command($afterB['state'], 'seat-a', 'activateUnit', ['unitId' => 'a1']);
            expect_same('2,0', $activated['state']['units']['a1']['hex'], 'activation resolves the saved slide first');
            expect_same('a1', $activated['state']['turn']['activeUnitId'], 'the unit becomes active after sliding finishes');
            expect_same('unitSlid', $activated['events'][array_key_last($activated['events'])]['kind'] ?? null, 'activation narrates the completed slide');
        },

        'Stunned and push-created sliders move only when activated' => static function (): void {
            $board = vanilla_board(vanilla_line(4), [], ice_paint(['1,0', '2,0']));
            $state = vanilla_battle($board, [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $pushEvents = [];
            $pushed = Forced::push($state, 'a1', [1, 0], 1, 'b1', new SequenceRandom([]), $pushEvents);
            expect_same('1,0', $pushed['units']['a1']['hex'], 'the push ends at its own forced distance');
            $conditionEvents = [];
            $pushed = UnitConditions::apply($pushed, 'Stunned', 'a1', 'b1', UnitConditions::afterTurn(2), $conditionEvents);

            $activated = vanilla_command($pushed, 'seat-a', 'activateUnit', ['unitId' => 'a1']);
            expect_same('2,0', $activated['state']['units']['a1']['hex'], 'Stunned slides until the occupied hex stops it');
            $rules = new \BloodAndBanners\Rules\Vanilla\BattleRules(0);
            $moveActions = array_filter($rules->legalActions($activated['state'], 'seat-a'), static fn(array $action): bool => $action['kind'] === 'moveUnit' && $action['unitId'] === 'a1');
            expect_same([], array_values($moveActions), 'Stunned still prevents ordinary movement afterward');
        },

        'lethal ice fall stops after the first die and before later movement' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 2, '1,0' => 0, '2,0' => 0], [], ice_paint(['0,0', '1,0'])), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3, 'hp' => 1]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $conditionEvents = [];
            $state = ice_condition($state, 'a1', [1, 0], $conditionEvents);
            $random = new IceSequenceRandom([1]);
            $result = (new \BloodAndBanners\Rules\Vanilla\MatchEngine(test_catalog()))->apply(
                $state,
                'seat-a',
                'activateUnit',
                ['unitId' => 'a1'],
                $random
            );
            expect_same(1, $random->rolls, 'defeat on the first fall die prevents the second roll');
            expect_same(null, $result['state']['units']['a1']['hex'], 'the falling unit is defeated');
            expect_same('completed', $result['state']['phase'], 'last-unit defeat completes the match');
            expect_true(!in_array('2,0', array_merge(...array_map(static fn(array $event): array => $event['focusHexes'], $result['events'])), true), 'no later hex is entered or narrated');
        },
    ];
}
