<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\Modes\CoopSetup;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\StepRunner;

/** Hidden creatures are not in the catalog, so their abilities are tested on stand-in monsters. */
function hidden_monster(string $id, string $hex, array $abilityNames, array $stats = []): array
{
    return ability_unit($id, 'void-lord', $hex, $abilityNames, $stats + ['side' => 'void']);
}

function monster_defense_tests(): array
{
    return [
        'the new defensive essences stay active on summoned monsters' => static function (): void {
            foreach (['Burrow', 'Molten Shell', 'Darkness Veil', 'Unmovable', 'Aquatic Surge', 'Crushing Blow'] as $name) {
                expect_true(CoopSetup::isAbilityPlayable(['name' => $name, 'type' => 'Essence']), "{$name} is playable");
            }
        },
        'molten shell takes one less wound from every attack' => static function (): void {
            $state = coop_battle([
                hidden_monster('golem', '1,0', ['Molten Shell'], ['defense' => 0, 'hp' => 10]),
                coop_hero('melee', 'hero-a', '0,0', ['power' => 2]),
                coop_hero('archer', 'hero-b', '4,0', ['power' => 2, 'range' => 6]),
            ]);
            $hit = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'melee', 'targetUnitId' => 'golem'], [1, 1]);
            expect_same(1, $hit['state']['units']['golem']['wounds'], 'two swords become one wound');
            $resolved = array_values(array_filter($hit['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'))[0];
            expect_same(1, $resolved['payload']['wounds'], 'the reported wounds are after the reduction');
            expect_same(1, $resolved['payload']['woundsPrevented'], 'the prevented wound is reported');
            $graze = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'melee', 'targetUnitId' => 'golem'], [1, 6]);
            expect_same(0, $graze['state']['units']['golem']['wounds'], 'a single sword is absorbed');
            $events = [];
            $ranged = (new StepRunner(new SequenceRandom([1, 1]), 100))->run($state, [StepRunner::strike('archer', 'golem')], $events);
            expect_same(1, $ranged['units']['golem']['wounds'], 'ranged attacks are reduced too');
        },
        'darkness veil takes one less wound only from ranged attacks' => static function (): void {
            $state = coop_battle([
                hidden_monster('spectre', '1,0', ['Darkness Veil'], ['defense' => 0, 'hp' => 10]),
                coop_hero('melee', 'hero-a', '0,0', ['power' => 2]),
                coop_hero('archer', 'hero-b', '4,0', ['power' => 2, 'range' => 6]),
            ]);
            $events = [];
            $ranged = (new StepRunner(new SequenceRandom([1, 1]), 100))->run($state, [StepRunner::strike('archer', 'spectre')], $events);
            expect_same(1, $ranged['units']['spectre']['wounds'], 'a ranged hit loses one wound');
            $melee = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'melee', 'targetUnitId' => 'spectre'], [1, 1]);
            expect_same(2, $melee['state']['units']['spectre']['wounds'], 'an adjacent attacker is not ranged');
        },
        'burrow blocks ranged attacks while the worm is not engaged' => static function (): void {
            $state = coop_battle([
                monster_unit('worm', 'Slatescale Worm', '0,0', ['defense' => 0, 'hp' => 10]),
                coop_hero('archer', 'hero-a', '4,0', ['power' => 1, 'range' => 6]),
            ]);
            expect_true(!in_array('worm', Combat::targets($state, 'archer'), true), 'an unengaged worm cannot be targeted from range');
            expect_game_error('targetBurrowed', static fn() => coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'archer', 'targetUnitId' => 'worm'], []));
            $state['board']['0,1'] = $state['board']['0,0'];
            $state['board']['4,1'] = $state['board']['0,0'];
            $state['units']['blocker'] = coop_hero('blocker', 'hero-b', '0,1');
            expect_true(in_array('worm', Combat::targets($state, 'archer'), true), 'an adjacent enemy engages the worm');
            unset($state['units']['blocker']);
            $state['units']['ally'] = monster_unit('ally', 'Slatescale Worm', '4,1');
            expect_true(!in_array('worm', Combat::targets($state, 'archer'), true), 'another worm being engaged does not expose this one');
            $state['units']['archer']['hex'] = '1,0';
            expect_true(in_array('worm', Combat::targets($state, 'archer'), true), 'melee attacks are always allowed');
        },
        'unmovable ignores pushes and skips knockback saves' => static function (): void {
            $state = coop_battle([
                hidden_monster('giant', '1,0', ['Unmovable'], ['defense' => 0, 'hp' => 10, 'move' => 4]),
                ability_unit('basher', 'hero-a', '0,0', ['Knockback'], ['power' => 1, 'side' => 'heroes']),
            ]);
            $hit = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'basher', 'targetUnitId' => 'giant'], [1]);
            expect_same('1,0', $hit['state']['units']['giant']['hex'], 'knockback does not move it');
            expect_true(!in_array('saveRolled', array_column($hit['events'], 'kind'), true), 'no pointless knockback save');
            $events = [];
            $swatted = (new StepRunner(new SequenceRandom([]), 100))->run($state, [StepRunner::push('giant', 'basher', 2)], $events);
            expect_same('1,0', $swatted['units']['giant']['hex'], 'a queued push does nothing');
            $state['units']['basher']['abilities'] = [['name' => 'Gust of Wind', 'type' => 'Ability', 'description' => '']];
            expect_same([], AbilityRegistry::get('Gust of Wind')->targets($state, 'basher'), 'gust of wind cannot target it');
        },
        'crushing blow adds half the target base defense rounded up to normal attack power' => static function (): void {
            $state = coop_battle([
                hidden_monster('giant', '0,0', ['Crushing Blow'], ['power' => 2]),
                coop_hero('bulky', 'hero-a', '1,0', ['defense' => 3]),
                coop_hero('slight', 'hero-b', '0,1', ['defense' => 0]),
            ], 'void-lord');
            $state['board']['0,1'] = $state['board']['0,0'];
            $normal = ['attackerId' => 'giant', 'defenderId' => 'bulky', 'ability' => null];
            expect_same(4, Stats::effective($state, 'giant', 'power', $normal), 'defense 3 adds two dice');
            expect_same(2, Stats::effective($state, 'giant', 'power', ['defenderId' => 'slight'] + $normal), 'defense 0 adds nothing');
            expect_same(2, Stats::effective($state, 'giant', 'power'), 'no target means no bonus');
            expect_same(2, Stats::effective($state, 'giant', 'power', ['ability' => 'Boulder Throw'] + $normal), 'special attacks get no bonus');
            $events = [];
            $shielded = Effects::add($state, 'Shield Wall', 'bulky', 'bulky', 'defense', 2, 'hero-a', Effects::UNTIL_NEXT_TURN, $events);
            expect_same(4, Stats::effective($shielded, 'giant', 'power', $normal), 'temporary defense bonuses do not count');
            $state['units']['slight']['defense'] = 1;
            $state['units']['slight']['hp'] = 10;
            // Power 2 + ceil(1 / 2) = 3 attack dice, then one defense die.
            $struck = (new StepRunner(new SequenceRandom([1, 1, 1, 6]), 100))->run($state, [StepRunner::strike('giant', 'slight')], $events);
            expect_same(3, $struck['units']['slight']['wounds'], 'a real strike rolls the extra die');
        },
        'aquatic surge moves through water without stopping or extra cost' => static function (): void {
            $water = ['biome' => 'water', 'terrain' => 'open-water'];
            $state = coop_battle([
                hidden_monster('surge', '0,0', ['Aquatic Surge'], ['move' => 3]),
                hidden_monster('plain', '0,0', [], ['move' => 3]),
            ], 'void-lord');
            $state['board'] = vanilla_board(vanilla_line(5), [], ['1,0' => $water, '2,0' => $water]);
            unset($state['units']['plain']);
            expect_true(in_array('3,0', Movement::reachable($state, 'surge'), true), 'it crosses two water hexes to land beyond them');
            $state['units']['plain'] = hidden_monster('plain', '0,0', [], ['move' => 3]);
            unset($state['units']['surge']);
            expect_true(!in_array('3,0', Movement::reachable($state, 'plain'), true), 'a normal unit is stopped by the water');
        },
    ];
}
