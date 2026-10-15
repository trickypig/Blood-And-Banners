<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;

function monster_area_battle(string $name = 'Storm Giant'): array
{
    $state = coop_battle([
        monster_unit('caster', $name, '0,0'),
        monster_unit('idle', 'Ice Wolf', '6,3'),
        coop_hero('a', 'hero-a', '2,0', ['hp' => 20, 'defense' => 0]),
        coop_hero('b', 'hero-b', '2,1', ['hp' => 20, 'defense' => 0]),
        coop_hero('c', 'hero-a', '3,-1', ['hp' => 20, 'defense' => 0]),
        coop_hero('d', 'hero-b', '5,2', ['hp' => 20, 'defense' => 0]),
    ], 'void-lord', 1);
    $hexes = [];
    for ($q = 0; $q <= 6; $q++) {
        for ($r = -2; $r <= 3; $r++) {
            $hexes["{$q},{$r}"] = 0;
        }
    }
    $state['board'] = vanilla_board($hexes);
    return $state;
}

function monster_area_cast(array $state, array $targets, array $rolls = [], string $ability = 'Frost Storm'): array
{
    return coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'caster', 'ability' => $ability, 'targetUnitIds' => $targets], $rolls);
}

function monster_area_hits(array $result): array
{
    return array_values(array_map(static fn(array $e): array => $e['payload'], array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved')));
}

function monster_area_attack_tests(): array
{
    return [
        'frost storm uses five four or three power on every selected target' => static function (): void {
            foreach ([['a'], ['a', 'b'], ['c', 'a', 'b']] as $targets) {
                $power = 6 - count($targets);
                $result = monster_area_cast(monster_area_battle(), $targets, array_fill(0, count($targets) * $power, 1));
                $hits = monster_area_hits($result);
                expect_same($targets, array_column($hits, 'targetUnitId'), 'targets resolve in selected order');
                foreach ($hits as $hit) {
                    expect_same($power, count($hit['attackFaces']), 'every selected target uses the same reduced power');
                    expect_same($power, $hit['wounds'], 'each target independently defends against its attack');
                }
            }
        },
        'frost storm validates every target before rolling dice' => static function (): void {
            $state = monster_area_battle();
            foreach ([[], ['a', 'a'], ['a', 'b', 'c', 'd'], ['a', 'idle'], ['a', 'missing'], ['a', 'd']] as $targets) {
                expect_game_error('invalidAbilityTarget', static fn() => monster_area_cast($state, $targets));
            }
            $state['board']['1,0']['sightBlocker'] = true;
            expect_game_error('invalidAbilityTarget', static fn() => monster_area_cast($state, ['b', 'a']));
        },
        'frost storm replaces the normal attack and normal attacks cannot be followed by the storm' => static function (): void {
            $state = monster_area_battle();
            $state['units']['a']['hex'] = '1,0';
            $cast = monster_area_cast($state, ['a'], [6, 6, 6, 6, 6]);
            expect_game_error('unitAlreadyActivated', static fn() => coop_command($cast['state'], 'void-lord', 'attackUnit', ['unitId' => 'caster', 'targetUnitId' => 'a']));
            $normal = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'caster', 'targetUnitId' => 'a'], [6, 6, 6, 6, 6]);
            expect_game_error('unitAlreadyActivated', static fn() => monster_area_cast($normal['state'], ['a']));
        },
        'frost storm cooldown survives saves and skips exactly the next void response' => static function (): void {
            $cast = monster_area_cast(monster_area_battle(), ['a'], [6, 6, 6, 6, 6]);
            $state = coop_command($cast['state'], 'void-lord', 'endTurn')['state'];
            expect_same('hero-b', $state['turn']['activeSeatId'], 'the other hero acts between void responses');
            $state = coop_command($state, 'hero-b', 'endTurn')['state'];
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_game_error('abilityCoolingDown', static fn() => monster_area_cast($state, ['a']));
            $storm = array_filter((new BattleRules(100))->legalActions($state, 'void-lord'), static fn(array $a): bool => ($a['ability'] ?? '') === 'Frost Storm');
            expect_same([], array_values($storm), 'cooling down storms are not advertised as legal');
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            $again = monster_area_cast($state, ['a'], [6, 6, 6, 6, 6]);
            expect_same(1, count(monster_area_hits($again)), 'the following void response may use it again');
        },
        'frost storm pending glare preserves the remaining targets and starts cooldown before the choice' => static function (): void {
            $state = monster_area_battle();
            $state['units']['a']['abilities'] = [['name' => 'Moonlight Glare']];
            $state['units']['a']['defense'] = 1;
            $opened = monster_area_cast($state, ['a', 'b'], [1, 1, 1, 1, 6]);
            expect_true($opened['state']['pending'] !== null, 'a defense reroll pauses the first target');
            $saved = json_decode(json_encode($opened['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $result = coop_command($saved, 'hero-a', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'keep'], [1, 1, 1, 1]);
            expect_same(['a', 'b'], array_column(monster_area_hits($result), 'targetUnitId'), 'both attacks complete after reconnect');
            $state = coop_command($result['state'], 'void-lord', 'endTurn')['state'];
            $state = coop_command($state, 'hero-b', 'endTurn')['state'];
            expect_game_error('abilityCoolingDown', static fn() => monster_area_cast($state, ['b']));
        },
        'frost cooldown follows owning turns even when another hero seat is wiped out' => static function (): void {
            $state = monster_area_battle();
            $state['units']['b']['hp'] = 1;
            $state['units']['d']['hex'] = null;
            $cast = monster_area_cast($state, ['b'], [1, 6, 6, 6, 6]);
            $state = coop_command($cast['state'], 'void-lord', 'endTurn')['state'];
            expect_same('hero-a', $state['turn']['activeSeatId'], 'the eliminated hero-b seat is skipped');
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_game_error('abilityCoolingDown', static fn() => monster_area_cast($state, ['a']));
        },
        'pyromancer fireball uses normal primary power and two no defense dice on selected enemies only' => static function (): void {
            $state = monster_area_battle('Pyromancer');
            $state['units']['b']['defense'] = 50;
            $state['units']['c']['defense'] = 50;
            $state['units']['idle']['hex'] = '1,1';
            $result = monster_area_cast($state, ['a', 'b', 'c'], [1, 1, 1, 1, 1, 1, 1, 1, 1, 6], 'Fireball');
            expect_same(6, $result['state']['units']['a']['wounds'], 'the primary attack uses the printed normal power');
            expect_same(2, $result['state']['units']['b']['wounds'], 'the first secondary rolls two dice with no defense');
            expect_same(1, $result['state']['units']['c']['wounds'], 'the second secondary rolls independently');
            expect_same(0, $result['state']['units']['idle']['wounds'], 'adjacent allies are never included');
            expect_same(0, $result['state']['units']['d']['wounds'], 'unselected enemies are never included');
        },
        'pyromancer secondary targets are optional unique adjacent enemies and validated before the primary roll' => static function (): void {
            $state = monster_area_battle('Pyromancer');
            foreach ([[], ['a', 'a'], ['a', 'b', 'c', 'd'], ['a', 'idle'], ['a', 'd'], ['missing']] as $targets) {
                expect_game_error('invalidAbilityTarget', static fn() => monster_area_cast($state, $targets, [], 'Fireball'));
            }
            $result = monster_area_cast($state, ['a'], [6, 6, 6, 6, 6, 6], 'Fireball');
            expect_same(0, $result['state']['units']['b']['wounds'], 'using no secondary targets is legal');
        },
        'pyromancer fireball primary uses effective normal range and fire defenses' => static function (): void {
            $state = monster_area_battle('Pyromancer');
            $state['units']['caster']['range'] = 1;
            expect_game_error('invalidAbilityTarget', static fn() => monster_area_cast($state, ['a'], [], 'Fireball'));
            $state['units']['caster']['range'] = 4;
            $state['units']['a']['defense'] = 1;
            $state['units']['a']['abilities'] = [['name' => 'Lava Walker']];
            $result = monster_area_cast($state, ['a'], array_fill(0, 9, 6), 'Fireball');
            $hit = monster_area_hits($result)[0];
            expect_same([6, 3, 'fire'], [count($hit['attackFaces']), count($hit['defenseFaces']), $hit['damageType']], 'the primary is a full fire strike');
        },
        'selected secondary fireball damage still lands after the primary is defeated' => static function (): void {
            $state = monster_area_battle('Pyromancer');
            $state['units']['a']['hp'] = 1;
            $result = monster_area_cast($state, ['a', 'b'], [1, 6, 6, 6, 6, 6, 1, 1], 'Fireball');
            expect_same(null, $result['state']['units']['a']['hex'], 'the primary is defeated');
            expect_same(2, $result['state']['units']['b']['wounds'], 'selected splash follows the original target hex');
        },
        'pyromancer splash resumes after a saved glare choice and needs no secondary line of sight' => static function (): void {
            $state = monster_area_battle('Pyromancer');
            $state['units']['a']['abilities'] = [['name' => 'Moonlight Glare']];
            $state['units']['a']['defense'] = 1;
            $state['board']['1,1']['sightBlocker'] = true;
            $state['board']['2,-1']['sightBlocker'] = true;
            $opened = monster_area_cast($state, ['a', 'b', 'c'], [1, 1, 1, 1, 1, 1, 6], 'Fireball');
            expect_true($opened['state']['pending'] !== null, 'primary defense may pause splash');
            $saved = json_decode(json_encode($opened['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $result = coop_command($saved, 'hero-a', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'keep'], [1, 1, 1, 1]);
            expect_same([2, 2], [$result['state']['units']['b']['wounds'], $result['state']['units']['c']['wounds']], 'both selected victims are retained through the save');
        },
        'frost and pyromancer splash reject submerged enemies before any attack dice' => static function (): void {
            foreach ([['Storm Giant', 'Frost Storm', ['b']], ['Pyromancer', 'Fireball', ['a', 'b']]] as [$name, $ability, $targets]) {
                $state = monster_area_battle($name);
                $state['units']['b']['name'] = 'Kraken';
                $state['units']['b']['abilities'] = [['name' => 'Submerged']];
                expect_game_error('invalidAbilityTarget', static fn() => monster_area_cast($state, $targets, [], $ability));
            }
        },
        'area attack projections provide independent and adjacent target selection rules' => static function (): void {
            foreach ([['Storm Giant', 'Frost Storm', 'independent'], ['Pyromancer', 'Fireball', 'adjacentEnemies']] as [$name, $ability, $mode]) {
                $actions = (new BattleRules(100))->legalActions(monster_area_battle($name), 'void-lord');
                $action = array_values(array_filter($actions, static fn(array $a): bool => ($a['ability'] ?? '') === $ability))[0] ?? [];
                expect_same($mode, $action['targetSelection']['mode'] ?? null, 'the client receives the appropriate selection flow');
                expect_same(3, $action['targetSelection']['maxTargets'] ?? null, 'both flows permit three total targets');
                if ($name === 'Pyromancer') {
                    $secondaries = (array)$action['targetSelection']['secondaryTargets'];
                    expect_same(['2,1', '3,-1'], $secondaries['2,0'], 'secondary candidates are projected by primary hex');
                }
            }
        },
        'new area monsters gain supported attacks while old snapshots stay unchanged' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            foreach ([['Storm Giant', 'Frost Storm'], ['Pyromancer', 'Fireball']] as [$name, $ability]) {
                $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $placed = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => '7,0'], new SequenceRandom([]))['state'];
                expect_same([$ability], array_column($placed['units']['v-m1']['abilities'], 'name'), 'the new attack is enabled');
                expect_same([], $placed['units']['v-m1']['inactiveAbilities'], 'no abilities remain deferred on this creature');
                $placed['units']['v-m1']['inactiveAbilities'] = $placed['units']['v-m1']['abilities'];
                $placed['units']['v-m1']['abilities'] = [];
                expect_same([], MatchEngine::normalize($placed)['units']['v-m1']['abilities'], 'loading never upgrades an old unit');
            }
        },
        'public frost storm selection and cooldown persist through commands retries and reconnects' => static function (): void {
            $match = coop_service_match(2);
            $match['games'] = new \BloodAndBanners\Game\GameService($match['db'], null, new ScriptedRandom(), new MatchEngine(new Catalog()));
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $void = $match['host']['seat'];
            $heroA = $match['hero1']['seat'];
            $heroB = $match['hero2']['seat'];
            $seats = ['void-lord' => $void, 'hero-a' => $heroA, 'hero-b' => $heroB];
            $state = monster_area_battle();
            $state['seats'] = [$heroA, $heroB, $void];
            $state['voidLordSeatId'] = $void;
            $state['initiative'] = ['rolls' => [$heroA => [20], $heroB => [10]], 'order' => [$heroA, $heroB]];
            $state['sides'] = ['heroes' => ['kind' => 'heroes', 'seatIds' => [$heroA, $heroB]], 'void' => ['kind' => 'void', 'seatIds' => [$void]]];
            foreach ($state['units'] as &$unit) {
                $unit['seatId'] = $seats[$unit['seatId']];
            }
            unset($unit);
            $state['turn'] = Turn::fresh($state, $void, 1, 1);
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')->execute([
                'state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId'],
            ]);
            $match['db']->prepare("UPDATE games SET lifecycle = 'active' WHERE id = :game")->execute(['game' => $match['gameId']]);
            $before = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $storm = array_values(array_filter($before['legalActions'], static fn(array $a): bool => ($a['ability'] ?? '') === 'Frost Storm'))[0];
            expect_same('independent', $storm['targetSelection']['mode'], 'selection rules reach the public client');
            $payload = ['unitId' => 'caster', 'ability' => 'Frost Storm', 'targetUnitIds' => ['a', 'b']];
            $cast = vanilla_submit($match, 'host', 'specialAttack', $payload, 'frost-retry');
            $retry = vanilla_submit($match, 'host', 'specialAttack', $payload, 'frost-retry');
            expect_same($cast['version'], $retry['version'], 'retry returns the original cast');
            $loaded = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $caster = array_column($loaded['units'], null, 'unitId')['caster'];
            expect_same(true, $caster['abilities'][0]['coolingDown'] ?? false, 'cooldown reaches the card after reconnect');
            vanilla_submit($match, 'host', 'endTurn', []);
            vanilla_submit($match, 'hero2', 'endTurn', []);
            expect_game_error('abilityCoolingDown', static fn() => vanilla_submit($match, 'host', 'specialAttack', $payload));
            vanilla_submit($match, 'host', 'endTurn', []);
            vanilla_submit($match, 'hero1', 'endTurn', []);
            $ready = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $caster = array_column($ready['units'], null, 'unitId')['caster'];
            expect_same(false, $caster['abilities'][0]['coolingDown'], 'the card recovers after exactly one skipped owning turn');
        },
    ];
}
