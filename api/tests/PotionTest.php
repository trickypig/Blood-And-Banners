<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\Stats;

/** a1 (0,0) moves to 2,0 onto the given potion; a2 stands by so seat-a can still act, b1 is far away. */
function potion_moved(string $kind, ?callable $setup = null): array
{
    $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
        vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('a2', 'seat-a', '0,1'), vanilla_unit('b1', 'seat-b', '5,0'),
    ]);
    $state['board']['0,1'] = $state['board']['0,0'];
    $state['board']['2,0']['object'] = $kind;
    if ($setup !== null) {
        $setup($state);
    }
    return vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
}

function potion_resolve(array $state, string $option): array
{
    return vanilla_command($state, 'seat-a', 'resolveChoice', ['choiceId' => $state['pending']['choice']['choiceId'], 'option' => $option]);
}

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

        'ending a move on a potion offers drink, keep or leave' => static function (): void {
            $s = potion_moved('power-potion')['state'];
            expect_same('itemPickup', $s['pending']['choice']['kind'], 'a pickup choice is pending');
            expect_same(['drink', 'keep', 'leave'], $s['pending']['choice']['options'], 'all three');
            expect_same('power-potion', $s['pending']['choice']['item'], 'the potion under the unit');
            expect_same('power-potion', $s['board']['2,0']['object'], 'still on the map until decided');
        },

        'keeping puts the potion in the unit\'s inventory' => static function (): void {
            $s = potion_moved('power-potion')['state'];
            $kept = potion_resolve($s, 'keep');
            expect_same(['power-potion'], $kept['state']['units']['a1']['items'], 'carried');
            expect_same(null, $kept['state']['board']['2,0']['object'], 'off the map');
            expect_same([], $kept['state']['effects'], 'no effect yet');
            expect_same(null, $kept['state']['pending'], 'decided');
            $picked = array_values(array_filter($kept['events'], static fn(array $e): bool => $e['kind'] === 'itemPickedUp'));
            expect_same([['unitId' => 'a1', 'seatId' => 'seat-a', 'hex' => '2,0', 'kind' => 'power-potion']], array_column($picked, 'payload'), 'reported');
        },

        'drinking now applies the effect and uses the turn\'s item' => static function (): void {
            $s = potion_moved('power-potion')['state'];
            $drunk = potion_resolve($s, 'drink');
            $state = $drunk['state'];
            expect_same(null, $state['board']['2,0']['object'], 'the potion is gone');
            expect_same(4, Stats::effective($state, 'a1', 'power'), '+2 power');
            expect_same(['seatId' => 'seat-a', 'at' => Effects::UNTIL_END_OF_TURN], $state['effects'][0]['expires'], 'counted on the drinker\'s turn ends');
            expect_same(3, $state['effects'][0]['turns'], 'this turn is the first of three');
            expect_same(['a1'], $state['turn']['itemsUsed'], 'the unit has used its item this turn');
            $events = array_values(array_filter($drunk['events'], static fn(array $e): bool => $e['kind'] === 'potionDrunk'));
            expect_same([['unitId' => 'a1', 'seatId' => 'seat-a', 'hex' => '2,0', 'kind' => 'power-potion']], array_column($events, 'payload'), 'one drink reported');
        },

        'leaving it keeps the potion on the map' => static function (): void {
            $s = potion_moved('power-potion')['state'];
            $left = potion_resolve($s, 'leave')['state'];
            expect_same('power-potion', $left['board']['2,0']['object'], 'still there');
            expect_same([], $left['units']['a1']['items'] ?? [], 'nothing carried');
            expect_same([], $left['turn']['itemsUsed'], 'nothing used');
            expect_same(null, $left['pending'], 'decided');
        },

        'only the unit\'s seat may answer the pickup' => static function (): void {
            $s = potion_moved('power-potion')['state'];
            expect_game_error('notYourChoice', static fn() => vanilla_command($s, 'seat-b', 'resolveChoice', ['choiceId' => $s['pending']['choice']['choiceId'], 'option' => 'leave']));
        },

        'passing over a potion mid-move offers nothing' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '5,0')]);
            $state['board']['1,0']['object'] = 'speed-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('speed-potion', $moved['state']['board']['1,0']['object'], 'still there');
            expect_same(null, $moved['state']['pending'], 'no offer');
        },

        'a unit carrying two items is not offered a third' => static function (): void {
            $moved = potion_moved('power-potion', static function (array &$state): void {
                $state['units']['a1']['items'] = ['speed-potion', 'health-potion'];
            });
            expect_same(null, $moved['state']['pending'], 'no offer');
            expect_same('power-potion', $moved['state']['board']['2,0']['object'], 'the potion stays');
        },

        'drink now is not offered once the unit has used an item this turn' => static function (): void {
            $moved = potion_moved('power-potion', static function (array &$state): void {
                $state['turn']['itemsUsed'] = ['a1'];
            });
            expect_same(['keep', 'leave'], $moved['state']['pending']['choice']['options'], 'keep or leave only');
        },

        'a pushed unit is not offered the potion it lands on' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $state['board']['2,0']['object'] = 'power-potion';
            $pushed = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 5]);
            expect_same('2,0', $pushed['state']['units']['b1']['hex'], 'pushed onto the potion');
            expect_same(null, $pushed['state']['pending'], 'no offer');
            expect_same('power-potion', $pushed['state']['board']['2,0']['object'], 'the potion stays');
            expect_same([], $pushed['state']['effects'], 'no effect');
        },

        'a teleported unit is not offered the potion it lands on' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 0, '4,0' => 0, '5,0' => 0, '6,0' => 0]), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Teleport'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '1,0', ['wounds' => 2]),
                vanilla_unit('b1', 'seat-b', '6,0'),
            ]);
            $state['board']['3,0']['object'] = 'health-potion';
            $blink = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Teleport', 'targetUnitIds' => ['a2'], 'toHex' => '3,0']);
            expect_same('3,0', $blink['state']['units']['a2']['hex'], 'landed');
            expect_same(null, $blink['state']['pending'], 'no offer');
            expect_same(2, $blink['state']['units']['a2']['wounds'], 'not healed');
        },

        'the void lord\'s monsters are not offered potions' => static function (): void {
            $state = coop_battle([coop_hero('h1', 'hero-a', '0,0'), vanilla_unit('m1', 'void-lord', '4,0', ['move' => 2, 'wounds' => 1, 'hp' => 3])], 'void-lord');
            $state['board']['2,0']['object'] = 'health-potion';
            $moved = vanilla_command($state, 'void-lord', 'moveUnit', ['unitId' => 'm1', 'to' => '2,0']);
            expect_same('2,0', $moved['state']['units']['m1']['hex'], 'the monster stands on it');
            expect_same(null, $moved['state']['pending'], 'no offer');
            expect_same('health-potion', $moved['state']['board']['2,0']['object'], 'the potion stays for the players');
            expect_same(1, $moved['state']['units']['m1']['wounds'], 'no healing');
        },

        'the turn waits for the pickup decision and passes after it' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '5,0')]);
            $state['board']['2,0']['object'] = 'speed-potion';
            // a1 spends its whole move and has no one in reach, so the turn would pass on the move command itself.
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('seat-a', $moved['state']['turn']['activeSeatId'], 'not passed while deciding');
            $left = potion_resolve($moved['state'], 'leave');
            expect_same('seat-b', $left['state']['turn']['activeSeatId'], 'passes once decided');
        },

        'a full-inventory unit ending its move with nothing left still passes the turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['items' => ['speed-potion', 'health-potion']]), vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state['board']['2,0']['object'] = 'power-potion';
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '2,0']);
            expect_same('seat-b', $moved['state']['turn']['activeSeatId'], 'nothing to decide, so the turn passed');
        },

        'speed and power last through the end of the drinker\'s third turn' => static function (): void {
            $s = potion_moved('speed-potion')['state'];
            $s = potion_resolve($s, 'drink')['state'];
            expect_same(4, Stats::effective($s, 'a1', 'move'), '+2 move');
            $ends = [];
            for ($i = 0; $i < 5; $i++) {
                $s = vanilla_command($s, $s['turn']['activeSeatId'], 'endTurn')['state'];
                $ends[] = Stats::effective($s, 'a1', 'move');
            }
            expect_same([4, 4, 4, 4, 2], $ends, 'a-end, b-end, a-end, b-end: still on; third a-end: gone');
        },

        'health heals every wound, even when wasted on an unhurt unit' => static function (): void {
            $healed = potion_moved('health-potion', static function (array &$state): void {
                $state['units']['a1']['hp'] = 5;
                $state['units']['a1']['wounds'] = 4;
            });
            $s = potion_resolve($healed['state'], 'drink')['state'];
            expect_same(0, $s['units']['a1']['wounds'], 'fully healed');
            $s['board']['0,2'] = $s['board']['0,0'];
            $s['board']['0,2']['object'] = 'health-potion';
            $s['turn']['itemsUsed'] = [];
            $wasted = vanilla_command($s, 'seat-a', 'moveUnit', ['unitId' => 'a2', 'to' => '0,2']);
            $wasted = potion_resolve($wasted['state'], 'drink');
            expect_same(null, $wasted['state']['board']['0,2']['object'], 'an unhurt unit still drinks it');
        },

        'the projected board shows the live object, so a drunk potion disappears' => static function (): void {
            $hex = ['q' => 2, 'r' => 0, 'object' => 'speed-potion'];
            expect_same('speed-potion', \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, null), 'no game yet: the map\'s object');
            expect_same(null, \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, ['2,0' => ['object' => null]]), 'drunk in play');
            expect_same('rune-of-focus', \BloodAndBanners\Rules\Vanilla\Board::projectedObject(['q' => 0, 'r' => 0, 'object' => 'rune-of-magic'], null), 'renamed');
        },

        'a board tile from before tiles carried objects falls back to the map, and an old rune name is renamed' => static function (): void {
            $hex = ['q' => 2, 'r' => 0, 'object' => 'speed-potion'];
            expect_same('speed-potion', \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, ['2,0' => ['elevation' => 0]]), 'no object key: the map');
            expect_same('rune-of-focus', \BloodAndBanners\Rules\Vanilla\Board::projectedObject($hex, ['2,0' => ['object' => 'rune-of-magic']]), 'stored old name');
        },
    ];
}
