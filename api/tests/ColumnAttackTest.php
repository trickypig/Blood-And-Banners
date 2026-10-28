<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;

function column_battle(string $targetHex = '3,0', array $target = []): array
{
    return coop_battle([
        monster_unit('treant', 'Treant', '1,0'),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('target', 'hero-a', $targetHex, $target + ['defense' => 0, 'hp' => 100]),
    ], 'void-lord');
}

function column_attack(array $state, string $ability, array $rolls = [], array $targets = ['target'], string $unitId = 'treant'): array
{
    return coop_command($state, 'void-lord', 'specialAttack', ['unitId' => $unitId, 'ability' => $ability, 'targetUnitIds' => $targets], $rolls);
}

function column_actions(array $state, string $unitId = 'treant'): array
{
    return array_values(array_filter((new BattleRules(100))->legalActions($state, 'void-lord'), static fn(array $a): bool => ($a['unitId'] ?? '') === $unitId && $a['kind'] === 'specialAttack'));
}

function column_next_turn(array $state): array
{
    $state = coop_command($state, 'void-lord', 'endTurn')['state'];
    return coop_command($state, 'hero-a', 'endTurn')['state'];
}

function column_attack_tests(): array
{
    return [
        'column attacks use their printed power and distinct range bands' => static function (): void {
            foreach ([['Column Swing', '2,0', 5], ['Column Swing', '3,0', 5], ['Column Toss', '4,0', 7], ['Column Toss', '5,0', 7]] as [$ability, $hex, $power]) {
                $result = column_attack(column_battle($hex), $ability, array_fill(0, $power, 1));
                expect_same($power, $result['state']['units']['target']['wounds'], 'the special uses its printed power rather than normal power three');
                expect_same($hex, $result['state']['units']['target']['hex'], 'column attacks do not add knockback');
                $hits = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'));
                expect_same($ability, $hits[0]['payload']['ability'], 'the battle log names the chosen attack');
            }
        },
        'column attacks reject the wrong range band and invalid targets before rolling' => static function (): void {
            foreach ([['Column Swing', '4,0'], ['Column Toss', '2,0'], ['Column Toss', '3,0'], ['Column Toss', '6,0']] as [$ability, $hex]) {
                expect_game_error('invalidAbilityTarget', static fn() => column_attack(column_battle($hex), $ability));
            }
            foreach ([['Column Swing', '3,0', '2,0'], ['Column Toss', '5,0', '3,0']] as [$ability, $targetHex, $blocker]) {
                $state = column_battle($targetHex);
                $state['board'][$blocker]['sightBlocker'] = true;
                expect_game_error('invalidAbilityTarget', static fn() => column_attack($state, $ability));
                $state['board'][$blocker]['sightBlocker'] = false;
                $state['units']['idle']['hex'] = $targetHex;
                $state['units']['target']['hex'] = null;
                foreach ([['idle'], ['target'], ['missing'], [], ['idle', 'target']] as $targets) {
                    expect_game_error('invalidAbilityTarget', static fn() => column_attack($state, $ability, [], $targets));
                }
            }
        },
        'each column attack replaces both normal branch attacks and cannot follow a normal attack' => static function (): void {
            foreach ([['Column Swing', '3,0', 5], ['Column Toss', '4,0', 7]] as [$ability, $hex, $power]) {
                $result = column_attack(column_battle($hex), $ability, array_fill(0, $power, 6));
                expect_same(null, $result['state']['turn']['activeUnitId'], 'one special completes the activation');
                expect_game_error('unitAlreadyActivated', static fn() => coop_command($result['state'], 'void-lord', 'attackUnit', ['unitId' => 'treant', 'targetUnitId' => 'target']));
                expect_game_error('unitAlreadyActivated', static fn() => column_attack($result['state'], $ability));
                $state = column_battle($hex);
                $state['units']['near'] = coop_hero('near', 'hero-a', '0,0', ['side' => 'heroes', 'defense' => 0, 'hp' => 100]);
                $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'treant', 'targetUnitId' => 'near'], [1, 1, 1])['state'];
                expect_same('treant', $state['turn']['activeUnitId'], 'one normal strike leaves a second normal strike');
                expect_same([], column_actions($state), 'neither special is offered after a normal strike');
                expect_game_error('noAttacksLeft', static fn() => column_attack($state, $ability));
                $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'treant', 'targetUnitId' => 'near'], [1, 1, 1])['state'];
                expect_same(6, $state['units']['near']['wounds'], 'two normal attacks retain power three each');
            }
        },
        'column swing remains reusable on later turns until the column is tossed' => static function (): void {
            $state = column_battle();
            for ($turn = 0; $turn < 3; $turn++) {
                expect_same(['Column Swing'], array_column(column_actions($state), 'ability'), 'the nearby target is offered to Swing only');
                $state = column_attack($state, 'Column Swing', [6, 6, 6, 6, 6])['state'];
                $state = column_next_turn($state);
            }
            $state['units']['target']['hex'] = '4,0';
            expect_same(['Column Toss'], array_column(column_actions($state), 'ability'), 'repeated swings never consume Toss');
            $result = column_attack($state, 'Column Toss', array_fill(0, 7, 1));
            expect_same(7, $result['state']['units']['target']['wounds'], 'Toss still works after multiple swings');
        },
        'column toss permanently disables both specials across turns and saved states but preserves branch attacks' => static function (): void {
            $state = column_attack(column_battle('4,0'), 'Column Toss', array_fill(0, 7, 6))['state'];
            for ($turn = 0; $turn < 3; $turn++) {
                $state = MatchEngine::normalize(json_decode(json_encode(column_next_turn($state), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
                foreach ([['Column Swing', '3,0'], ['Column Toss', '4,0']] as [$ability, $hex]) {
                    $state['units']['target']['hex'] = $hex;
                    expect_same([], column_actions($state), 'no column action returns on later turns');
                    expect_game_error('columnAlreadyTossed', static fn() => column_attack($state, $ability));
                }
                $state['units']['target']['hex'] = '3,0';
                for ($hit = 0; $hit < 2; $hit++) {
                    $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'treant', 'targetUnitId' => 'target'], [1, 1, 1])['state'];
                }
                expect_same(null, $state['turn']['activeUnitId'], 'both normal branch attacks remain available without the column');
            }
            expect_same(18, $state['units']['target']['wounds'], 'three later activations each retain two normal attacks');
        },
        'a tossed column belongs to only the treant that used it' => static function (): void {
            $state = column_battle('4,0');
            $state['units']['second'] = monster_unit('second', 'Treant', '4,-3');
            $state['board'] += vanilla_board(['4,-3' => 0, '4,-2' => 0, '4,-1' => 0]);
            $state = column_attack($state, 'Column Toss', array_fill(0, 7, 6))['state'];
            expect_same(['Column Toss'], array_column(column_actions($state, 'second'), 'ability'), 'the other treant still has its own column');
            $result = column_attack($state, 'Column Toss', array_fill(0, 7, 1), ['target'], 'second');
            expect_same(7, $result['state']['units']['target']['wounds'], 'another treant may toss its column during the same turn');
        },
        'column toss consumes the column even when evaded or fully blocked' => static function (): void {
            foreach ([[['abilities' => [['name' => 'Evasion']]], [11]], [['defense' => 7], array_merge(array_fill(0, 7, 1), array_fill(0, 7, 4))]] as [$target, $rolls]) {
                $state = column_attack(column_battle('4,0', $target), 'Column Toss', $rolls)['state'];
                expect_same(0, $state['units']['target']['wounds'], 'the target takes no wounds');
                $state = column_next_turn($state);
                expect_game_error('columnAlreadyTossed', static fn() => column_attack($state, 'Column Toss'));
                $state['units']['target']['hex'] = '3,0';
                expect_game_error('columnAlreadyTossed', static fn() => column_attack($state, 'Column Swing'));
            }
        },
        'column toss consumption is saved before a pending glare choice and survives resolution' => static function (): void {
            $state = column_battle('4,0', ['defense' => 1, 'abilities' => [['name' => 'Moonlight Glare']]]);
            $opened = column_attack($state, 'Column Toss', [1, 1, 1, 1, 1, 1, 1, 6])['state'];
            expect_true($opened['pending'] !== null, 'the defense reroll pauses the attack');
            $saved = json_decode(json_encode($opened, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_same('columnAlreadyTossed', \BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry::get('Column Toss')->unavailable($saved, 'treant'), 'the column is spent before the pending decision is resolved');
            $state = coop_command($saved, 'hero-a', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'reroll:0'], [4])['state'];
            expect_same(6, $state['units']['target']['wounds'], 'the final defense result completes the saved strike');
            $state = column_next_turn($state);
            expect_game_error('columnAlreadyTossed', static fn() => column_attack($state, 'Column Toss'));
        },
        'public column toss retries and reconnects preserve permanent unavailability on the card' => static function (): void {
            $match = coop_service_match();
            $match['games'] = new GameService($match['db'], null, new ScriptedRandom(), new MatchEngine(new Catalog()));
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $void = $match['host']['seat'];
            $hero = $match['hero1']['seat'];
            $state = column_battle('4,0');
            $state['seats'] = [$hero, $void];
            $state['voidLordSeatId'] = $void;
            $state['initiative'] = ['rolls' => [$hero => [20]], 'order' => [$hero]];
            $state['sides'] = ['heroes' => ['kind' => 'heroes', 'seatIds' => [$hero]], 'void' => ['kind' => 'void', 'seatIds' => [$void]]];
            foreach ($state['units'] as &$unit) {
                $unit['seatId'] = $unit['side'] === 'void' ? $void : $hero;
            }
            unset($unit);
            $state['turn'] = Turn::fresh($state, $void, 1, 1);
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);
            $match['db']->prepare("UPDATE games SET lifecycle = 'active' WHERE id = :game")->execute(['game' => $match['gameId']]);
            $match['games'] = new GameService($match['db'], null, new SequenceRandom(array_fill(0, 7, 6)), new MatchEngine(new Catalog()));
            $payload = ['unitId' => 'treant', 'ability' => 'Column Toss', 'targetUnitIds' => ['target']];
            $cast = vanilla_submit($match, 'host', 'specialAttack', $payload, 'column-toss');
            $retry = vanilla_submit($match, 'host', 'specialAttack', $payload, 'column-toss');
            expect_same($cast['version'], $retry['version'], 'a retry returns the same result without another throw');
            vanilla_submit($match, 'host', 'endTurn', []);
            vanilla_submit($match, 'hero1', 'endTurn', []);
            $loaded = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $treant = array_column($loaded['units'], null, 'unitId')['treant'];
            $cards = array_column($treant['abilities'], null, 'name');
            foreach (['Column Swing', 'Column Toss'] as $ability) {
                expect_true(str_contains($cards[$ability]['description'], 'column has been tossed'), 'both cards explain the permanent loss after reconnect');
            }
            expect_same([], array_values(array_filter($loaded['legalActions'], static fn(array $a): bool => ($a['unitId'] ?? '') === 'treant' && $a['kind'] === 'specialAttack')), 'the public client offers neither column attack');
            expect_game_error('columnAlreadyTossed', static fn() => vanilla_submit($match, 'host', 'specialAttack', $payload));
        },
    ];
}
