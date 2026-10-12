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

            $theirTurn = $moved['state']; // seat-a's only activation is spent, so the turn passes
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
