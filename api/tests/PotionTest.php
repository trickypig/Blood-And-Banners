<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Effects;

function potion_tests(): array
{
    return [
        'a turn-counted effect lasts that many of its seat\'s turn ends' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            $events = [];
            $state = Effects::add($state, 'Power Potion', 'a1', 'a1', 'power', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events, 3);
            expect_same(3, $state['effects'][0]['turns'], 'starts at 3');
            $state = Effects::expire($state, 'seat-b', Effects::UNTIL_END_OF_TURN, $events);
            expect_same(3, $state['effects'][0]['turns'], 'another seat\'s turn end does not count');
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_same(1, $state['effects'][0]['turns'], 'two turn ends counted');
            $events = [];
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_same([], $state['effects'], 'gone after the third');
            expect_same(['effectExpired'], array_column($events, 'kind'), 'expiry reported once, at the end');

            $state = Effects::add($state, 'Speed Potion', 'a1', 'a1', 'move', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events, 3);
            $state = Effects::expire($state, 'seat-a', Effects::UNTIL_END_OF_TURN, $events, true);
            expect_same([], $state['effects'], 'expiring everything ignores the count');

            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_true(!array_key_exists('turns', $state['effects'][0]), 'plain effects carry no count');
        },
    ];
}
