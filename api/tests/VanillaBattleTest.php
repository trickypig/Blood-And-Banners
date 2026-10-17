<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\UnitConditions;
use BloodAndBanners\Rules\Vanilla\Turn;

function vanilla_battle_tests(): array
{
    return [
        'movement climbs at most one level per step' => static function (): void {
            $state = vanilla_battle(
                vanilla_board(['0,0' => 0, '1,0' => 1, '2,0' => 3, '3,0' => 1]),
                [vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '3,0')]
            );
            expect_same(['1,0'], Movement::reachable($state, 'a1'), 'a two-level cliff stops movement');
        },

        'units pass through friends but not enemies and never stop on an occupied hex' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 4]),
                vanilla_unit('a2', 'seat-a', '1,0'),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            expect_same(['2,0'], Movement::reachable($state, 'a1'), 'friends are passable, enemies block');
        },

        'sight blocking hexes are impassable' => static function (): void {
            $state = vanilla_battle(
                vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '5,5' => 0], ['1,0']),
                [vanilla_unit('a1', 'seat-a', '0,0', ['move' => 3]), vanilla_unit('b1', 'seat-b', '5,5')]
            );
            expect_same([], Movement::reachable($state, 'a1'), 'a rock wall blocks the only path');
        },

        'moving away from an engaged enemy costs one disengagement die' => static function (): void {
            $units = [vanilla_unit('a1', 'seat-a', '1,0'), vanilla_unit('b1', 'seat-b', '0,0')];
            $struck = vanilla_command(vanilla_battle(vanilla_board(vanilla_line(4)), $units), 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [1]);
            expect_same('unitMoved', $struck['events'][0]['kind'], 'the move is announced first');
            expect_same(
                [['byUnitId' => 'b1', 'faces' => ['sword'], 'wounds' => 1]],
                $struck['events'][0]['payload']['disengagement'],
                'a sword is one wound'
            );
            expect_same(['1,0', '3,0'], $struck['events'][0]['focusHexes'], 'the move focuses both ends');
            expect_same(1, $struck['state']['units']['a1']['wounds'], 'the wound is applied');

            $blocked = vanilla_command(vanilla_battle(vanilla_board(vanilla_line(4)), $units), 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [4]);
            expect_same(0, $blocked['state']['units']['a1']['wounds'], 'a shield does nothing');
        },

        'enemies more than one level apart are not engaged' => static function (): void {
            $state = vanilla_battle(
                vanilla_board(['0,0' => 2, '1,0' => 0, '2,0' => 0, '3,0' => 0]),
                [vanilla_unit('a1', 'seat-a', '1,0'), vanilla_unit('b1', 'seat-b', '0,0')]
            );
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0']);
            expect_same([], $moved['events'][0]['payload']['disengagement'], 'no die is rolled');
        },

        'a lethal disengagement removes the mover and can end the match' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '1,0', ['hp' => 1]),
                vanilla_unit('b1', 'seat-b', '0,0'),
            ]);
            $result = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [2]);
            expect_same(['unitMoved', 'unitDefeated', 'matchCompleted'], array_column($result['events'], 'kind'), 'defeat and victory follow the move');
            expect_same(null, $result['state']['units']['a1']['hex'], 'the defeated mover leaves the board');
            expect_same(null, $result['state']['turn']['activeUnitId'], 'the dead unit is no longer active');
            expect_same(['reason' => 'annihilation', 'winningSeatId' => 'seat-b'], $result['state']['result'], 'seat-b wins');
        },

        'a living hero limits a turn to three other activations' => static function (): void {
            $board = vanilla_board(vanilla_line(10) + ['0,5' => 0]);
            $state = vanilla_battle($board, [
                vanilla_unit('a-hero', 'seat-a', '0,0', ['isHero' => true]),
                vanilla_unit('a1', 'seat-a', '2,0'),
                vanilla_unit('a2', 'seat-a', '4,0'),
                vanilla_unit('a3', 'seat-a', '6,0'),
                vanilla_unit('a4', 'seat-a', '8,0'),
                vanilla_unit('b1', 'seat-b', '0,5'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '5,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a3', 'to' => '7,0'])['state'];
            expect_same(1, Turn::activationsRemaining($state), 'three of four activations are spent');
            expect_game_error('heroActivationRequired', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a4', 'to' => '9,0']));

            $last = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a-hero', 'to' => '1,0']);
            expect_same(['unitMoved', 'turnEnded'], array_column($last['events'], 'kind'), 'the turn passes when nothing can act');
            expect_same('seat-b', $last['state']['turn']['activeSeatId'], 'seat-b takes over');
            expect_same(['seatId' => 'seat-a', 'nextSeatId' => 'seat-b', 'round' => 1], $last['events'][1]['payload'], 'the round only advances on the first player');
        },

        'a dead hero lets any four units activate' => static function (): void {
            $board = vanilla_board(vanilla_line(10) + ['0,5' => 0]);
            $state = vanilla_battle($board, [
                vanilla_unit('a-hero', 'seat-a', null, ['isHero' => true]),
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('a2', 'seat-a', '2,0'),
                vanilla_unit('a3', 'seat-a', '4,0'),
                vanilla_unit('a4', 'seat-a', '6,0'),
                vanilla_unit('a5', 'seat-a', '8,0'),
                vanilla_unit('b1', 'seat-b', '0,5'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '3,0'])['state'];
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a3', 'to' => '5,0'])['state'];
            $result = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a4', 'to' => '7,0']);
            expect_same('seat-b', $result['state']['turn']['activeSeatId'], 'the fourth activation spends the turn');
        },

        'units activate once per turn and move once per activation' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 1, 'hp' => 5]),
                vanilla_unit('a3', 'seat-a', '2,0'),
                vanilla_unit('a2', 'seat-a', '3,0'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [6, 6])['state'];
            expect_game_error('unitAlreadyActivated', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0']));
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '4,0'])['state'];
            expect_game_error('alreadyMoved', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '3,0']));
            expect_game_error('hexUnreachable', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a3', 'to' => '0,0']));
            expect_game_error('notYourUnit', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'b1', 'to' => '0,0']));
            expect_game_error('unknownUnit', static fn() => vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'ghost', 'to' => '0,0']));
            expect_game_error('notYourTurn', static fn() => vanilla_command($state, 'seat-b', 'endTurn'));
        },

        'attacks need range and a clear line of sight' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['range' => 2]),
                vanilla_unit('a2', 'seat-a', '1,0'),
                vanilla_unit('b2', 'seat-b', '2,0'),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            expect_game_error('targetOutOfRange', static fn() => vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1']));
            expect_game_error('noLineOfSight', static fn() => vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b2']));
            expect_game_error('unknownUnit', static fn() => vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'a2']));
            $attacks = array_values(array_filter(
                (new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'),
                static fn(array $action): bool => $action['kind'] === 'attackUnit'
            ));
            expect_same([['kind' => 'attackUnit', 'unitId' => 'a2', 'targetHexes' => ['2,0']]], $attacks, 'only the adjacent friend can strike');
        },

        'wounds are swords minus shields and lethal wounds remove the unit' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 2, 'hp' => 2]),
                vanilla_unit('b2', 'seat-b', '3,0'),
            ]);
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 2, 3, 4, 6]);
            expect_same(['attackResolved', 'unitDefeated', 'turnEnded'], array_column($result['events'], 'kind'), 'attack, defeat, then the spent turn passes');
            expect_same(
                ['unitId' => 'a1', 'targetUnitId' => 'b1', 'ability' => null, 'attackFaces' => ['sword', 'sword', 'sword'], 'defenseFaces' => ['shield', 'blank'], 'rerolled' => null, 'wounds' => 2,
                    'attackBase' => 3, 'attackModifiers' => [], 'defenseBase' => 2, 'defenseModifiers' => []],
                $result['events'][0]['payload'],
                'dice faces and wounds are reported'
            );
            expect_same(null, $result['state']['units']['b1']['hex'], 'the defeated unit leaves the board');
            expect_same(2, $result['state']['units']['b1']['wounds'], 'wounds never exceed hit points');
        },

        'attack reports name every modifier behind each dice pool' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4), [], ['0,0' => ['biome' => 'water', 'terrain' => 'open-water']]), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 1, 'hp' => 5]),
                vanilla_unit('b2', 'seat-b', '3,0'),
            ]);
            $events = [];
            $state = Effects::add($state, 'Rally', 'b2', 'b1', 'defense', 2, 'seat-b', Effects::UNTIL_NEXT_TURN, $events);
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 1, 1, 1, 1]);
            $payload = $result['events'][0]['payload'];
            expect_same([3, [['source' => 'Standing in water', 'amount' => -1]]], [$payload['attackBase'], $payload['attackModifiers']], 'water costs the attacker a die');
            expect_same(2, count($payload['attackFaces']), 'the pool matches the breakdown');
            expect_same([1, [['source' => 'Rally', 'amount' => 2]]], [$payload['defenseBase'], $payload['defenseModifiers']], 'the effect adds defense dice');
            expect_same(3, count($payload['defenseFaces']), 'the defense pool matches the breakdown');
        },

        'a defense-blocking condition is reported as a null modifier' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 3, 'hp' => 5]),
                vanilla_unit('b2', 'seat-b', '3,0'),
            ]);
            $events = [];
            $state = UnitConditions::apply($state, 'Petrified', 'b1', 'a1', ['kind' => 'untilCleared'], $events);
            $breakdown = Stats::breakdown($state, 'b1', 'defense');
            expect_same(['value' => 0, 'base' => 3, 'modifiers' => [['source' => 'Petrified', 'amount' => null]]], $breakdown, 'petrified units roll no defense');
            expect_same(0, Stats::effective($state, 'b1', 'defense'), 'effective agrees with the breakdown');
        },

        'destroying the last enemy unit wins by annihilation' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(2)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 1, 'hp' => 1]),
            ]);
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 6]);
            expect_same(['attackResolved', 'unitDefeated', 'matchCompleted'], array_column($result['events'], 'kind'), 'the match ends immediately');
            expect_same('completed', $result['state']['phase'], 'the phase is completed');
            expect_same(['reason' => 'annihilation', 'winningSeatId' => 'seat-a'], $result['state']['result'], 'seat-a wins');
            expect_game_error('matchCompleted', static fn() => vanilla_command($result['state'], 'seat-b', 'endTurn'));
            expect_same([], (new MatchEngine(test_catalog()))->legalActions($result['state'], 'seat-a'), 'nothing is legal after victory');
        },

        'ending turns alternates seats and advances the round on the first player' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            $first = vanilla_command($state, 'seat-a', 'endTurn');
            expect_same(['seatId' => 'seat-a', 'nextSeatId' => 'seat-b', 'round' => 1], $first['events'][0]['payload'], 'seat-b follows in round one');
            $second = vanilla_command($first['state'], 'seat-b', 'endTurn');
            expect_same(['seatId' => 'seat-b', 'nextSeatId' => 'seat-a', 'round' => 2], $second['events'][0]['payload'], 'round two begins with the first player');
            expect_same(2, $second['state']['turn']['round'], 'the turn record advances');
        },

        'end activation only accepts the active unit' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('a2', 'seat-a', '5,0'),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $state = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0'])['state'];
            expect_game_error('unitNotActive', static fn() => vanilla_command($state, 'seat-a', 'endActivation', ['unitId' => 'a2']));
            $ended = vanilla_command($state, 'seat-a', 'endActivation', ['unitId' => 'a1'])['state'];
            expect_same(null, $ended['turn']['activeUnitId'], 'the activation is closed');
            expect_same(['a1'], $ended['turn']['activated'], 'the spent unit stays spent');
        },

        'legal actions list reachable hexes for the active seat only' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 1]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $engine = new MatchEngine(test_catalog());
            expect_same(
                [
                    [
                        'kind' => 'moveUnit', 'unitId' => 'a1', 'targetHexes' => ['1,0'],
                        'movementOptions' => [[
                            'destination' => '1,0', 'path' => ['1,0'], 'cost' => 1, 'hazards' => [],
                        ]],
                    ],
                    ['kind' => 'endTurn', 'unitId' => null, 'targetHexes' => []],
                ],
                $engine->legalActions($state, 'seat-a'),
                'the active seat sees its moves and end turn'
            );
            expect_same([], $engine->legalActions($state, 'seat-b'), 'the waiting seat sees nothing');
        },
    ];
}
