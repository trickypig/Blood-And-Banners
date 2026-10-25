<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\Stats;

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

        'ending a move on a potion drinks it and removes it' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['2,0']['object'] = 'power-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            $drunk = array_values(array_filter($moved['events'], static fn(array $e): bool => $e['kind'] === 'potionDrunk'));
            expect_same([['unitId' => 'a1', 'seatId' => 'seat-a', 'hex' => '2,0', 'kind' => 'power-potion']], array_column($drunk, 'payload'), 'one drink reported');
            expect_same(null, $moved['state']['board']['2,0']['object'], 'the potion is gone');
            expect_same(4, Stats::effective($moved['state'], 'a1', 'power'), '+2 power');
            expect_same(['seatId' => 'seat-a', 'at' => Effects::UNTIL_END_OF_TURN], $moved['state']['effects'][0]['expires'], 'counted on the drinker\'s turn ends');
            expect_same(3, $moved['state']['effects'][0]['turns'], 'this turn is the first of three');
        },

        'passing over a potion mid-move does not drink it' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '5,0')]);
            $state['board']['1,0']['object'] = 'speed-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('speed-potion', $moved['state']['board']['1,0']['object'], 'still there');
            expect_same([], $moved['state']['effects'], 'no effect');
        },

        'a drink just before the turn passes on its own still counts that turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '5,0')]);
            $state['board']['2,0']['object'] = 'speed-potion';
            // a1 spends its whole move and has no one in reach, so seat-a's turn passes on the move command itself.
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('seat-b', $moved['state']['turn']['activeSeatId'], 'seat-a had nothing left and passed');
            expect_same(2, $moved['state']['effects'][0]['turns'], 'the drinking turn was counted');
        },

        'speed and power last through the end of the drinker\'s third turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['2,0']['object'] = 'speed-potion';
            $s = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0'])['state'];
            expect_same(4, Stats::effective($s, 'a1', 'move'), '+2 move');
            $ends = [];
            for ($i = 0; $i < 5; $i++) {
                $s = vanilla_command($s, $s['turn']['activeSeatId'], 'endTurn')['state'];
                $ends[] = Stats::effective($s, 'a1', 'move');
            }
            expect_same([4, 4, 4, 4, 2], $ends, 'a-end, b-end, a-end, b-end: still on; third a-end: gone');
        },

        'health heals every wound, even when wasted on an unhurt unit' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['hp' => 5, 'wounds' => 4]), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['2,0']['object'] = 'health-potion';
            $state['board']['0,2'] = $state['board']['0,0'];
            $state['board']['0,2']['object'] = 'health-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same(0, $moved['state']['units']['a1']['wounds'], 'fully healed');
            $wasted = vanilla_command($moved['state'], 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '0,2']);
            expect_same(null, $wasted['state']['board']['0,2']['object'], 'an unhurt unit still drinks it');
        },

        'a unit pushed onto a potion drinks it, and on the enemy turn it still gets three of its own' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $state['board']['2,0']['object'] = 'power-potion';
            $pushed = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 5]);
            expect_same('2,0', $pushed['state']['units']['b1']['hex'], 'pushed onto the potion');
            expect_same(null, $pushed['state']['board']['2,0']['object'], 'and drank it');
            $effect = array_values(array_filter($pushed['state']['effects'], static fn(array $e): bool => $e['targetUnitId'] === 'b1'))[0];
            expect_same(['seat-b', 3], [$effect['expires']['seatId'], $effect['turns']], 'three of seat-b\'s turns, even after seat-a\'s turn ends');
        },

        'a teleported unit drinks the potion it lands on' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 0, '4,0' => 0, '5,0' => 0, '6,0' => 0]), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Teleport'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '1,0', ['wounds' => 2]),
                vanilla_unit('b1', 'seat-b', '6,0'),
            ]);
            $state['board']['3,0']['object'] = 'health-potion';
            $blink = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Teleport', 'targetUnitIds' => ['a2'], 'toHex' => '3,0']);
            expect_same('3,0', $blink['state']['units']['a2']['hex'], 'landed');
            expect_same(0, $blink['state']['units']['a2']['wounds'], 'drank on landing');
        },

        'the void lord\'s monsters never drink potions' => static function (): void {
            $state = coop_battle([coop_hero('h1', 'hero-a', '0,0'), vanilla_unit('m1', 'void-lord', '4,0', ['move' => 2, 'wounds' => 1, 'hp' => 3])], 'void-lord');
            $state['board']['2,0']['object'] = 'health-potion';
            $moved = vanilla_command($state, 'void-lord', 'moveUnit', ['unitId' => 'm1', 'to' => '2,0']);
            expect_same('2,0', $moved['state']['units']['m1']['hex'], 'the monster stands on it');
            expect_same('health-potion', $moved['state']['board']['2,0']['object'], 'the potion stays for the players');
            expect_same(1, $moved['state']['units']['m1']['wounds'], 'no healing');
        },

        'drinking the same potion again resets it to three turns' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(8)), [
                vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '7,0'),
            ]);
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['2,0']['object'] = 'power-potion';
            $state['board']['4,0']['object'] = 'power-potion';
            $s = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0'])['state'];
            $s = vanilla_command($s, 'seat-a', 'endTurn')['state'];
            $s = vanilla_command($s, 'seat-b', 'endTurn')['state'];
            $s = vanilla_command($s, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '4,0'])['state'];
            $mine = array_values(array_filter($s['effects'], static fn(array $e): bool => $e['name'] === 'Power Potion' && $e['targetUnitId'] === 'a1'));
            expect_same(1, count($mine), 'a single Power Potion effect');
            expect_same(3, $mine[0]['turns'], 'reset to three');
        },

        'the projected board shows the live object, so a drunk potion disappears' => static function (): void {
            $hex = ['q' => 2, 'r' => 0, 'object' => 'speed-potion'];
            expect_same('speed-potion', \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, null), 'no game yet: the map\'s object');
            expect_same(null, \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, ['2,0' => ['object' => null]]), 'drunk in play');
            expect_same('rune-of-focus', \BloodAndBanners\Rules\Vanilla\Board::projectedObject(['q' => 0, 'r' => 0, 'object' => 'rune-of-magic'], null), 'renamed');
        },
    ];
}
