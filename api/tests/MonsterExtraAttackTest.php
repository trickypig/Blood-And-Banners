<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Wounds;

function monster_extra_attack_battle(string $name, int $wounds = 0): array
{
    return coop_battle([
        monster_unit('monster', $name, '1,0', ['power' => 1, 'wounds' => $wounds]),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('target', 'hero-a', '2,0', ['defense' => 0, 'hp' => 100]),
    ], 'void-lord');
}

function monster_extra_strike(array $state, string $target = 'target', ?array $rolls = null): array
{
    return coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => $target], $rolls ?? array_fill(0, 32, 6));
}

function monster_extra_actions(array $state, string $kind): array
{
    return array_values(array_filter(
        (new MatchEngine(test_catalog()))->legalActions($state, 'void-lord'),
        static fn(array $action): bool => $action['unitId'] === 'monster' && $action['kind'] === $kind
    ));
}

/** An unrelated special attack checks that Branch Attack excludes every special, not just column attacks. */
function monster_extra_spell_treant(): array
{
    $state = monster_extra_attack_battle('Treant');
    $state['units']['monster']['abilities'][] = ['name' => 'Flame Strike', 'type' => 'Attack', 'description' => 'Power 3, Range 5'];
    return $state;
}

function monster_extra_attack_tests(): array
{
    return [
        'extra attack budgets match every shipped hydra health boundary and the treant two strikes' => static function (): void {
            foreach ([
                ['Marsh Hydra', 0, 4], ['Marsh Hydra', 1, 3], ['Marsh Hydra', 2, 2], ['Marsh Hydra', 3, 1],
                ['Void Hydra', 0, 4], ['Void Hydra', 1, 4], ['Void Hydra', 2, 3], ['Void Hydra', 3, 3],
                ['Void Hydra', 4, 2], ['Void Hydra', 5, 2], ['Void Hydra', 6, 1],
                ['Treant', 0, 2], ['Treant', 4, 2],
            ] as [$name, $wounds, $attacks]) {
                $state = monster_extra_attack_battle($name, $wounds);
                for ($strike = 1; $strike <= $attacks; $strike++) {
                    expect_same(1, count(monster_extra_actions($state, 'attackUnit')), "{$name} with {$wounds} wounds offers strike {$strike}");
                    $state = monster_extra_strike($state)['state'];
                    expect_same($strike < $attacks ? 'monster' : null, $state['turn']['activeUnitId'], 'only the final permitted strike closes the activation');
                }
                expect_same([], monster_extra_actions($state, 'attackUnit'), 'the server no longer offers an exhausted attack');
                expect_game_error('unitAlreadyActivated', static fn() => monster_extra_strike($state));
                expect_same(['monster'], $state['turn']['activated'], 'extra strikes consume one commander activation');
            }
        },
        'hydra attack limits shrink when health changes during the activation' => static function (): void {
            foreach ([['Marsh Hydra', 2], ['Void Hydra', 4]] as [$name, $wounds]) {
                $state = monster_extra_strike(monster_extra_attack_battle($name))['state'];
                $events = [];
                $state = Wounds::apply($state, 'monster', $wounds, $events);
                expect_same(1, count(monster_extra_actions($state, 'attackUnit')), 'the wounded hydra still has its second attack');
                $state = monster_extra_strike($state)['state'];
                expect_same(null, $state['turn']['activeUnitId'], 'current health reduces the total budget to two');
                expect_same([], monster_extra_actions($state, 'attackUnit'), 'it cannot use its old healthy attack allowance');
            }
        },
        'a health drop below attacks already spent forbids another strike' => static function (): void {
            $state = monster_extra_strike(monster_extra_attack_battle('Void Hydra'))['state'];
            $state = monster_extra_strike($state)['state'];
            $events = [];
            $state = Wounds::apply($state, 'monster', 6, $events);
            expect_same([], monster_extra_actions($state, 'attackUnit'), 'a one-head hydra cannot attack after spending two strikes');
            expect_game_error('noAttacksLeft', static fn() => monster_extra_strike($state));
        },
        'a hydra can split its attacks among targets and each bite resolves poison separately' => static function (): void {
            $state = monster_extra_attack_battle('Marsh Hydra');
            $state['units']['other'] = coop_hero('other', 'hero-b', '0,0', ['side' => 'heroes', 'defense' => 0, 'hp' => 100]);
            $saveCount = 0;
            foreach (['target', 'other', 'target', 'other'] as $target) {
                $result = monster_extra_strike($state, $target, [1, 11]);
                $saveCount += count(array_filter($result['events'], static fn(array $event): bool => $event['kind'] === 'saveRolled'));
                $state = $result['state'];
            }
            expect_same([2, 2], [$state['units']['target']['wounds'], $state['units']['other']['wounds']], 'four strikes can alternate between two enemies');
            expect_same(4, $saveCount, 'every bite grants its own poison save');
            expect_same(null, $state['turn']['activeUnitId'], 'the fourth strike exhausts the hydra');
        },
        'movement must finish before the first extra attack' => static function (): void {
            $state = monster_extra_attack_battle('Marsh Hydra');
            $state['units']['target']['hex'] = '3,0';
            $state = coop_command($state, 'void-lord', 'moveUnit', ['unitId' => 'monster', 'to' => '2,0'])['state'];
            $state = monster_extra_strike($state)['state'];
            expect_same('monster', $state['turn']['activeUnitId'], 'movement does not spend the extra attacks');
            expect_same([], monster_extra_actions($state, 'moveUnit'), 'the hydra cannot move between attacks');
            expect_game_error('alreadyMoved', static fn() => coop_command($state, 'void-lord', 'moveUnit', ['unitId' => 'monster', 'to' => '1,0']));
        },
        'a poison kill between hydra attacks leaves the remaining attacks for another enemy' => static function (): void {
            $state = monster_extra_attack_battle('Marsh Hydra');
            $state['units']['target']['hp'] = 1;
            $state['units']['other'] = coop_hero('other', 'hero-b', '0,0', ['side' => 'heroes', 'defense' => 0, 'hp' => 100]);
            $first = monster_extra_strike($state, 'target', [6, 10, 1]);
            $state = $first['state'];
            expect_same(null, $state['units']['target']['hex'], 'immediate bite poison defeats the first target');
            expect_same('active', $state['phase'], 'another hero commander keeps the match active');
            expect_same('monster', $state['turn']['activeUnitId'], 'poison damage does not spend another hydra attack');
            expect_same(['0,0'], monster_extra_actions($state, 'attackUnit')[0]['targetHexes'], 'the remaining enemy becomes the available target');
            for ($strike = 2; $strike <= 4; $strike++) {
                $state = monster_extra_strike($state, 'other', [6, 11])['state'];
            }
            expect_same(null, $state['turn']['activeUnitId'], 'the hydra retains exactly three attacks after the poison kill');
        },
        'a saved hydra activation resumes after a glare choice without resetting the attack budget' => static function (): void {
            $state = monster_extra_attack_battle('Marsh Hydra');
            $state['units']['target']['defense'] = 1;
            $state['units']['target']['abilities'] = [['name' => 'Moonlight Glare', 'type' => 'Essence', 'description' => '']];
            $opened = monster_extra_strike($state, 'target', [1, 6]);
            expect_true($opened['state']['pending'] !== null, 'the first attack waits for the defender');
            $saved = json_decode(json_encode($opened['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $state = coop_command($saved, 'hero-a', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'keep'], [11])['state'];
            expect_same(1, $state['turn']['attacks'], 'the resumed attack is counted once');
            for ($strike = 2; $strike <= 4; $strike++) {
                $state = monster_extra_strike($state, 'target', [1, 4, 11])['state'];
            }
            expect_same(null, $state['turn']['activeUnitId'], 'only three attacks remain after reconnecting');
            expect_same([], monster_extra_actions($state, 'attackUnit'), 'reconnecting cannot replenish spent strikes');
        },
        'evaded extra attacks still consume the attack budget' => static function (): void {
            $state = monster_extra_attack_battle('Treant');
            $state['units']['target']['abilities'] = [['name' => 'Evasion', 'type' => 'Essence', 'description' => '']];
            $state = monster_extra_strike($state, 'target', [11])['state'];
            expect_same('monster', $state['turn']['activeUnitId'], 'one evaded strike leaves the second');
            $state = monster_extra_strike($state, 'target', [11])['state'];
            expect_same(null, $state['turn']['activeUnitId'], 'two evaded strikes spend the activation');
        },
        'branch attack forbids a special after a normal strike in both legal actions and command validation' => static function (): void {
            $state = monster_extra_spell_treant();
            expect_true(in_array('Flame Strike', array_column(monster_extra_actions($state, 'specialAttack'), 'ability'), true), 'a fresh treant may choose an unrelated special attack');
            $state = monster_extra_strike($state, 'target', [6])['state'];
            expect_same([], monster_extra_actions($state, 'specialAttack'), 'choosing a normal attack hides special attacks');
            expect_game_error('noAttacksLeft', static fn() => coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => 'Flame Strike', 'targetUnitIds' => ['target']], [6, 6, 6]));
            $state = monster_extra_strike($state, 'target', [6])['state'];
            expect_same(null, $state['turn']['activeUnitId'], 'a second normal attack is permitted');
        },
        'branch attack allows only one special attack and saves that choice across reconnects' => static function (): void {
            $result = coop_command(monster_extra_spell_treant(), 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => 'Flame Strike', 'targetUnitIds' => ['target']], [6, 6, 6]);
            $state = json_decode(json_encode($result['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_same(null, $state['turn']['activeUnitId'], 'one special spends the whole treant attack allowance');
            expect_same([], monster_extra_actions($state, 'attackUnit'), 'no normal attack follows a special');
            expect_same([], monster_extra_actions($state, 'specialAttack'), 'no second special follows it');
            expect_game_error('unitAlreadyActivated', static fn() => monster_extra_strike($state));
        },
        'a treant special choice does not leak into the next unit activation' => static function (): void {
            $state = monster_extra_spell_treant();
            $state['units']['second'] = monster_unit('second', 'Treant', '3,0', ['power' => 1]);
            $state = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => 'Flame Strike', 'targetUnitIds' => ['target']], [6, 6, 6])['state'];
            $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'second', 'targetUnitId' => 'target'], [6])['state'];
            expect_same('second', $state['turn']['activeUnitId'], 'the next treant still gets two normal attacks');
            $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'second', 'targetUnitId' => 'target'], [6])['state'];
            expect_same(null, $state['turn']['activeUnitId'], 'its second normal attack ends its activation');
        },
        'ending a multiattack activation early never permits reactivation during the same turn' => static function (): void {
            $state = monster_extra_strike(monster_extra_attack_battle('Marsh Hydra'))['state'];
            $state = coop_command($state, 'void-lord', 'endActivation', ['unitId' => 'monster'])['state'];
            expect_game_error('unitAlreadyActivated', static fn() => monster_extra_strike($state));
        },
        'a saved ice choice starts a fresh treant attack budget after another unit special attack' => static function (): void {
            $state = monster_extra_spell_treant();
            $state['units']['second'] = monster_unit('second', 'Treant', '3,0', ['power' => 1]);
            $state['units']['idle']['hex'] = '4,0';
            $state['board']['3,0']['terrain'] = 'ice';
            $state['board']['4,0']['terrain'] = 'ice';
            $events = [];
            $state = ice_condition($state, 'second', [1, 0], $events);
            $state = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => 'Flame Strike', 'targetUnitIds' => ['target']], [6, 6, 6])['state'];
            $opened = coop_command($state, 'void-lord', 'activateUnit', ['unitId' => 'second'])['state'];
            expect_same('iceCollision', $opened['pending']['choice']['kind'] ?? null, 'the new activation waits for its ice collision choice');
            $saved = json_decode(json_encode($opened, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $state = coop_command($saved, 'void-lord', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'stop'])['state'];
            $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'second', 'targetUnitId' => 'target'])['state'];
            expect_same('second', $state['turn']['activeUnitId'], 'the previous special never removes this treant second normal attack');
            $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'second', 'targetUnitId' => 'target'])['state'];
            expect_same(null, $state['turn']['activeUnitId'], 'the second normal attack completes the resumed activation');
        },
        'extra attack allowances reset on each void response turn' => static function (): void {
            $state = monster_extra_attack_battle('Void Hydra', 2);
            $state['units']['other'] = coop_hero('other', 'hero-b', '0,0', ['side' => 'heroes', 'hp' => 100]);
            for ($turn = 0; $turn < 2; $turn++) {
                for ($strike = 0; $strike < 3; $strike++) {
                    $state = monster_extra_strike($state)['state'];
                }
                expect_same(null, $state['turn']['activeUnitId'], 'the wounded hydra gets three attacks each turn');
                if ($turn === 0) {
                    $state = coop_command($state, 'void-lord', 'endTurn')['state'];
                    expect_same('hero-b', $state['turn']['activeSeatId'], 'the other hero acts between void responses');
                    $state = coop_command($state, 'hero-b', 'endTurn')['state'];
                    expect_same('void-lord', $state['turn']['activeSeatId'], 'the void lord responds again in the same round');
                }
            }
        },
        'existing double strike abilities retain their normal and special attack combinations' => static function (): void {
            foreach (['Dual Strike', 'Twin Strikes', 'Dual Strikes'] as $name) {
                foreach ([['attackUnit', 'specialAttack'], ['specialAttack', 'attackUnit']] as $order) {
                    $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                        ability_unit('attacker', 'seat-a', '0,0', [$name, 'Flame Strike'], ['power' => 1]),
                        vanilla_unit('idle', 'seat-a', '4,0'),
                        vanilla_unit('target', 'seat-b', '1,0', ['defense' => 0, 'hp' => 100]),
                    ]);
                    foreach ($order as $index => $kind) {
                        $payload = $kind === 'attackUnit'
                            ? ['unitId' => 'attacker', 'targetUnitId' => 'target']
                            : ['unitId' => 'attacker', 'ability' => 'Flame Strike', 'targetUnitIds' => ['target']];
                        $state = vanilla_command($state, 'seat-a', $kind, $payload, [6, 6, 6])['state'];
                        expect_same($index === 0 ? 'attacker' : null, $state['turn']['activeUnitId'], 'the new branch restriction never limits other double-strike abilities');
                    }
                }
            }
        },
        'older monster snapshots do not gain extra attacks merely by loading' => static function (): void {
            $state = monster_extra_attack_battle('Marsh Hydra');
            $state['units']['monster']['abilities'] = array_values(array_filter($state['units']['monster']['abilities'], static fn(array $ability): bool => $ability['name'] !== 'Multiple Heads'));
            $state = monster_extra_strike($state)['state'];
            expect_same(null, $state['turn']['activeUnitId'], 'the saved unit still has its original one attack');
        },
        'new extra attack monsters enable only the supported catalog abilities' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            foreach ([
                ['Marsh Hydra', ['Multiple Heads', 'Poisonous Bite', 'Outrage'], []],
                ['Void Hydra', ['Many Heads', 'Outrage'], []],
                ['Treant', ['Column Swing', 'Column Toss', 'Branch Attack'], ["Nature's Wrath"]],
            ] as [$name, $active, $inactive]) {
                $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $unit = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => '7,0'], new SequenceRandom([]))['state']['units']['v-m1'];
                expect_same($active, array_column($unit['abilities'], 'name'), 'all verified abilities appear on the active card');
                expect_same($inactive, array_column($unit['inactiveAbilities'], 'name'), 'unimplemented special attacks and passives remain deferred');
            }
        },
    ];
}
