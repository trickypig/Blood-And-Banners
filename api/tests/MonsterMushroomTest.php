<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\UnitConditions;
use BloodAndBanners\Rules\Vanilla\Wounds;

function mushroom_battle(string $name = 'Shroom Kicker', string $seat = 'void-lord'): array
{
    return coop_battle([
        monster_unit('mushroom', $name, '0,0', ['defense' => 0]),
        monster_unit('ally', 'Spore Sprout', '7,0'),
        coop_hero('near', 'hero-a', '1,0', ['power' => 1, 'defense' => 0, 'hp' => 8]),
        coop_hero('edge', 'hero-b', '2,0', ['defense' => 0, 'hp' => 8]),
        coop_hero('far', 'hero-a', '4,0', ['defense' => 0, 'hp' => 8]),
    ], $seat);
}

function mushroom_network(array $state, string $to, array $rolls = []): array
{
    return coop_command($state, 'void-lord', 'moveAbility', ['unitId' => 'mushroom', 'ability' => 'Mycelium Network', 'toHex' => $to], $rolls);
}

function monster_mushroom_tests(): array
{
    return [
        'spore attacks poison every enemy in their area at the printed threshold' => static function (): void {
            foreach ([['Shroom Bomb', 'Spore Bomb', 17, 2], ['Myconid', 'Spore Cloud', 13, 3]] as [$name, $ability, $threshold, $radius]) {
                foreach ([$threshold - 1, $threshold] as $roll) {
                    $state = mushroom_battle($name);
                    $state['units']['ally']['hex'] = '0,1';
                    $state['board'] += vanilla_board(['0,1' => 0]);
                    $state['units']['edge']['hex'] = "{$radius},0";
                    $result = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'mushroom', 'ability' => $ability, 'targetUnitIds' => ['mushroom']], [$roll, $roll]);
                    foreach (['near', 'edge'] as $id) {
                        expect_same($roll < $threshold, UnitConditions::has($result['state'], $id, 'Poisoned'), 'each enemy saves independently at the threshold');
                        expect_same(0, $result['state']['units'][$id]['wounds'], 'spores cause no immediate poison damage');
                    }
                    foreach (['ally', 'far'] as $id) {
                        expect_true(!UnitConditions::has($result['state'], $id, 'Poisoned'), 'allies and out of range enemies are unaffected');
                    }
                    expect_same($name === 'Shroom Bomb' ? null : '0,0', $result['state']['units']['mushroom']['hex'], 'only the bomb sacrifices itself');
                    expect_same(2, count(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'saveRolled')), 'the sacrifice causes no extra ordinary release');
                }
            }
        },
        'bomb resolves all spores before its sacrifice ends the match' => static function (): void {
            $state = mushroom_battle('Shroom Bomb');
            unset($state['units']['ally']);
            $result = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'mushroom', 'ability' => 'Spore Bomb', 'targetUnitIds' => ['mushroom']], [1, 1]);
            expect_same('completed', $result['state']['phase'], 'the final monster is defeated');
            expect_true(UnitConditions::has($result['state'], 'edge', 'Poisoned'), 'even the second victim saves before victory');
            expect_same('matchCompleted', $result['events'][array_key_last($result['events'])]['kind'], 'victory is evaluated last');
        },
        'spore cloud consumes the normal attack and rejects enemy targeted payloads' => static function (): void {
            $state = mushroom_battle('Myconid');
            expect_game_error('invalidAbilityTarget', static fn() => coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'mushroom', 'ability' => 'Spore Cloud', 'targetUnitIds' => ['near']], []));
            $result = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'mushroom', 'ability' => 'Spore Cloud', 'targetUnitIds' => ['mushroom']], [20, 20]);
            expect_game_error('unitAlreadyActivated', static fn() => coop_command($result['state'], 'void-lord', 'attackUnit', ['unitId' => 'mushroom', 'targetUnitId' => 'near']));
        },
        'ordinary release saves the adjacent killer on ten even when the last monster dies' => static function (): void {
            foreach ([9, 10] as $roll) {
                $state = mushroom_battle('Spore Sprout', 'hero-a');
                unset($state['units']['ally']);
                $state['units']['mushroom']['wounds'] = 2;
                $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'near', 'targetUnitId' => 'mushroom'], [1, $roll]);
                expect_same('completed', $result['state']['phase'], 'normal victory still occurs');
                expect_same($roll < 10, UnitConditions::has($result['state'], 'near', 'Poisoned'), 'the killer saves before victory');
                expect_true(!UnitConditions::has($result['state'], 'edge', 'Poisoned'), 'other adjacent enemies are not targets');
                if ($roll < 10) {
                    expect_same('mushroom', UnitConditions::forUnit($result['state'], 'near')[0]['sourceUnitId'], 'the defeated source remains attributed');
                }
            }
        },
        'ordinary release does not trigger for a distant killer or environmental death' => static function (): void {
            $state = mushroom_battle('Spore Sprout', 'hero-a');
            $state['units']['mushroom']['wounds'] = 2;
            $state['units']['near']['hex'] = '3,0';
            $state['units']['edge']['hex'] = '5,0';
            $state['units']['near']['range'] = 4;
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'near', 'targetUnitId' => 'mushroom'], [1]);
            expect_true(!UnitConditions::has($result['state'], 'near', 'Poisoned'), 'the ranged killer is too far away');
            $events = [];
            $dead = Wounds::apply(mushroom_battle('Spore Sprout'), 'mushroom', 3, $events, new SequenceRandom([]));
            expect_true(!UnitConditions::has($dead, 'near', 'Poisoned'), 'an environmental death has no killer');
        },
        'myconid releases on any death to every enemy within two with ten plus saves' => static function (): void {
            $events = [];
            $dead = Wounds::apply(mushroom_battle('Myconid'), 'mushroom', 5, $events, new SequenceRandom([9, 10]));
            // Targets use stable unit id ordering: edge, near.
            expect_true(UnitConditions::has($dead, 'edge', 'Poisoned'), 'the enemy exactly two away fails');
            expect_true(!UnitConditions::has($dead, 'near', 'Poisoned'), 'ten succeeds');
            expect_true(!UnitConditions::has($dead, 'far', 'Poisoned'), 'outside two is safe');
        },
        'a lethal parting strike attributes ordinary spores to the adjacent killer' => static function (): void {
            $state = mushroom_battle('Spore Sprout');
            $state['board'] += vanilla_board(['-1,0' => 0]);
            $state['units']['mushroom']['wounds'] = 2;
            $result = coop_command($state, 'void-lord', 'moveUnit', ['unitId' => 'mushroom', 'to' => '-1,0'], [1, 9]);
            expect_same(null, $result['state']['units']['mushroom']['hex'], 'the parting strike defeats it before moving');
            expect_true(UnitConditions::has($result['state'], 'near', 'Poisoned'), 'the parting striker fails its spore save');
        },
        'myconid reacts to actual turn end poison and lava deaths before final victory' => static function (): void {
            foreach (['poison', 'lava'] as $cause) {
                $state = mushroom_battle('Myconid');
                unset($state['units']['ally']);
                $state['units']['mushroom']['wounds'] = 4;
                $events = [];
                if ($cause === 'poison') {
                    $state = UnitConditions::apply($state, 'Poisoned', 'mushroom', null, ['kind' => 'untilCleared'], $events);
                } else {
                    $state['board']['0,0']['terrain'] = 'lava';
                }
                $rolls = $cause === 'poison' ? [1, 9, 10] : [1, 6, 6, 6, 6, 9, 10];
                $result = coop_command($state, 'void-lord', 'endTurn', [], $rolls);
                expect_same('completed', $result['state']['phase'], 'the last monster dies');
                expect_true(UnitConditions::has($result['state'], 'edge', 'Poisoned'), 'death from either cause releases to the edge of the area');
                expect_true(!UnitConditions::has($result['state'], 'near', 'Poisoned'), 'the ten plus save succeeds');
            }
        },
        'one last horn survival is not a death and does not release spores' => static function (): void {
            $state = mushroom_battle('Myconid');
            $state['units']['mushroom']['abilities'][] = ['name' => 'One Last Horn'];
            $events = [];
            $state = Wounds::apply($state, 'mushroom', 5, $events, new SequenceRandom([]));
            expect_same('0,0', $state['units']['mushroom']['hex'], 'the mushroom survives');
            expect_true(!UnitConditions::has($state, 'near', 'Poisoned'), 'survival releases nothing');
        },
        'mycelium teleports across the board without disengagement and leaves its attack available' => static function (): void {
            $result = mushroom_network(mushroom_battle(), '6,0');
            expect_same('6,0', $result['state']['units']['mushroom']['hex'], 'the far allied mushroom enables the destination');
            expect_same(0, $result['state']['units']['mushroom']['wounds'], 'no parting strike is rolled');
            expect_true($result['state']['turn']['moved'], 'the whole movement is spent');
            expect_same(3, $result['state']['turn']['moveSpent'], 'all movement points are consumed');
            $saved = json_decode(json_encode($result['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_game_error('networkNeedsStillness', static fn() => mushroom_network($saved, '8,0'));
            expect_game_error('alreadyMoved', static fn() => coop_command($saved, 'void-lord', 'moveUnit', ['unitId' => 'mushroom', 'to' => '5,0']));
            $saved['units']['far']['hex'] = '5,0';
            $saved['units']['mushroom']['power'] = 0;
            $attack = coop_command($saved, 'void-lord', 'attackUnit', ['unitId' => 'mushroom', 'targetUnitId' => 'far'], [20]);
            expect_true(in_array('attackResolved', array_column($attack['events'], 'kind'), true), 'the ordinary attack remains available');
        },
        'network destinations require another living allied mushroom and an empty enterable hex' => static function (): void {
            $state = mushroom_battle();
            foreach (['0,0', '7,0', '5,0', '9,0'] as $to) {
                expect_game_error('invalidDestination', static fn() => mushroom_network($state, $to));
            }
            foreach ([['hex' => null], ['side' => 'heroes', 'seatId' => 'hero-a'], ['name' => 'Ice Wolf']] as $change) {
                $invalid = $state;
                $invalid['units']['ally'] = array_replace($invalid['units']['ally'], $change);
                expect_game_error('invalidDestination', static fn() => mushroom_network($invalid, '6,0'));
            }
            $state['board']['6,0']['sightBlocker'] = true;
            expect_game_error('invalidDestination', static fn() => mushroom_network($state, '6,0'));
        },
        'network enforces turn ownership and activation allowance' => static function (): void {
            $state = mushroom_battle();
            expect_game_error('notYourUnit', static fn() => coop_command($state, 'void-lord', 'moveAbility', ['unitId' => 'near', 'ability' => 'Mycelium Network', 'toHex' => '6,0']));
            expect_game_error('notYourTurn', static fn() => coop_command($state, 'hero-a', 'moveAbility', ['unitId' => 'mushroom', 'ability' => 'Mycelium Network', 'toHex' => '6,0']));
            // The rules derive the remaining allowance from activated units.
            $state['turn']['activated'] = ['spent-1', 'spent-2', 'spent-3', 'spent-4', 'spent-5', 'spent-6'];
            foreach ($state['turn']['activated'] as $id) {
                $state['units'][$id] = monster_unit($id, 'Ice Wolf', null);
            }
            expect_game_error('noActivationsRemaining', static fn() => mushroom_network($state, '6,0'));
        },
        'network cannot replace movement after moving attacking or being stunned' => static function (): void {
            $state = mushroom_battle();
            // Use a controlled active state to test spending either budget independently.
            foreach ([['moved' => true], ['moveSpent' => 1], ['attacks' => 1]] as $usage) {
                $used = $state;
                $used['turn']['activeUnitId'] = 'mushroom';
                $used['turn']['activated'] = ['mushroom'];
                $used['turn'] = array_replace($used['turn'], $usage);
                expect_game_error('networkNeedsStillness', static fn() => mushroom_network($used, '6,0'));
            }
            $events = [];
            $stunned = UnitConditions::apply($state, 'Stunned', 'mushroom', 'near', ['kind' => 'untilCleared'], $events);
            expect_game_error('unitStunned', static fn() => mushroom_network($stunned, '6,0'));
        },
        'network arrival applies poison lava water and stationary ice effects' => static function (): void {
            foreach (['poison-pits', 'lava', 'open-water', 'ice'] as $terrain) {
                $state = mushroom_battle();
                $state['board']['6,0']['terrain'] = $terrain;
                $result = mushroom_network($state, '6,0', $terrain === 'lava' ? [6, 6, 6, 6, 6] : []);
                expect_same('6,0', $result['state']['units']['mushroom']['hex'], 'teleport lands without sliding');
                expect_same($terrain === 'poison-pits', UnitConditions::has($result['state'], 'mushroom', 'Poisoned'), 'poison terrain applies');
                expect_same($terrain === 'ice', UnitConditions::has($result['state'], 'mushroom', 'Unsteady'), 'ice arrival is stationary');
                expect_true(!UnitConditions::has($result['state'], 'mushroom', 'Slipping'), 'teleport never starts a slide');
                if ($terrain === 'open-water') {
                    expect_same(2, \BloodAndBanners\Rules\Vanilla\Stats::effective($result['state'], 'mushroom', 'power'), 'water applies its standing power penalty');
                }
            }
        },
        'legal actions project network destinations hazards and self centered spore attacks' => static function (): void {
            $state = mushroom_battle();
            $state['board']['6,0']['terrain'] = 'ice';
            $actions = (new BattleRules(100))->legalActions($state, 'void-lord');
            $network = array_values(array_filter($actions, static fn(array $a): bool => ($a['ability'] ?? '') === 'Mycelium Network'));
            expect_same(1, count($network), 'the ability has one legal action');
            expect_same('moveAbility', $network[0]['kind'], 'it targets empty hexes');
            expect_same(['6,0', '8,0'], $network[0]['targetHexes'], 'only adjacent empty on board destinations are offered');
            expect_same(false, $network[0]['movementOptions'][0]['hazards'][0]['forced'], 'ice preview never claims a forced slide');
            $cloud = array_values(array_filter((new BattleRules(100))->legalActions(mushroom_battle('Myconid'), 'void-lord'), static fn(array $a): bool => ($a['ability'] ?? '') === 'Spore Cloud'));
            expect_same(['0,0'], $cloud[0]['targetHexes'], 'the area is centered on the caster');
        },
        'all five newly placed mushrooms have their complete implemented ability set' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            foreach (['Spore Sprout', 'Fungal Archer', 'Shroom Bomb', 'Shroom Kicker', 'Myconid'] as $name) {
                $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $placed = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => '7,0'], new SequenceRandom([]))['state']['units']['v-m1'];
                expect_same([], $placed['inactiveAbilities'], "{$name} is fully supported");
                $release = array_values(array_filter($placed['abilities'], static fn(array $a): bool => $a['name'] === 'Spore Release'));
                expect_same('Essence', $release[0]['type'], 'death reactions are passive, including Myconid');
            }
        },
        'old snapshots never gain inactive mushroom abilities on load' => static function (): void {
            $state = mushroom_battle();
            $state['units']['mushroom']['abilities'] = [];
            $state['units']['mushroom']['inactiveAbilities'] = [['name' => 'Mycelium Network', 'type' => 'Essence']];
            $saved = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_game_error('unknownAbility', static fn() => mushroom_network($saved, '6,0'));
        },
        'public game service projects and accepts the network movement command' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $host = $match['host']['seat'];
            $rival = $match['rival']['seat'];
            $state = vanilla_battle(vanilla_board(vanilla_line(9)), [
                monster_unit('kicker', 'Shroom Kicker', '0,0', ['seatId' => $host, 'side' => $host]),
                monster_unit('anchor', 'Spore Sprout', '7,0', ['seatId' => $host, 'side' => $host]),
                vanilla_unit('enemy', $rival, '3,0'),
            ], $host);
            $state['seats'] = [$host, $rival];
            $state['sides'] = \BloodAndBanners\Rules\Vanilla\Sides::perSeat([$host, $rival]);
            $state['board']['6,0']['terrain'] = 'poison-pits';
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')->execute([
                'state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId'],
            ]);
            $projection = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $network = array_values(array_filter($projection['legalActions'], static fn(array $a): bool => ($a['ability'] ?? '') === 'Mycelium Network'))[0];
            expect_same('moveAbility', $network['kind'], 'the public command kind is preserved');
            expect_same('condition', $network['movementOptions'][0]['hazards'][0]['kind'], 'arrival hazards reach the client');
            vanilla_submit($match, 'host', 'moveAbility', ['unitId' => 'kicker', 'ability' => 'Mycelium Network', 'toHex' => '6,0']);
            $loaded = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $kicker = array_column($loaded['units'], null, 'unitId')['kicker'];
            expect_same('6,0', $kicker['hex'], 'the submitted movement persists');
            expect_same('Poisoned', $kicker['conditions'][0]['name'], 'arrival poison persists through reconnect');
        },
    ];
}
