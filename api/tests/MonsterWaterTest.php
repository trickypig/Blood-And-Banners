<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Turn;

function water_battle(string $name, string $active = 'void-lord', array $stats = []): array
{
    $state = coop_battle([
        monster_unit('water', $name, '0,0', $stats),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('enemy', 'hero-a', '4,0', ['range' => 6, 'power' => 1, 'defense' => 0, 'hp' => 20]),
    ], $active);
    $state['board'] = vanilla_board(vanilla_line(9));
    return $state;
}

function water_summon(array $state, string $hex = '1,0'): array
{
    return coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'water', 'ability' => 'Tentacle Regeneration', 'targetUnitIds' => ['water'], 'toHex' => $hex]);
}

function monster_water_tests(): array
{
    return [
        'merfolk paths stay in or adjacent to shallow or open water' => static function (): void {
            foreach (['Merman', 'Mermaid'] as $name) {
                foreach (['shallow-water', 'open-water'] as $terrain) {
                    $state = water_battle($name);
                    $state['board']['0,0']['terrain'] = $terrain;
                    expect_same(['1,0'], Movement::reachable($state, 'water'), 'merfolk may step onto the bank but cannot cross dry land');
                    expect_game_error('hexUnreachable', static fn() => coop_command($state, 'void-lord', 'moveUnit', ['unitId' => 'water', 'to' => '2,0']));
                }
            }
        },
        'merfolk ranged defense is contextual and sahuagin uses its separate affinity rule' => static function (): void {
            foreach (['Merman', 'Mermaid'] as $name) {
                $state = water_battle($name);
                $base = $state['units']['water']['defense'];
                expect_same($base + 2, Stats::effective($state, 'water', 'defense', ['attackerId' => 'enemy', 'defenderId' => 'water']), 'ranged defense increases');
                $state['units']['enemy']['hex'] = '1,0';
                expect_same($base, Stats::effective($state, 'water', 'defense', ['attackerId' => 'enemy', 'defenderId' => 'water']), 'adjacent attacks receive no bonus');
            }
            $state = water_battle('Sahuagin');
            expect_same([3, 2], [Stats::effective($state, 'water', 'move'), Stats::effective($state, 'water', 'power')], 'land reduces both stats');
            $state['board']['0,0']['terrain'] = 'shallow-water';
            expect_same([4, 3], [Stats::effective($state, 'water', 'move'), Stats::effective($state, 'water', 'power')], 'shallow water removes the land penalties');
            expect_same(2, Stats::effective($state, 'water', 'defense', ['attackerId' => 'enemy', 'defenderId' => 'water']), 'sahuagin has no merfolk defense bonus');
            expect_true(in_array('2,0', Movement::reachable($state, 'water'), true), 'sahuagin may move onto dry land');
        },
        'water bound enters open water without projected terrain warnings' => static function (): void {
            foreach (['Kraken', 'Kraken Tentacle (Lesser)', 'Kraken Tentacle (Greater)'] as $name) {
                $state = water_battle($name);
                $state['board']['1,0']['terrain'] = 'open-water';
                $actions = (new BattleRules(100))->legalActions($state, 'void-lord');
                $move = array_values(array_filter($actions, static fn(array $a): bool => $a['kind'] === 'moveUnit' && $a['unitId'] === 'water'))[0];
                $entry = array_values(array_filter($move['movementOptions'], static fn(array $o): bool => $o['destination'] === '1,0'))[0];
                expect_same([], $entry['hazards'], "{$name} enters water without a danger warning");
            }
        },
        'water bound uses its full move through shallow and open water' => static function (): void {
            foreach (['Kraken', 'Kraken Tentacle (Lesser)', 'Kraken Tentacle (Greater)'] as $name) {
                foreach (['shallow-water', 'open-water'] as $terrain) {
                    $state = water_battle($name, 'void-lord', ['move' => 4]);
                    $state['units']['enemy']['hex'] = '7,0';
                    foreach (range(1, 5) as $q) {
                        $state['board']["{$q},0"]['terrain'] = $q % 2 ? $terrain : 'shallow-water';
                    }
                    expect_same(['1,0', '2,0', '3,0', '4,0'], Movement::reachable($state, 'water'), "{$name} gets four normal steps through {$terrain}");
                    $option = Movement::canonical($state, 'water', '4,0');
                    expect_same(4, $option['cost'], 'each water step costs one Move');
                    expect_same([], $option['hazards'], 'the entire water route is safe');
                    $result = coop_command($state, 'void-lord', 'moveUnit', ['unitId' => 'water', 'to' => '4,0']);
                    expect_same('4,0', $result['state']['units']['water']['hex'], 'execution reaches the end without stopping at water entry');
                    expect_same([], array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'movementStopped')), 'water does not stop the move');
                    foreach (array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'terrainIgnored') as $event) {
                        expect_same('Water Bound', $event['payload']['ability'], 'the log names the water immunity correctly');
                    }
                }
            }
        },
        'water bound prohibits dry path steps but forced movement can strand the unit' => static function (): void {
            foreach (['Kraken', 'Kraken Tentacle (Lesser)', 'Kraken Tentacle (Greater)'] as $name) {
                $state = water_battle($name);
                $state['board']['0,0']['terrain'] = 'shallow-water';
                $state['board']['1,0']['terrain'] = 'shallow-water';
                expect_same(['1,0'], Movement::reachable($state, 'water'), 'only water steps are allowed');
                expect_game_error('hexUnreachable', static fn() => coop_command($state, 'void-lord', 'moveUnit', ['unitId' => 'water', 'to' => '2,0']));
                $events = [];
                $pushed = Forced::push($state, 'water', [1, 0], 2, 'enemy', new SequenceRandom([]), $events);
                expect_same('2,0', $pushed['units']['water']['hex'], 'forced movement ignores habitat restrictions');
                expect_same(['0,0', '1,0'], Movement::reachable($pushed, 'water'), 'a stranded creature can return through water');
            }
        },
        'submerged protects the allied kraken family until any member engages an enemy' => static function (): void {
            $state = water_battle('Kraken', 'hero-a', ['defense' => 0, 'hp' => 10]);
            $state['units']['tentacle'] = monster_unit('tentacle', 'Kraken Tentacle (Lesser)', '6,0', ['defense' => 0]);
            expect_true(!in_array('water', Combat::targets($state, 'enemy'), true) && !in_array('tentacle', Combat::targets($state, 'enemy'), true), 'both distant family members are submerged');
            expect_game_error('targetSubmerged', static fn() => coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'enemy', 'targetUnitId' => 'water'], []));
            $state['units']['enemy']['hex'] = '5,0';
            expect_true(in_array('water', Combat::targets($state, 'enemy'), true), 'engaging one tentacle exposes the kraken');
            $state['units']['tentacle']['hex'] = null;
            expect_true(!in_array('water', Combat::targets($state, 'enemy'), true), 'dead tentacles never keep the family exposed');
        },
        'submerged leaves melee legal and blocks ranged special strikes' => static function (): void {
            $state = water_battle('Kraken Tentacle (Lesser)', 'hero-a', ['defense' => 0, 'hp' => 10]);
            $state['units']['enemy']['abilities'] = [['name' => 'Flame Strike']];
            expect_true(!in_array('water', AbilityRegistry::get('Flame Strike')->targets($state, 'enemy'), true), 'special targeting respects immunity');
            $events = [];
            $result = (new StepRunner(new SequenceRandom([]), 100))->run($state, [StepRunner::strike('enemy', 'water', 3, 'Flame Strike')], $events);
            expect_same(0, $result['units']['water']['wounds'], 'queued ranged strikes do not bypass immunity');
            $state['units']['enemy']['hex'] = '1,0';
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'enemy', 'targetUnitId' => 'water'], [1]);
            expect_same(1, $result['state']['units']['water']['wounds'], 'an adjacent enemy may strike');
        },
        'submerged blocks ranged splash and spore attacks but not passive death spores' => static function (): void {
            $state = water_battle('Kraken', 'hero-a', ['defense' => 0]);
            $state['units']['enemy']['hex'] = '3,0';
            $events = [];
            $result = (new StepRunner(new SequenceRandom([]), 100))->run($state, [StepRunner::splash('1,0', 'excluded', 'Fireball', 'enemy')], $events);
            expect_same(0, $result['units']['water']['wounds'], 'ranged splash cannot damage an immune target');
            $state['units']['bomber'] = monster_unit('bomber', 'Shroom Bomb', '2,0', ['seatId' => 'hero-a', 'side' => 'heroes']);
            $state['turn'] = Turn::fresh($state, 'hero-a', 1, 0);
            $result = coop_command($state, 'hero-a', 'specialAttack', ['unitId' => 'bomber', 'ability' => 'Spore Bomb', 'targetUnitIds' => ['bomber']], []);
            expect_true(!\BloodAndBanners\Rules\Vanilla\UnitConditions::has($result['state'], 'water', 'Poisoned'), 'a distant spore attack is still a ranged attack');
            $state['units']['bomber'] = monster_unit('bomber', 'Myconid', '2,0', ['seatId' => 'hero-a', 'side' => 'heroes']);
            $events = [];
            $dead = \BloodAndBanners\Rules\Vanilla\Wounds::apply($state, 'bomber', 5, $events, new SequenceRandom([9]));
            expect_true(\BloodAndBanners\Rules\Vanilla\UnitConditions::has($dead, 'water', 'Poisoned'), 'passive release is not an attack');
        },
        'setup cannot exceed four living allied tentacles' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $board = coop_board();
            foreach (range(0, 4) as $r) {
                $board["7,{$r}"]['terrain'] = 'shallow-water';
            }
            $state = $engine->start(['hero-a', 'void-lord'], $board, new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            foreach (range(0, 3) as $r) {
                $state = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => 'Kraken Tentacle (Lesser)', 'hex' => "7,{$r}"], new SequenceRandom([]))['state'];
            }
            expect_game_error('hexNotEnterable', static fn() => $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => 'Kraken Tentacle (Greater)', 'hex' => '7,4'], new SequenceRandom([])));
        },
        'water twister measures range and visibility from a nearby water origin' => static function (): void {
            $state = water_battle('Ewashia, Mistress of Tides');
            $state['board']['1,0']['terrain'] = 'shallow-water';
            $ability = AbilityRegistry::get('Water Twister');
            expect_true($ability !== null, 'water twister has a handler');
            expect_same(['enemy'], $ability->targets($state, 'water'), 'a target four from ewashia is three from the adjacent water');
            $result = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'water', 'ability' => 'Water Twister', 'targetUnitIds' => ['enemy']], [1, 1, 1, 1]);
            expect_same(4, $result['state']['units']['enemy']['wounds'], 'the attack has power four');
            $hit = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'))[0];
            expect_same('water', $hit['payload']['damageType'], 'water damage reaches contextual defenses');
            expect_same('1,0', $hit['payload']['originHex'], 'the event records the water origin');
            $state['board']['2,0']['sightBlocker'] = true;
            expect_same([], $ability->targets($state, 'water'), 'water origin still needs a clear line of sight');
            $state['board']['1,0']['terrain'] = 'grass';
            expect_same([], $ability->targets($state, 'water'), 'no nearby water means no twister');
        },
        'water twister can see around the caster blocker and applies water vulnerability' => static function (): void {
            $state = water_battle('Ewashia, Mistress of Tides');
            $state['board'] = vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 0, '0,1' => 0, '1,1' => 0, '2,1' => 0, '8,0' => 0], ['1,0']);
            $state['board']['0,1']['terrain'] = 'open-water';
            $state['units']['enemy']['hex'] = '3,0';
            $state['units']['enemy']['defense'] = 1;
            $state['units']['enemy']['abilities'] = [['name' => 'Lava Walker']];
            expect_true(!Combat::visible($state, '0,0', '3,0'), 'caster sight is blocked');
            $result = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'water', 'ability' => 'Water Twister', 'targetUnitIds' => ['enemy']], [1, 1, 1, 1]);
            expect_same(4, $result['state']['units']['enemy']['wounds'], 'water origin sees the target and lava walker rolls no defense');
        },
        'water twister chooses the nearest valid origin rather than granting avoidable ranged defense' => static function (): void {
            $state = water_battle('Ewashia, Mistress of Tides');
            $state['board']['0,0']['terrain'] = 'open-water';
            $state['board']['1,0']['terrain'] = 'shallow-water';
            $state['units']['enemy']['hex'] = '2,0';
            $state['units']['enemy']['abilities'] = [['name' => 'Stoneskin']];
            $result = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'water', 'ability' => 'Water Twister', 'targetUnitIds' => ['enemy']], [1, 1, 1]);
            $hit = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'))[0]['payload'];
            expect_same('1,0', $hit['originHex'], 'the closest usable water hex is selected');
            expect_same([], $hit['defenseFaces'], 'an adjacent water origin avoids the ranged defense bonus');
            expect_same(3, $hit['wounds'], 'the caster still takes the ordinary standing open-water power penalty');
        },
        'tentacle regeneration summons the shipped lesser unit and defers its activation' => static function (): void {
            $state = water_battle('Kraken');
            $state['board']['0,0']['terrain'] = 'shallow-water';
            $state['board']['1,0']['terrain'] = 'shallow-water';
            $result = water_summon($state);
            $summoned = array_values(array_filter($result['state']['units'], static fn(array $u): bool => $u['name'] === 'Kraken Tentacle (Lesser)'));
            expect_same(1, count($summoned), 'one tentacle is summoned');
            $unit = $summoned[0];
            expect_same(['void-lord', 'void', '1,0', 2, 0], [$unit['seatId'], $unit['side'], $unit['hex'], $unit['hp'], $unit['wounds']], 'it has normal ownership and health');
            expect_same(['Water Bound', 'Submerged'], array_column($unit['abilities'], 'name'), 'its passives are enabled');
            expect_game_error('unitSummonedThisTurn', static fn() => coop_command($result['state'], 'void-lord', 'activateUnit', ['unitId' => $unit['unitId']]));
            expect_game_error('unitAlreadyActivated', static fn() => water_summon($result['state']));
            expect_same(['water'], $result['state']['turn']['activated'], 'summoning does not consume another activation slot');
            $saved = json_decode(json_encode($result['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $saved['turn'] = Turn::fresh($saved, 'void-lord', 2, 1);
            $active = coop_command($saved, 'void-lord', 'activateUnit', ['unitId' => $unit['unitId']]);
            expect_same($unit['unitId'], $active['state']['turn']['activeUnitId'], 'it can activate on the next void turn');
        },
        'regeneration requires adjacent empty unblocked water and caps four living allied tentacles' => static function (): void {
            $state = water_battle('Kraken');
            $state['board']['1,0']['terrain'] = 'shallow-water';
            foreach (['0,0', '2,0', '9,0'] as $hex) {
                expect_game_error('invalidDestination', static fn() => water_summon($state, $hex));
            }
            $occupied = $state;
            $occupied['units']['idle']['hex'] = '1,0';
            expect_game_error('invalidDestination', static fn() => water_summon($occupied));
            $blocked = $state;
            $blocked['board']['1,0']['sightBlocker'] = true;
            expect_game_error('invalidDestination', static fn() => water_summon($blocked));
            for ($i = 1; $i <= 4; $i++) {
                $state['units']["t{$i}"] = monster_unit("t{$i}", $i % 2 ? 'Kraken Tentacle (Lesser)' : 'Kraken Tentacle (Greater)', "0,{$i}");
            }
            expect_game_error('tentacleLimitReached', static fn() => water_summon($state));
            $state['units']['t1']['hex'] = null;
            expect_same(4, count(array_filter(water_summon($state)['state']['units'], static fn(array $u): bool => str_starts_with($u['name'], 'Kraken Tentacle') && $u['hex'] !== null)), 'defeated tentacles free a slot');
        },
        'regeneration projects destination hexes and never reuses a saved monster id' => static function (): void {
            $state = water_battle('Kraken');
            $state['board']['1,0']['terrain'] = 'shallow-water';
            $state['units']['v-m1'] = monster_unit('v-m1', 'Kraken Tentacle (Lesser)', null);
            $actions = (new BattleRules(100))->legalActions($state, 'void-lord');
            $summon = array_values(array_filter($actions, static fn(array $a): bool => ($a['ability'] ?? '') === 'Tentacle Regeneration'));
            expect_same(['1,0'], $summon[0]['targetHexes'], 'only empty water hexes are offered');
            $result = water_summon($state);
            expect_same(null, $result['state']['units']['v-m1']['hex'], 'the old defeated unit remains intact');
            expect_same('1,0', $result['state']['units']['v-m2']['hex'], 'a fresh id is used');
        },
        'water setup is habitat aware and enables only the approved visible abilities' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $board = coop_board();
            $board['7,0']['terrain'] = 'open-water';
            $board['7,1']['terrain'] = 'shallow-water';
            $board['8,4']['voidPortal'] = true;
            foreach ([['Kraken', '7,0'], ['Kraken Tentacle (Lesser)', '7,1'], ['Merman', '8,0'], ['Mermaid', '8,0'], ['Sahuagin', '8,8'], ['Ewashia, Mistress of Tides', '8,8']] as [$name, $hex]) {
                $state = $engine->start(['hero-a', 'void-lord'], $board, new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $actions = $engine->legalActions($state, 'void-lord');
                $placement = array_values(array_filter($actions, static fn(array $a): bool => ($a['monster'] ?? '') === $name));
                expect_true(in_array($hex, $placement[0]['targetHexes'] ?? [], true), "{$name} gets suitable spawn hexes");
                $unit = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => $hex], new SequenceRandom([]))['state']['units']['v-m1'];
                expect_true(count($unit['abilities']) >= 1, 'water mechanics are active');
                expect_same([], $unit['inactiveAbilities'], 'every water creature is fully supported');
                if (in_array($name, ['Merman', 'Mermaid', 'Kraken', 'Kraken Tentacle (Lesser)'], true)) {
                    expect_game_error('hexNotEnterable', static fn() => $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => '8,8'], new SequenceRandom([])));
                }
            }
            expect_same(null, (new Catalog())->monster('Greater Water Elemental'), 'hidden aquatic surge remains outside this slice');
        },
        'void reaches provides usable river and bank deployment hexes' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $state = $engine->start(['hero-a', 'void-lord'], Board::fromScenario(built_in_scenario('void-reaches')), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            $actions = $engine->legalActions($state, 'void-lord');
            foreach (['Kraken', 'Merman'] as $name) {
                $placement = array_values(array_filter($actions, static fn(array $a): bool => ($a['monster'] ?? '') === $name));
                expect_true(count($placement[0]['targetHexes'] ?? []) >= 4, "{$name} has multiple valid river deployment hexes");
            }
        },
        'public service persists summons marks them spent and handles command retries without duplicates' => static function (): void {
            $match = coop_service_match();
            $match['games'] = new \BloodAndBanners\Game\GameService($match['db'], null, new ScriptedRandom(), new MatchEngine(new Catalog()));
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $placement = array_values(array_filter($started['legalActions'], static fn(array $a): bool => ($a['monster'] ?? '') === 'Kraken'));
            expect_true(count($placement[0]['targetHexes'] ?? []) > 0, 'public setup projects river deployment for the kraken');
            $void = $match['host']['seat'];
            $hero = $match['hero1']['seat'];
            $state = water_battle('Kraken');
            $state['seats'] = [$hero, $void];
            $state['voidLordSeatId'] = $void;
            $state['initiative'] = ['rolls' => [$hero => [15]], 'order' => [$hero]];
            $state['sides'] = ['heroes' => ['kind' => 'heroes', 'seatIds' => [$hero]], 'void' => ['kind' => 'void', 'seatIds' => [$void]]];
            foreach ($state['units'] as &$unit) {
                $unit['seatId'] = $unit['side'] === 'void' ? $void : $hero;
            }
            unset($unit);
            $state['board']['0,0']['terrain'] = 'shallow-water';
            $state['board']['1,0']['terrain'] = 'shallow-water';
            $state['turn'] = Turn::fresh($state, $void, 1, 1);
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')->execute([
                'state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId'],
            ]);
            $match['db']->prepare("UPDATE games SET lifecycle = 'active' WHERE id = :game")->execute(['game' => $match['gameId']]);
            $payload = ['unitId' => 'water', 'ability' => 'Tentacle Regeneration', 'targetUnitIds' => ['water'], 'toHex' => '1,0'];
            $first = vanilla_submit($match, 'host', 'specialAttack', $payload, 'summon-retry');
            $again = vanilla_submit($match, 'host', 'specialAttack', $payload, 'summon-retry');
            expect_same($first['version'], $again['version'], 'retries return the cached result');
            $loaded = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $tentacles = array_values(array_filter($loaded['units'], static fn(array $u): bool => $u['name'] === 'Kraken Tentacle (Lesser)'));
            expect_same(1, count($tentacles), 'exactly one tentacle persists');
            expect_same(true, $tentacles[0]['activatedThisTurn'], 'the waiting tentacle displays as spent');
            expect_true(!in_array($tentacles[0]['unitId'], array_column($loaded['legalActions'], 'unitId'), true), 'it has no actions on its summoning turn');
        },
    ];
}
