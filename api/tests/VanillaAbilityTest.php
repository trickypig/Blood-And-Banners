<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
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
    ];
}
