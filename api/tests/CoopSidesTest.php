<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Sides;
use BloodAndBanners\Rules\Vanilla\Targeting;

function coop_sides_tests(): array
{
    return [
        'saved duel states gain one side per seat' => static function (): void {
            $state = MatchEngine::normalize(vanilla_battle(
                vanilla_board(vanilla_line(3)),
                [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]
            ));
            expect_same(Sides::perSeat(['seat-a', 'seat-b']), $state['sides'], 'each seat is its own side');
            expect_same('seat-a', $state['units']['a1']['side'], 'a unit sides with its seat');
            expect_true(Sides::enemies($state, 'a1', 'b1'), 'duel seats are enemies');
        },

        'an allied seat unit cannot be attacked' => static function (): void {
            $state = allied_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('c1', 'seat-c', '1,0'),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            expect_game_error('unknownUnit', static fn() => vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'c1']));
        },

        'targeting separates allied seats from the enemy side' => static function (): void {
            $state = allied_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0'),
                vanilla_unit('c1', 'seat-c', '2,0'),
            ]);
            expect_same(['b1'], Targeting::enemiesInSight($state, 'a1', 3), 'only the void unit is an enemy');
            expect_same(['c1'], Targeting::alliesWithin($state, 'a1', 3, false), 'the other hero seat is an ally');
        },

        'units pass through allies and leave them without a disengagement strike' => static function (): void {
            $state = allied_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '1,0', ['move' => 3]),
                vanilla_unit('c1', 'seat-c', '2,0'),
                vanilla_unit('c2', 'seat-c', '0,0'),
                vanilla_unit('b1', 'seat-b', '4,0'),
            ]);
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0']);
            expect_same('3,0', $moved['state']['units']['a1']['hex'], 'the move passes through the allied unit');
            expect_same([], $moved['events'][0]['payload']['disengagement'], 'allies never strike a departing unit');
        },
    ];
}
