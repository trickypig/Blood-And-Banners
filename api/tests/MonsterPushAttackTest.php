<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

function monster_push_battle(string $name = 'Dune Wyvern', array $target = []): array
{
    return coop_battle([
        monster_unit('monster', $name, '1,0'),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('target', 'hero-a', '2,0', $target + ['defense' => 0, 'hp' => 100]),
    ], 'void-lord');
}

function monster_push_attack(array $state, string $ability = 'Tail Slash', array $rolls = [], string $target = 'target'): array
{
    return coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => $ability, 'targetUnitIds' => [$target]], $rolls);
}

function monster_push_events(array $result, string $kind): array
{
    return array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === $kind));
}

function monster_push_attack_tests(): array
{
    return [
        'tail slash rolls power four and saves on eleven for exactly one hex of knockback' => static function (): void {
            foreach ([1, 10, 11, 20] as $save) {
                $result = monster_push_attack(monster_push_battle(), 'Tail Slash', [1, 1, 1, 1, $save]);
                expect_same(4, $result['state']['units']['target']['wounds'], 'tail slash has power four');
                expect_same($save < 11 ? '3,0' : '2,0', $result['state']['units']['target']['hex'], 'even a natural one only pushes one hex');
                expect_same($save >= 11, monster_push_events($result, 'saveRolled')[0]['payload']['saved'], 'the save result is logged');
            }
        },
        'tail slash and one bite may be used in either order on the same or different targets' => static function (): void {
            foreach ([false, true] as $different) {
                foreach ([['attackUnit', 'specialAttack'], ['specialAttack', 'attackUnit']] as $order) {
                    $state = monster_push_battle();
                    $state['board'] += vanilla_board(['1,1' => 0]);
                    $state['units']['other'] = coop_hero('other', 'hero-b', '1,1', ['side' => 'heroes', 'defense' => 0, 'hp' => 100]);
                    foreach ($order as $index => $kind) {
                        $target = $different && $index === 1 ? 'other' : 'target';
                        $result = $kind === 'attackUnit'
                            ? coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => $target], [6, 6, 6])
                            : monster_push_attack($state, 'Tail Slash', [6, 6, 6, 6, 11], $target);
                        $state = $result['state'];
                        expect_same($index === 0 ? 'monster' : null, $state['turn']['activeUnitId'], 'only the second attack completes the activation');
                    }
                }
            }
        },
        'wyvern cannot spend its separate attack budgets on two bites or two tail slashes' => static function (): void {
            $state = monster_push_battle();
            $bitten = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target'], [6, 6, 6]);
            expect_game_error('noAttacksLeft', static fn() => coop_command($bitten['state'], 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target']));
            $tail = monster_push_attack($state, 'Tail Slash', [6, 6, 6, 6, 11]);
            expect_game_error('abilityAlreadyUsed', static fn() => monster_push_attack($tail['state']));
            $bite = coop_command($tail['state'], 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target'], [6, 6, 6]);
            expect_game_error('unitAlreadyActivated', static fn() => monster_push_attack($bite['state']));
        },
        'a normal wyvern bite never rolls the tail save or pushes its victim' => static function (): void {
            $result = coop_command(monster_push_battle(), 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target'], [1, 1, 1]);
            expect_same(3, $result['state']['units']['target']['wounds'], 'a bite retains its printed power three');
            expect_same('2,0', $result['state']['units']['target']['hex'], 'a bite leaves the target in place');
            expect_same([], monster_push_events($result, 'saveRolled'), 'a bite has no knockback save');
        },
        'stone swat pushes a surviving defender two hexes automatically even when fully blocked' => static function (): void {
            $state = monster_push_battle('Stonekin Boulderfist', ['defense' => 4]);
            $result = monster_push_attack($state, 'Stone Swat', [1, 1, 1, 1, 4, 4, 4, 4]);
            expect_same(0, $result['state']['units']['target']['wounds'], 'four shields block four swords');
            expect_same('4,0', $result['state']['units']['target']['hex'], 'zero wounds still knock the target back two hexes');
            expect_same([], monster_push_events($result, 'saveRolled'), 'stone swat has no save');
            expect_game_error('unitAlreadyActivated', static fn() => coop_command($result['state'], 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target']));
        },
        'tail slash still forces its save when the attack deals no wounds' => static function (): void {
            $result = monster_push_attack(monster_push_battle(), 'Tail Slash', [6, 6, 6, 6, 10]);
            expect_same(0, $result['state']['units']['target']['wounds'], 'the attack rolls no swords');
            expect_same('3,0', $result['state']['units']['target']['hex'], 'the failed save still pushes');
        },
        'evasion and a lethal strike prevent the special knockback effect' => static function (): void {
            foreach ([['Dune Wyvern', 'Tail Slash'], ['Stonekin Boulderfist', 'Stone Swat']] as [$name, $ability]) {
                $evaded = monster_push_attack(monster_push_battle($name, ['abilities' => [['name' => 'Evasion']]]), $ability, [11]);
                expect_same('2,0', $evaded['state']['units']['target']['hex'], 'evaded strikes never push');
                expect_same([], monster_push_events($evaded, 'saveRolled'), 'evaded tail slash never rolls a save');
                $dead = monster_push_attack(monster_push_battle($name, ['hp' => 1]), $ability, [1, 6, 6, 6]);
                expect_same(null, $dead['state']['units']['target']['hex'], 'the defender is defeated');
                expect_same([], monster_push_events($dead, 'saveRolled'), 'defeated targets never roll saves');
                expect_same([], monster_push_events($dead, 'unitPushed'), 'defeated targets never move');
            }
        },
        'special pushes resolve only after a saved moonlight glare choice' => static function (): void {
            foreach ([['Dune Wyvern', 'Tail Slash'], ['Stonekin Boulderfist', 'Stone Swat']] as [$name, $ability]) {
                $state = monster_push_battle($name, ['defense' => 1, 'abilities' => [['name' => 'Moonlight Glare']]]);
                $opened = monster_push_attack($state, $ability, [1, 6, 6, 6, 6]);
                expect_true($opened['state']['pending'] !== null, 'the defense reroll pauses the hit');
                expect_same('2,0', $opened['state']['units']['target']['hex'], 'nothing pushes before the choice');
                $saved = json_decode(json_encode($opened['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $result = coop_command($saved, 'hero-a', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'reroll:0'], $ability === 'Tail Slash' ? [4, 10] : [4]);
                expect_same(0, $result['state']['units']['target']['wounds'], 'the rerolled shield prevents the attack wound');
                expect_same($ability === 'Tail Slash' ? '3,0' : '4,0', $result['state']['units']['target']['hex'], 'the special push follows the final attack result');
            }
        },
        'stone swat uses shared wall collision and falling damage rules' => static function (): void {
            $state = monster_push_battle('Stonekin Boulderfist');
            $state['board']['3,0']['sightBlocker'] = true;
            $wall = monster_push_attack($state, 'Stone Swat', [6, 6, 6, 6, 1]);
            expect_same(1, $wall['state']['units']['target']['wounds'], 'a wall collision rolls one undefended die');
            expect_same('2,0', $wall['state']['units']['target']['hex'], 'the wall prevents movement');
            $state['board']['3,0']['sightBlocker'] = false;
            $state['board']['2,0']['elevation'] = 2;
            $fall = monster_push_attack($state, 'Stone Swat', [6, 6, 6, 6, 1, 1]);
            expect_same(2, $fall['state']['units']['target']['wounds'], 'a two-level fall rolls two undefended dice');
            expect_same('4,0', $fall['state']['units']['target']['hex'], 'the remaining push step still resolves after a survivable fall');
        },
        'stone swat handles occupied destinations and poison lava and ice arrivals' => static function (): void {
            $state = monster_push_battle('Stonekin Boulderfist');
            $state['units']['idle']['hex'] = '3,0';
            $collision = monster_push_attack($state, 'Stone Swat', [6, 6, 6, 6, 1, 1]);
            expect_same([1, 1], [$collision['state']['units']['target']['wounds'], $collision['state']['units']['idle']['wounds']], 'both units take a collision die');
            foreach (['poison-pits', 'lava', 'ice'] as $terrain) {
                $state = monster_push_battle('Stonekin Boulderfist');
                $state['board']['3,0']['terrain'] = $terrain;
                $result = monster_push_attack($state, 'Stone Swat', array_fill(0, $terrain === 'lava' ? 9 : 4, 6));
                expect_same('4,0', $result['state']['units']['target']['hex'], 'forced entry keeps the two-hex push');
                expect_same($terrain === 'poison-pits', UnitConditions::has($result['state'], 'target', 'Poisoned'), 'poison arrival uses the shared resolver');
                expect_true(!UnitConditions::has($result['state'], 'target', 'Slipping'), 'leaving the single ice hex clears slipping');
            }
        },
        'both special attacks reject distant allied and extra targets without rolling' => static function (): void {
            foreach ([['Dune Wyvern', 'Tail Slash'], ['Stonekin Boulderfist', 'Stone Swat']] as [$name, $ability]) {
                $state = monster_push_battle($name);
                $state['units']['target']['hex'] = '3,0';
                expect_game_error('invalidAbilityTarget', static fn() => monster_push_attack($state, $ability));
                expect_game_error('invalidAbilityTarget', static fn() => monster_push_attack($state, $ability, [], 'idle'));
                $state['units']['target']['hex'] = '2,0';
                expect_game_error('invalidAbilityTarget', static fn() => coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => $ability, 'targetUnitIds' => ['target', 'idle']]));
            }
        },
        'wyvern legal actions expose only the remaining bite or tail slash after reconnect' => static function (): void {
            foreach (['bite', 'tail'] as $first) {
                $state = monster_push_battle();
                $result = $first === 'bite'
                    ? coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target'], [6, 6, 6])
                    : monster_push_attack($state, 'Tail Slash', [6, 6, 6, 6, 11]);
                $saved = json_decode(json_encode($result['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $actions = array_values(array_filter((new BattleRules(100))->legalActions($saved, 'void-lord'), static fn(array $a): bool => ($a['unitId'] ?? '') === 'monster'));
                expect_same($first === 'tail', in_array('attackUnit', array_column($actions, 'kind'), true), 'only an unused bite is offered');
                expect_same($first === 'bite', in_array('Tail Slash', array_column($actions, 'ability'), true), 'only an unused tail slash is offered');
            }
        },
        'tail slash allowances reset for another wyvern activation and the next void turn' => static function (): void {
            $state = monster_push_battle();
            $state['units']['second'] = monster_unit('second', 'Dune Wyvern', '3,0');
            $state['turn'] = \BloodAndBanners\Rules\Vanilla\Turn::fresh($state, 'void-lord', 1, 1);
            $first = monster_push_attack($state, 'Tail Slash', [6, 6, 6, 6, 11])['state'];
            $second = coop_command($first, 'void-lord', 'specialAttack', ['unitId' => 'second', 'ability' => 'Tail Slash', 'targetUnitIds' => ['target']], [6, 6, 6, 6, 11])['state'];
            expect_same('second', $second['turn']['activeUnitId'], 'a different wyvern retains its bite');
            $second = coop_command($second, 'void-lord', 'attackUnit', ['unitId' => 'second', 'targetUnitId' => 'target'], [6, 6, 6])['state'];
            $next = coop_command($second, 'void-lord', 'endTurn')['state'];
            $next = coop_command($next, 'hero-a', 'endTurn')['state'];
            $again = monster_push_attack($next, 'Tail Slash', [6, 6, 6, 6, 11]);
            expect_same('monster', $again['state']['turn']['activeUnitId'], 'the new void turn restores tail slash plus bite');
        },
        'a wyvern resumed from an ice collision starts its own bite and tail budgets' => static function (): void {
            $state = monster_push_battle();
            $state['units']['second'] = monster_unit('second', 'Dune Wyvern', '3,0');
            $state['units']['idle']['hex'] = '4,0';
            $state['board']['3,0']['terrain'] = 'ice';
            $state['board']['4,0']['terrain'] = 'ice';
            $state['turn'] = \BloodAndBanners\Rules\Vanilla\Turn::fresh($state, 'void-lord', 1, 1);
            $events = [];
            $state = ice_condition($state, 'second', [1, 0], $events);
            $state = monster_push_attack($state, 'Tail Slash', [6, 6, 6, 6, 11])['state'];
            $opened = coop_command($state, 'void-lord', 'activateUnit', ['unitId' => 'second'])['state'];
            expect_same('iceCollision', $opened['pending']['choice']['kind'] ?? null, 'activation pauses at the icy ally');
            $saved = json_decode(json_encode($opened, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $state = coop_command($saved, 'void-lord', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'stop'])['state'];
            // Unsteady reduces both attack pools by one while it remains on ice.
            $state = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'second', 'ability' => 'Tail Slash', 'targetUnitIds' => ['target']], [6, 6, 6, 11])['state'];
            expect_same('second', $state['turn']['activeUnitId'], 'the previous wyvern tail use never consumes this wyvern bite');
            $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'second', 'targetUnitId' => 'target'], [6, 6])['state'];
            expect_same(null, $state['turn']['activeUnitId'], 'both attacks complete the resumed activation');
        },
        'ending early cannot reactivate a wyvern and stun still reduces special attack power' => static function (): void {
            $state = monster_push_attack(monster_push_battle(), 'Tail Slash', [6, 6, 6, 6, 11])['state'];
            $state = coop_command($state, 'void-lord', 'endActivation', ['unitId' => 'monster'])['state'];
            expect_game_error('unitAlreadyActivated', static fn() => coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target']));
            $state = stun(monster_push_battle(), 'monster', 'target');
            $result = monster_push_attack($state, 'Tail Slash', [1, 1, 11]);
            expect_same(2, $result['state']['units']['target']['wounds'], 'the special attack applies the existing stun penalty');
        },
        'public wyvern commands preserve the remaining attack and used marker through retries and reconnects' => static function (): void {
            foreach (['bite', 'tail'] as $first) {
                $match = coop_service_match();
                $match['games'] = new \BloodAndBanners\Game\GameService($match['db'], null, new ScriptedRandom(), new MatchEngine(new Catalog()));
                vanilla_submit($match, 'host', 'claimVoidLord', []);
                coop_ready_all($match);
                $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
                $void = $match['host']['seat'];
                $hero = $match['hero1']['seat'];
                $state = monster_push_battle();
                $state['seats'] = [$hero, $void];
                $state['voidLordSeatId'] = $void;
                $state['initiative'] = ['rolls' => [$hero => [20]], 'order' => [$hero]];
                $state['sides'] = ['heroes' => ['kind' => 'heroes', 'seatIds' => [$hero]], 'void' => ['kind' => 'void', 'seatIds' => [$void]]];
                foreach ($state['units'] as &$unit) {
                    $unit['seatId'] = $unit['side'] === 'void' ? $void : $hero;
                }
                unset($unit);
                $state['turn'] = \BloodAndBanners\Rules\Vanilla\Turn::fresh($state, $void, 1, 1);
                $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')->execute([
                    'state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId'],
                ]);
                $match['db']->prepare("UPDATE games SET lifecycle = 'active' WHERE id = :game")->execute(['game' => $match['gameId']]);
                $rolls = $first === 'bite' ? [6, 6, 6, 6, 6, 6, 6, 11] : [6, 6, 6, 6, 11, 6, 6, 6];
                $match['games'] = new \BloodAndBanners\Game\GameService($match['db'], null, new SequenceRandom($rolls), new MatchEngine(new Catalog()));
                $bite = ['unitId' => 'monster', 'targetUnitId' => 'target'];
                $tail = ['unitId' => 'monster', 'ability' => 'Tail Slash', 'targetUnitIds' => ['target']];
                $kind = $first === 'bite' ? 'attackUnit' : 'specialAttack';
                $payload = $first === 'bite' ? $bite : $tail;
                $used = vanilla_submit($match, 'host', $kind, $payload, 'wyvern-first');
                $retry = vanilla_submit($match, 'host', $kind, $payload, 'wyvern-first');
                expect_same($used['version'], $retry['version'], 'the first attack is idempotent');
                $loaded = $match['games']->getGame($match['gameId'], $match['host']['identity']);
                $unit = array_column($loaded['units'], null, 'unitId')['monster'];
                expect_same($first === 'tail', $unit['abilities'][0]['usedThisTurn'], 'the used tail marker persists');
                $actions = array_values(array_filter($loaded['legalActions'], static fn(array $a): bool => ($a['unitId'] ?? '') === 'monster'));
                expect_same($first === 'tail', in_array('attackUnit', array_column($actions, 'kind'), true), 'only the remaining normal attack is projected');
                expect_same($first === 'bite', in_array('Tail Slash', array_column($actions, 'ability'), true), 'only the remaining special attack is projected');
                $finished = vanilla_submit($match, 'host', $first === 'bite' ? 'specialAttack' : 'attackUnit', $first === 'bite' ? $tail : $bite);
                expect_same(null, $finished['turn']['activeUnitId'], 'the second attack finishes the activation');
            }
        },
        'new wyverns and boulderfists enable their special attacks without upgrading old saves' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            foreach ([['Dune Wyvern', 'Tail Slash', []], ['Stonekin Boulderfist', 'Stone Swat', ['Stone Grip']]] as [$name, $ability, $deferred]) {
                $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $placed = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => '7,0'], new SequenceRandom([]))['state'];
                expect_true(in_array($ability, array_column($placed['units']['v-m1']['abilities'], 'name'), true), 'the special attack is enabled');
                expect_same($deferred, array_column($placed['units']['v-m1']['inactiveAbilities'], 'name'), 'unrelated abilities stay deferred');
                $placed['units']['v-m1']['abilities'] = [];
                expect_same([], MatchEngine::normalize($placed)['units']['v-m1']['abilities'], 'old units remain unchanged on load');
            }
        },
    ];
}
