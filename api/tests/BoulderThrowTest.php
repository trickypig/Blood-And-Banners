<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

function boulder_throw_tests(): array
{
    return [
        'boulder throw strikes at power three up to range three and pushes one hex without a save' => static function (): void {
            foreach (['2,0', '3,0', '4,0'] as $hex) {
                $state = monster_push_battle('Stoneking');
                $state['units']['target']['hex'] = $hex;
                $result = monster_push_attack($state, 'Boulder Throw', [1, 1, 1]);
                expect_same(3, $result['state']['units']['target']['wounds'], 'the boulder uses power three rather than the normal power four');
                expect_same(((int)$hex[0] + 1) . ',0', $result['state']['units']['target']['hex'], 'a surviving target moves exactly one hex');
                expect_same([], monster_push_events($result, 'saveRolled'), 'boulder knockback has no save');
                expect_same('Boulder Throw', monster_push_events($result, 'attackResolved')[0]['payload']['ability'], 'the battle log identifies the special attack');
            }
        },
        'boulder throw pushes away in all six directions including off axis and tied angles' => static function (): void {
            foreach ([
                ['3,0', '4,0'], ['3,-3', '4,-4'], ['0,-3', '0,-4'],
                ['-3,0', '-4,0'], ['-3,3', '-4,4'], ['0,3', '0,4'],
                ['2,1', '3,1'], ['1,2', '1,3'], ['-1,3', '-1,4'],
                ['-2,3', '-3,4'], ['-3,1', '-4,1'], ['-3,2', '-4,3'],
                ['2,-1', '3,-1'], ['1,1', '2,1'], ['-1,-1', '-1,-2'],
            ] as [$target, $destination]) {
                $state = monster_push_battle('Stoneking');
                $state['units']['monster']['hex'] = '0,0';
                $state['units']['target']['hex'] = $target;
                $hexes = [];
                for ($q = -5; $q <= 5; $q++) {
                    for ($r = -5; $r <= 5; $r++) {
                        $hexes[$q . ',' . $r] = 0;
                    }
                }
                $state['board'] += vanilla_board($hexes);
                $result = monster_push_attack($state, 'Boulder Throw', [6, 6, 6]);
                expect_same($destination, $result['state']['units']['target']['hex'], 'the push follows the closest direction away from the attacker');
            }
        },
        'boulder throw pushes a fully defended target and applies ranged defense at distance' => static function (): void {
            $state = monster_push_battle('Stoneking', ['defense' => 1, 'abilities' => [['name' => 'Stoneskin']]]);
            $state['units']['target']['hex'] = '4,0';
            $result = monster_push_attack($state, 'Boulder Throw', [1, 1, 1, 4, 4, 4]);
            $hit = monster_push_events($result, 'attackResolved')[0]['payload'];
            expect_same(3, count($hit['defenseFaces']), 'the ranged attack includes the two extra Stoneskin defense dice');
            expect_same(0, $result['state']['units']['target']['wounds'], 'all three swords are blocked');
            expect_same('5,0', $result['state']['units']['target']['hex'], 'blocking wounds does not block knockback');
            expect_same([], monster_push_events($result, 'saveRolled'), 'a blocked attack has no save either');
        },
        'boulder throw rejects out of range obstructed allied defeated and multiple targets' => static function (): void {
            $state = monster_push_battle('Stoneking');
            $state['units']['target']['hex'] = '5,0';
            expect_game_error('invalidAbilityTarget', static fn() => monster_push_attack($state, 'Boulder Throw'));
            $state['units']['target']['hex'] = '4,0';
            $state['board']['3,0']['sightBlocker'] = true;
            expect_game_error('invalidAbilityTarget', static fn() => monster_push_attack($state, 'Boulder Throw'));
            $state['board']['3,0']['sightBlocker'] = false;
            $state['units']['idle']['hex'] = '0,0';
            expect_game_error('invalidAbilityTarget', static fn() => monster_push_attack($state, 'Boulder Throw', [], 'idle'));
            foreach ([[], ['target', 'idle'], ['missing']] as $targets) {
                expect_game_error('invalidAbilityTarget', static fn() => coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => 'Boulder Throw', 'targetUnitIds' => $targets]));
            }
            $state['units']['target']['hex'] = null;
            expect_game_error('invalidAbilityTarget', static fn() => monster_push_attack($state, 'Boulder Throw'));
        },
        'boulder throw and the normal attack share one activation budget' => static function (): void {
            $state = monster_push_battle('Stoneking');
            $special = monster_push_attack($state, 'Boulder Throw', [6, 6, 6])['state'];
            expect_game_error('unitAlreadyActivated', static fn() => coop_command($special, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target']));
            expect_game_error('unitAlreadyActivated', static fn() => monster_push_attack($special, 'Boulder Throw'));
            $normal = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target'], [1, 1, 1, 1]);
            expect_same(4, $normal['state']['units']['target']['wounds'], 'the normal attack retains power four');
            expect_same('2,0', $normal['state']['units']['target']['hex'], 'a normal attack does not throw a boulder or push');
            expect_game_error('unitAlreadyActivated', static fn() => monster_push_attack($normal['state'], 'Boulder Throw'));
        },
        'boulder throw uses collision and terrain entry rules for its single push' => static function (): void {
            $state = monster_push_battle('Stoneking');
            $state['units']['target']['hex'] = '4,0';
            $state['units']['idle']['hex'] = '5,0';
            $collision = monster_push_attack($state, 'Boulder Throw', [6, 6, 6, 1, 1]);
            expect_same('4,0', $collision['state']['units']['target']['hex'], 'an occupied destination stops the push');
            expect_same([1, 1], [$collision['state']['units']['target']['wounds'], $collision['state']['units']['idle']['wounds']], 'the collision wounds both units');
            $state['units']['idle']['hex'] = '8,0';
            $state['board']['5,0']['sightBlocker'] = true;
            $wall = monster_push_attack($state, 'Boulder Throw', [6, 6, 6, 1]);
            expect_same('4,0', $wall['state']['units']['target']['hex'], 'a wall stops the push');
            expect_same(1, $wall['state']['units']['target']['wounds'], 'the wall collision rolls one undefended die');
            $state['board']['5,0']['sightBlocker'] = false;
            $state['board']['4,0']['elevation'] = 2;
            $fall = monster_push_attack($state, 'Boulder Throw', [6, 6, 6, 1, 1]);
            expect_same(2, $fall['state']['units']['target']['wounds'], 'a two-level fall rolls two undefended dice');
            $state['board']['4,0']['elevation'] = 0;
            foreach (['poison-pits', 'lava', 'ice'] as $terrain) {
                $state['board']['5,0']['terrain'] = $terrain;
                $result = monster_push_attack($state, 'Boulder Throw', array_fill(0, $terrain === 'lava' ? 8 : 3, 1));
                expect_same('5,0', $result['state']['units']['target']['hex'], 'the boulder pushes exactly one hex onto terrain');
                expect_same($terrain === 'lava' ? 8 : 3, $result['state']['units']['target']['wounds'], 'lava entry rolls five more undefended dice');
                expect_same($terrain === 'poison-pits', UnitConditions::has($result['state'], 'target', 'Poisoned'), 'poison entry applies its condition');
                expect_same($terrain === 'ice', UnitConditions::has($result['state'], 'target', 'Slipping'), 'ice entry preserves the forced slide direction');
            }
        },
        'boulder throw is projected at range three after reconnect without extending the normal attack' => static function (): void {
            $state = monster_push_battle('Stoneking');
            $state['units']['target']['hex'] = '4,0';
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $actions = array_values(array_filter((new BattleRules(100))->legalActions($state, 'void-lord'), static fn(array $a): bool => ($a['unitId'] ?? '') === 'monster'));
            $special = array_values(array_filter($actions, static fn(array $a): bool => ($a['ability'] ?? '') === 'Boulder Throw'));
            expect_same(1, count($special), 'the attack remains available after loading the saved state');
            expect_same(['4,0'], $special[0]['targetHexes'], 'the special attack reaches the enemy at range three');
            expect_same([], array_values(array_filter($actions, static fn(array $a): bool => $a['kind'] === 'attackUnit')), 'the normal melee attack cannot reach that enemy');
        },
    ];
}
