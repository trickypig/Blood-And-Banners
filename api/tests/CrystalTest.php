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

        'an enemy that could not strike a leaving unit does not engage it' => static function (): void {
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '4,0'), vanilla_unit('b1', 'seat-b', '3,0')]);
            $state['conditions'][] = ['name' => 'Stunned', 'unitId' => 'b1', 'sourceUnitId' => null, 'expires' => [], 'data' => []];
            $events = [];
            $after = Crystals::claim($state, 'a1', $events);
            expect_same(['5,0' => 'seat-a'], $after['crystals'] ?? [], 'a stunned neighbour, like for disengagement strikes, does not engage');
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
            $state = crystal_battle([vanilla_unit('a1', 'seat-a', '4,0'), vanilla_unit('a2', 'seat-a', '3,0'), vanilla_unit('b1', 'seat-b', '8,0')]);
            $state['turn']['activeUnitId'] = 'a1';
            $state['turn']['activated'][] = 'a1';
            $state['conditions'][] = ['name' => 'Slipping', 'unitId' => 'a1', 'sourceUnitId' => null, 'expires' => [], 'data' => ['direction' => [-1, 0]]];
            $state['pending'] = [
                'choice' => ['choiceId' => 'c1', 'kind' => 'iceCollision', 'seatId' => 'seat-a', 'unitId' => 'a1', 'options' => ['stop']],
                'context' => ['currentHex' => '4,0', 'direction' => [-1, 0], 'remainingMove' => 0, 'path' => [], 'from' => '4,0', 'blockerUnitId' => 'a2'],
            ];
            $resolved = vanilla_command($state, 'seat-a', 'resolveChoice', ['choiceId' => 'c1', 'option' => 'stop']);
            expect_same(['5,0' => 'seat-a'], $resolved['state']['crystals'] ?? [], 'the claim waits for the slide to end');
        },

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

        'board tiles keep their map object' => static function (): void {
            $board = \BloodAndBanners\Rules\Vanilla\Board::fromScenario(['map' => ['hexes' => [
                ['q' => 0, 'r' => 0, 'biome' => 'plains', 'terrain' => 'grass', 'elevation' => 0, 'object' => 'ice-crystal', 'sightBlocker' => false],
            ]]]);
            expect_same('ice-crystal', $board['0,0']['object'], 'object kept');
        },
    ];
}
