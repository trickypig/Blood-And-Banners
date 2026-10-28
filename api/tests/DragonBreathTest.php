<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;

function breath_battle(string $hex = '3,0', array $primary = []): array
{
    [$q, $r] = Hex::parse($hex);
    $state = coop_battle([
        monster_unit('dragon', 'Void Dragon', '0,0'),
        monster_unit('idle', 'Ice Wolf', '6,3'),
        coop_hero('primary', 'hero-a', $hex, $primary + ['defense' => 0, 'hp' => 100]),
        coop_hero('a-splash', 'hero-a', $q . ',' . ($r + 1), ['defense' => 0, 'hp' => 100]),
        monster_unit('b-ally', 'Ice Wolf', ($q + 1) . ',' . $r, ['defense' => 0, 'hp' => 100]),
    ], 'void-lord');
    $hexes = [];
    for ($q = -1; $q <= 6; $q++) {
        for ($r = -2; $r <= 4; $r++) {
            $hexes[$q . ',' . $r] = 0;
        }
    }
    $state['board'] = vanilla_board($hexes);
    return $state;
}

function breath_cast(array $state, array $rolls, array $targets = ['primary']): array
{
    return coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'dragon', 'ability' => 'Dragon Breath', 'targetUnitIds' => $targets], $rolls);
}

function breath_hits(array $result): array
{
    return array_values(array_map(static fn(array $e): array => $e['payload'], array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved')));
}

function dragon_breath_tests(): array
{
    return [
        'dragon breath shares six attack dice and subtracts one sword before each independent splash defense' => static function (): void {
            $state = breath_battle('3,0', ['defense' => 6]);
            $state['units']['a-splash']['defense'] = 2;
            $state['units']['b-ally']['defense'] = 1;
            $result = breath_cast($state, [1, 1, 1, 1, 1, 1, 4, 4, 4, 4, 4, 4, 4, 6, 4]);
            $hits = breath_hits($result);
            expect_same(['primary', 'a-splash', 'b-ally'], array_column($hits, 'targetUnitId'), 'primary first, then every adjacent unit including allies');
            expect_same([0, 4, 4], array_column($hits, 'wounds'), 'splash uses six swords minus one, not the primary zero wounds minus one');
            expect_same([6, 2, 1], array_map(static fn(array $hit): int => count($hit['defenseFaces']), $hits), 'every defender rolls its own pool');
            expect_same([0, 1, 1], array_column($hits, 'attackPenalty'), 'the splash penalty is explicit in the log');
            foreach ($hits as $hit) {
                expect_same(array_fill(0, 6, 'sword'), $hit['attackFaces'], 'every hit reuses the same six dice');
                expect_same(true, $hit['sharedAttackRoll'], 'the log identifies the shared roll');
                expect_same('fire', $hit['damageType'], 'all breath damage is fire');
            }
        },
        'dragon breath clamps a zero or one sword splash result to zero' => static function (): void {
            foreach ([[6, 6, 6, 6, 6, 6], [1, 6, 6, 6, 6, 6]] as $rolls) {
                $result = breath_cast(breath_battle(), $rolls);
                expect_same([0, 0], [$result['state']['units']['a-splash']['wounds'], $result['state']['units']['b-ally']['wounds']], 'splash never deals negative wounds or manufactures a sword');
            }
        },
        'dragon breath reaches range four and splashes beyond its targeting range' => static function (): void {
            $result = breath_cast(breath_battle('4,0'), [1, 1, 1, 1, 1, 1]);
            expect_same([6, 5, 5], array_column(breath_hits($result), 'wounds'), 'the adjacent ally at range five is still hit');
            expect_same('5,0', $result['state']['units']['b-ally']['hex'], 'splash does not push units');
            $near = breath_cast(breath_battle('1,0'), array_fill(0, 10, 4));
            expect_same(4, count(breath_hits($near)), 'an adjacent primary is legal and also catches the dragon in the splash');
        },
        'dragon breath validates a single living visible enemy before rolling' => static function (): void {
            expect_game_error('invalidAbilityTarget', static fn() => breath_cast(breath_battle('5,0'), []));
            $state = breath_battle();
            $state['board']['1,0']['sightBlocker'] = true;
            expect_game_error('invalidAbilityTarget', static fn() => breath_cast($state, []));
            $state['board']['1,0']['sightBlocker'] = false;
            foreach ([[], ['b-ally'], ['missing'], ['primary', 'a-splash']] as $targets) {
                expect_game_error('invalidAbilityTarget', static fn() => breath_cast($state, [], $targets));
            }
            $state['units']['primary']['hex'] = null;
            expect_game_error('invalidAbilityTarget', static fn() => breath_cast($state, []));
        },
        'dragon breath checks evasion separately and primary evasion does not cancel the blast' => static function (): void {
            $state = breath_battle();
            foreach (['primary', 'a-splash', 'b-ally'] as $id) {
                $state['units'][$id]['abilities'] = [['name' => 'Evasion']];
            }
            $result = breath_cast($state, [1, 1, 1, 1, 1, 1, 11, 10, 11]);
            expect_same([0, 5, 0], array_map(static fn(string $id): int => $result['state']['units'][$id]['wounds'], ['primary', 'a-splash', 'b-ally']), 'one evade cannot cancel another defender hit');
            expect_same(['a-splash'], array_column(breath_hits($result), 'targetUnitId'), 'only the non-evading unit takes the strike');
            expect_same(3, count(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'evaded')), 'each unit gets its own evasion roll');
        },
        'dragon breath uses fire defense on primary and splash targets' => static function (): void {
            $state = breath_battle();
            foreach (['primary', 'a-splash'] as $id) {
                $state['units'][$id]['abilities'] = [['name' => 'Lava Walker']];
            }
            $result = breath_cast($state, [1, 1, 1, 1, 1, 1, 4, 4, 4, 4]);
            expect_same([4, 3, 5], array_column(breath_hits($result), 'wounds'), 'Lava Walker rolls two extra fire defense dice on both parts');
        },
        'dragon breath uses one special attack slot and is available again on a later turn' => static function (): void {
            $state = breath_cast(breath_battle(), array_fill(0, 6, 6))['state'];
            expect_same(null, $state['turn']['activeUnitId'], 'one breath completes the dragon activation');
            expect_game_error('unitAlreadyActivated', static fn() => breath_cast($state, []));
            expect_game_error('unitAlreadyActivated', static fn() => coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'dragon', 'targetUnitId' => 'primary']));
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_same(3, count(breath_hits(breath_cast($state, [1, 1, 1, 1, 1, 1]))), 'breath has no unprinted cooldown or once-per-match limit');
            $state = breath_battle('2,0');
            $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'dragon', 'targetUnitId' => 'primary'], [6, 6, 6, 6])['state'];
            expect_game_error('unitAlreadyActivated', static fn() => breath_cast($state, []));
        },
        'dragon breath applies power modifiers once to the shared roll' => static function (): void {
            $state = stun(breath_battle(), 'dragon', 'primary');
            $result = breath_cast($state, [1, 1, 1, 1]);
            expect_same([4, 3, 3], array_column(breath_hits($result), 'wounds'), 'stun reduces the shared six-die power to four');
        },
        'dragon breath continues around a defeated primary while enemies remain' => static function (): void {
            $result = breath_cast(breath_battle('3,0', ['hp' => 1]), [1, 1, 1, 1, 1, 1]);
            expect_same(null, $result['state']['units']['primary']['hex'], 'the primary is defeated');
            expect_same([5, 5], [$result['state']['units']['a-splash']['wounds'], $result['state']['units']['b-ally']['wounds']], 'the saved blast center remains after the primary falls');
        },
        'dragon breath still reaches later defenders if its own splash defeats the dragon' => static function (): void {
            $state = breath_battle('1,0');
            $state['units']['dragon']['hp'] = 1;
            $state['units']['dragon']['defense'] = 0;
            foreach (['a-splash' => 'z-splash', 'b-ally' => 'zz-ally'] as $old => $new) {
                $state['units'][$new] = $state['units'][$old];
                $state['units'][$new]['unitId'] = $new;
                unset($state['units'][$old]);
            }
            $state['units']['z-splash']['abilities'] = [['name' => 'Stoneskin']];
            $result = breath_cast($state, [1, 1, 1, 1, 1, 1, 4, 4]);
            expect_same(null, $result['state']['units']['dragon']['hex'], 'friendly fire defeats the dragon');
            expect_same([3, 5], [$result['state']['units']['z-splash']['wounds'], $result['state']['units']['zz-ally']['wounds']], 'the fired breath continues and retains its original ranged origin');
            expect_same('active', $result['state']['phase'], 'the idle monster and surviving heroes keep the battle active');
        },
        'dragon breath preserves the shared roll through primary and secondary defense glare choices' => static function (): void {
            foreach (['primary', 'a-splash'] as $defender) {
                $state = breath_battle();
                $state['units'][$defender]['defense'] = 1;
                $state['units'][$defender]['abilities'] = [['name' => 'Moonlight Glare']];
                $opened = breath_cast($state, [1, 1, 1, 1, 1, 1, 6]);
                expect_same($defender, $opened['state']['pending']['choice']['unitId'] ?? null, 'the correct defender can reroll');
                $saved = json_decode(json_encode($opened['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $result = coop_command($saved, 'hero-a', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'reroll:0'], [4]);
                expect_same($defender === 'primary' ? [5, 5, 5] : [6, 4, 5], array_map(static fn(string $id): int => $result['state']['units'][$id]['wounds'], ['primary', 'a-splash', 'b-ally']), 'only the chosen defense changes after reconnect');
                foreach (breath_hits($result) as $hit) {
                    expect_same(array_fill(0, 6, 'sword'), $hit['attackFaces'], 'resuming never rolls another attack pool');
                }
            }
        },
        'dragon breath finalizes an attacker glare reroll before any defender resolves' => static function (): void {
            $state = breath_battle();
            $state['units']['dragon']['abilities'][] = ['name' => 'Moonlight Glare'];
            $opened = breath_cast($state, [1, 1, 1, 1, 1, 6])['state'];
            expect_same('attack', $opened['pending']['choice']['side'] ?? null, 'the shared attack pool offers one attacker reroll');
            expect_same(0, $opened['units']['primary']['wounds'], 'no defender resolves before the shared roll is final');
            $saved = json_decode(json_encode($opened, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $result = coop_command($saved, 'void-lord', 'resolveChoice', ['choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'reroll:5'], [1]);
            expect_same([6, 5, 5], array_column(breath_hits($result), 'wounds'), 'the rerolled sword benefits every defender calculation');
            expect_same(null, $result['state']['pending'], 'the shared attack offers no repeated attacker rerolls');
        },
        'new void dragons enable breath while older snapshots keep their saved abilities' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            $state = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => 'Void Dragon', 'hex' => '7,0'], new SequenceRandom([]))['state'];
            expect_same(['Dragon Breath', 'Sky Toss', 'Flying', 'Dragon Slayer'], array_column($state['units']['v-m1']['abilities'], 'name'), 'only implemented dragon abilities are enabled');
            expect_same([], array_column($state['units']['v-m1']['inactiveAbilities'], 'name'), 'all Void Dragon abilities are implemented');
            $state['units']['v-m1']['abilities'] = [];
            expect_same([], MatchEngine::normalize($state)['units']['v-m1']['abilities'], 'loading never upgrades existing saved dragons');
        },
        'public dragon breath retries and saved defense choices never duplicate or reroll the shared attack' => static function (): void {
            $match = coop_service_match();
            $match['games'] = new GameService($match['db'], null, new ScriptedRandom(), new MatchEngine(new Catalog()));
            coop_ready_all($match);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $void = $match['host']['seat'];
            $hero = $match['hero1']['seat'];
            $state = breath_battle('3,0', ['defense' => 1, 'abilities' => [['name' => 'Moonlight Glare', 'type' => 'Essence', 'description' => 'Once per turn, reroll one die.']]]);
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
            $match['games'] = new GameService($match['db'], null, new SequenceRandom([1, 1, 1, 1, 1, 1, 6]), new MatchEngine(new Catalog()));
            $payload = ['unitId' => 'dragon', 'ability' => 'Dragon Breath', 'targetUnitIds' => ['primary']];
            $cast = vanilla_submit($match, 'host', 'specialAttack', $payload, 'breath-retry');
            $retry = vanilla_submit($match, 'host', 'specialAttack', $payload, 'breath-retry');
            expect_same($cast['version'], $retry['version'], 'retrying a paused breath returns the original version');
            $match['games'] = new GameService($match['db'], null, new SequenceRandom([4]), new MatchEngine(new Catalog()));
            $loaded = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same('primary', $loaded['pendingChoice']['unitId'], 'reconnecting retains the defender decision');
            $choice = ['choiceId' => $loaded['pendingChoice']['choiceId'], 'option' => 'reroll:0'];
            $resolved = vanilla_submit($match, 'hero1', 'resolveChoice', $choice, 'breath-choice');
            $retriedChoice = vanilla_submit($match, 'hero1', 'resolveChoice', $choice, 'breath-choice');
            expect_same($resolved['version'], $retriedChoice['version'], 'retrying the decision does not repeat splash damage');
            $loaded = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $units = array_column($loaded['units'], null, 'unitId');
            expect_same([5, 5, 5], [$units['primary']['wounds'], $units['a-splash']['wounds'], $units['b-ally']['wounds']], 'one saved attack pool resolves once for every defender');
            expect_same(null, $loaded['pendingChoice'], 'the saved queue finishes after the decision');
        },
    ];
}
