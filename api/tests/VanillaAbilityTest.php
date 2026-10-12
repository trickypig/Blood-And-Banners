<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Turn;

/** A battle unit carrying the named abilities (types are irrelevant to the rules, which key on names). */
function ability_unit(string $unitId, string $seatId, ?string $hex, array $abilityNames, array $stats = []): array
{
    $abilities = array_map(
        static fn(string $name): array => ['name' => $name, 'type' => 'Essence', 'description' => ''],
        $abilityNames
    );
    return vanilla_unit($unitId, $seatId, $hex, $stats + ['abilities' => $abilities, 'flags' => []]);
}

function vanilla_ability_tests(): array
{
    return [
        'catalog cards carry their abilities and deployment copies them' => static function (): void {
            $captain = test_catalog()->hero('Test Captain');
            expect_same(
                [['name' => 'Shield Wall', 'type' => 'Ability', 'description' => 'Grant one adjacent unit +1 Defense until the start of your next turn']],
                $captain['abilities'],
                'hero abilities are copied from the catalog'
            );
            expect_same([], test_catalog()->army('Red Company')['units'][0]['abilities'], 'units without abilities get an empty list');

            $engine = new MatchEngine(test_catalog());
            $none = new SequenceRandom([]);
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([17, 5]))['state'];
            foreach ([
                ['seat-a', 'chooseFirstPlayer', ['seatId' => 'seat-a']],
                ['seat-b', 'chooseStartPosition', ['start' => 2]],
                ['seat-b', 'pickHero', ['hero' => 'Test Marshal']],
                ['seat-a', 'pickHero', ['hero' => 'Test Captain']],
                ['seat-a', 'pickArmy', ['army' => 'Red Company']],
                ['seat-b', 'pickArmy', ['army' => 'Blue Company']],
            ] as [$seat, $kind, $payload]) {
                $state = $engine->apply($state, $seat, $kind, $payload, $none)['state'];
            }
            $hero = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['name'] === 'Test Captain'))[0];
            expect_same('Shield Wall', $hero['abilities'][0]['name'], 'the deployed hero carries Shield Wall');
            expect_same([], $hero['flags'], 'deployed units start with no flags');
        },

        'turns are numbered and carry fresh usage records' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            expect_same(1, $state['turn']['number'], 'the first turn is number one');
            expect_same(
                ['attacks' => 0, 'moveSpent' => 0, 'movedAfterAttack' => false, 'abilitiesUsed' => [], 'glareUsed' => []],
                array_intersect_key($state['turn'], array_flip(['attacks', 'moveSpent', 'movedAfterAttack', 'abilitiesUsed', 'glareUsed'])),
                'usage starts empty'
            );
            $next = vanilla_command($state, 'seat-a', 'endTurn')['state'];
            expect_same(2, $next['turn']['number'], 'each seat turn advances the number');
            expect_same(3, Turn::nextTurnNumberOf($next, 'seat-a'), 'seat-a plays next on turn three');
            expect_same(4, Turn::nextTurnNumberOf($next, 'seat-b'), 'the active seat\'s next turn is two ahead');
        },

        'old saved states are normalized with empty ability data' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            unset($state['turn']['number'], $state['turn']['attacks'], $state['turn']['abilitiesUsed']);
            $normal = MatchEngine::normalize($state);
            expect_same([], $normal['effects'], 'effects default to empty');
            expect_same(null, $normal['pending'], 'nothing is pending');
            expect_same([], $normal['units']['a1']['abilities'], 'units default to no abilities');
            expect_same([], $normal['units']['a1']['flags'], 'units default to no flags');
            expect_same(0, $normal['turn']['attacks'], 'attack count defaults to zero');
            expect_same([], $normal['turn']['abilitiesUsed'], 'ability usage defaults to empty');
        },

        'effects modify effective stats, refresh by name and expire at turn boundaries' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['defense' => 2, 'power' => 1]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $events = [];
            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_NEXT_TURN, $events);
            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_NEXT_TURN, $events);
            $state = Effects::add($state, 'Warlord\'s Command', 'a1', 'a1', 'power', 1, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::add($state, 'Rune of Distraction', 'b1', 'a1', 'power', -5, 'seat-b', Effects::UNTIL_NEXT_TURN, $events);
            expect_same(4, count($events), 'each application is announced');
            expect_same(3, Stats::effective($state, 'a1', 'defense'), 'the same named effect does not stack');
            expect_same(0, Stats::effective($state, 'a1', 'power'), 'effective stats never drop below zero');

            $passed = vanilla_command($state, 'seat-a', 'endTurn');
            expect_same(['effectExpired', 'turnEnded', 'effectExpired'], array_column($passed['events'], 'kind'), 'end-of-turn effects expire, the turn passes, then seat-b\'s effects end');
            expect_same(['Shield Wall'], array_column(Effects::forUnit($passed['state'], 'a1'), 'name'), 'Shield Wall lasts until seat-a\'s next turn');

            $back = vanilla_command($passed['state'], 'seat-b', 'endTurn');
            expect_same(['turnEnded', 'effectExpired'], array_column($back['events'], 'kind'), 'Shield Wall fades as seat-a starts again');
            expect_same(2, Stats::effective($back['state'], 'a1', 'defense'), 'defense is back to base');
        },

        'movement and range read effective stats' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 1, 'range' => 1]),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $events = [];
            $state = Effects::add($state, 'Test Haste', 'a1', 'a1', 'move', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::add($state, 'Test Sight', 'a1', 'a1', 'range', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_same(['1,0', '2,0'], Movement::reachable($state, 'a1'), 'the extra move opens two more hexes');
            expect_same(['b1'], Combat::targets($state, 'a1'), 'the extra range reaches the enemy');
            expect_same(['1,0' => 1, '2,0' => 2], Movement::costs($state, 'a1'), 'costs count steps');
            expect_same(['1,0'], Movement::reachable($state, 'a1', 1), 'a budget caps the search');
        },

        'the registry only knows implemented abilities' => static function (): void {
            expect_same(false, AbilityRegistry::isPlayable('Teleport'), 'Teleport is deferred');
            expect_same(null, AbilityRegistry::find(ability_unit('x', 'seat-a', '0,0', ['Teleport']), 'Teleport'), 'deferred abilities have no handler');
        },
    ];
}
