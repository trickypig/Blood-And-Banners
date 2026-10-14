<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Modes\Modes;
use BloodAndBanners\Rules\Vanilla\Schedule;

function mode_schedule_tests(): array
{
    return [
        'a duel schedule alternates the first seat and the other' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            expect_same(0, $state['turn']['slot'], 'the first seat holds slot 0');
            expect_same(1, Schedule::after($state, 0), 'slot 1 follows slot 0');
            expect_same(0, Schedule::after($state, 1), 'the round wraps to slot 0');
            expect_same(3, Schedule::nextTurnNumberOf($state, 'seat-a'), 'the active seat acts again two turns on');
            expect_same(2, Schedule::nextTurnNumberOf($state, 'seat-b'), 'the other seat acts next turn');
        },

        'ending a duel turn passes to the other seat and counts rounds on the wrap' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')], 'seat-b');
            $passed = vanilla_command($state, 'seat-b', 'endTurn')['state'];
            expect_same('seat-a', $passed['turn']['activeSeatId'], 'seat-a follows seat-b');
            expect_same(0, $passed['turn']['slot'], 'the turn records its slot');
            expect_same(2, $passed['turn']['round'], 'wrapping to slot 0 starts a new round');
        },

        'saved states gain the vanilla mode and a slot for the active seat' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')], 'seat-b');
            unset($state['turn']['slot']);
            $normalized = MatchEngine::normalize($state);
            expect_same('vanillaPvp', $normalized['mode'], 'old states are duels');
            expect_same(1, $normalized['turn']['slot'], 'seat-b holds slot 1');
        },

        'unplayable modes are refused' => static function (): void {
            expect_game_error('modeNotPlayable', static fn() => Modes::for('battleRoyale'));
        },
    ];
}
