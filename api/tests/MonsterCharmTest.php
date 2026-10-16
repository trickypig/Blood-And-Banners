<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

/** A Mermaid in the corner, a hero five hexes away and a second hero-a troop that the charm holds back. */
function charm_battle(string $targetHex = '5,0'): array
{
    $state = coop_battle([
        monster_unit('mermaid', 'Mermaid', '0,0'),
        monster_unit('idle', 'Ice Wolf', '8,8'),
        coop_hero('target', 'hero-a', $targetHex, ['move' => 2, 'defense' => 0, 'hp' => 10]),
        vanilla_unit('buddy', 'hero-a', '8,0', ['side' => 'heroes', 'move' => 2]),
        coop_hero('other', 'hero-b', '8,4'),
    ], 'void-lord');
    $state['board'] = coop_board();
    return $state;
}

function charm_cast(array $state, int $save, string $target = 'target'): array
{
    return coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'mermaid', 'ability' => "Siren's Song", 'targetUnitIds' => [$target]], [$save]);
}

/** End turns until hero-a is active. */
function charm_to_hero_a(array $state): array
{
    while ($state['turn']['activeSeatId'] !== 'hero-a') {
        $state = coop_command($state, $state['turn']['activeSeatId'], 'endTurn')['state'];
    }
    return $state;
}

function charm_actions(array $state, string $seatId = 'hero-a'): array
{
    return (new MatchEngine(new Catalog()))->legalActions($state, $seatId);
}

function monster_charm_tests(): array
{
    return [
        "siren's song reaches five hexes and charms on a save below fifteen" => static function (): void {
            $state = charm_battle();
            $song = array_values(array_filter(charm_actions($state, 'void-lord'), static fn(array $a): bool => ($a['ability'] ?? null) === "Siren's Song"));
            expect_same(['5,0'], $song[0]['targetHexes'] ?? null, 'the hero five hexes away is in range');
            expect_same([], array_values(array_filter(charm_actions(charm_battle('6,0'), 'void-lord'), static fn(array $a): bool => ($a['ability'] ?? null) === "Siren's Song")), 'six hexes is out of range');
            foreach ([[14, true], [15, false]] as [$save, $charmed]) {
                $result = charm_cast($state, $save);
                $condition = UnitConditions::named($result['state'], 'target', 'Charmed');
                expect_same($charmed, $condition !== null, "save {$save}");
                expect_same(0, $result['state']['units']['target']['wounds'], 'the song deals no damage');
                if ($charmed) {
                    expect_same('mermaid', $condition['sourceUnitId'], 'the charm remembers its Mermaid');
                }
            }
        },
        'a charmed unit must first move as close as possible to the mermaid' => static function (): void {
            $state = charm_to_hero_a(charm_cast(charm_battle(), 1)['state']);
            $actions = charm_actions($state);
            expect_same([['moveUnit', 'target', ['3,0']]], array_map(static fn(array $a): array => [$a['kind'], $a['unitId'], $a['targetHexes']], $actions), 'only the nearest hex is offered and the turn cannot end');
            expect_game_error('charmedUnitFirst', static fn() => coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'buddy', 'to' => '7,0']));
            expect_game_error('charmedUnitFirst', static fn() => coop_command($state, 'hero-a', 'endTurn'));
            expect_game_error('charmedMustApproach', static fn() => coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'target', 'to' => '4,0']));
            $activated = coop_command($state, 'hero-a', 'activateUnit', ['unitId' => 'target'])['state'];
            expect_game_error('charmedMustApproach', static fn() => coop_command($activated, 'hero-a', 'endActivation', ['unitId' => 'target']));
            $moved = coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'target', 'to' => '3,0']);
            expect_same('3,0', $moved['state']['units']['target']['hex'], 'the unit walks toward the Mermaid');
            expect_true(!UnitConditions::has($moved['state'], 'target', 'Charmed'), 'the charm ends with that activation');
            expect_true(in_array('conditionExpired', array_column($moved['events'], 'kind'), true), 'the end of the charm is logged');
            expect_same('7,0', coop_command($moved['state'], 'hero-a', 'moveUnit', ['unitId' => 'buddy', 'to' => '7,0'])['state']['units']['buddy']['hex'], 'other units are free again');
        },
        'a charmed unit cannot attack or make parting strikes' => static function (): void {
            $state = charm_to_hero_a(charm_cast(charm_battle(), 1)['state']);
            $state['units']['idle']['hex'] = '5,1';
            expect_game_error('unitCannotAttack', static fn() => coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'target', 'targetUnitId' => 'idle']));
            expect_same([], Movement::disengagedFrom($state, 'idle', '7,1'), 'the charmed hero lets the wolf walk away');
            expect_true(!in_array('attackUnit', array_column(charm_actions($state), 'kind'), true), 'no attack is offered');
        },
        'a charmed unit that cannot get any closer is not held back' => static function (): void {
            $state = charm_to_hero_a(charm_cast(charm_battle('1,0'), 1)['state']);
            $kinds = array_column(charm_actions($state), 'kind');
            expect_true(in_array('endTurn', $kinds, true), 'the turn may end');
            expect_same('7,0', coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'buddy', 'to' => '7,0'])['state']['units']['buddy']['hex'], 'other units may act');
            $target = array_values(array_filter(charm_actions($state), static fn(array $a): bool => $a['kind'] === 'moveUnit' && $a['unitId'] === 'target'));
            foreach ($target[0]['targetHexes'] ?? [] as $hex) {
                expect_same(1, \BloodAndBanners\Rules\Vanilla\Hex::distance($hex, '0,0'), 'it may only shuffle around the Mermaid');
            }
        },
        'the charm breaks when the mermaid dies' => static function (): void {
            $state = charm_to_hero_a(charm_cast(charm_battle(), 1)['state']);
            $state['units']['mermaid']['hex'] = null;
            expect_true(in_array('endTurn', array_column(charm_actions($state), 'kind'), true), 'the turn may end');
            $moved = coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'target', 'to' => '7,0']);
            expect_same('7,0', $moved['state']['units']['target']['hex'], 'the unit moves freely');
            expect_true(!UnitConditions::has($moved['state'], 'target', 'Charmed'), 'the orphaned charm is removed');
        },
        'an unused charm expires at the end of its owner next turn' => static function (): void {
            $state = charm_to_hero_a(charm_cast(charm_battle('1,0'), 1)['state']);
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_true(!UnitConditions::has($state, 'target', 'Charmed'), 'the charm does not linger');
        },
    ];
}
