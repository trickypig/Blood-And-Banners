<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\UnitConditions;
use BloodAndBanners\Rules\Vanilla\Wounds;

function monster_control_battle(string $name, array $targetStats = []): array
{
    return coop_battle([
        monster_unit('monster', $name, '0,0', ['power' => 1]),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('target', 'hero-a', '1,0', $targetStats + ['defense' => 0, 'hp' => 10, 'move' => 3, 'power' => 4]),
    ], 'void-lord');
}

function monster_control_attack(array $state, array $rolls): array
{
    return coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target'], $rolls);
}

function monster_control_tests(): array
{
    return [
        'stunning kick saves on ten and applies its own one power stun penalty' => static function (): void {
            foreach ([[9, true], [10, false], [20, false]] as [$save, $stunned]) {
                $result = monster_control_attack(monster_control_battle('Shroom Kicker'), [1, $save]);
                expect_same($stunned, UnitConditions::has($result['state'], 'target', 'Stunned'), "Stunning Kick save {$save}");
                expect_same($stunned ? 3 : 4, Stats::effective($result['state'], 'target', 'power'), 'kick costs exactly one power');
                if ($stunned) {
                    expect_same([], Movement::disengagedFrom($result['state'], 'monster', '3,0'), 'the stunned unit cannot make a parting strike');
                }
            }
        },
        'stunning kick and slowness need damage rather than an attack roll alone' => static function (): void {
            foreach (['Shroom Kicker', 'Stray'] as $name) {
                foreach ([[1, 4], [6, 6]] as $dice) {
                    $result = monster_control_attack(monster_control_battle($name, ['defense' => 1]), $dice);
                    expect_same([], UnitConditions::forUnit($result['state'], 'target'), 'blocked and missed strikes apply no condition');
                    expect_true(!in_array('saveRolled', array_column($result['events'], 'kind'), true), 'a nondamaging kick does not roll a save');
                }
            }
        },
        'arrows of slowness reduce movement without a save and never change power' => static function (): void {
            $result = monster_control_attack(monster_control_battle('Stray'), [1]);
            expect_true(UnitConditions::has($result['state'], 'target', 'Slowed'), 'a damaging arrow slows its target');
            expect_same(2, Stats::effective($result['state'], 'target', 'move'), 'slow costs one movement point');
            expect_same(4, Stats::effective($result['state'], 'target', 'power'), 'slow does not weaken attacks');
            expect_true(!in_array('saveRolled', array_column($result['events'], 'kind'), true), 'slowness grants no save');
        },
        'control effects survive intervening co-op seats and expire at the victim next turn end' => static function (): void {
            foreach ([['Shroom Kicker', 'Stunned', [1, 9]], ['Stray', 'Slowed', [1]]] as [$name, $conditionName, $dice]) {
                $state = monster_control_battle($name);
                $state['units']['other-hero'] = coop_hero('other-hero', 'hero-b', '7,0');
                $attacked = monster_control_attack($state, $dice)['state'];
                $condition = UnitConditions::named($attacked, 'target', $conditionName);
                expect_true($condition !== null, 'the victim carries its control condition');
                $state = json_decode(json_encode($attacked, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                foreach (['void-lord', 'hero-b', 'void-lord'] as $seat) {
                    $state = coop_command($state, $seat, 'endTurn')['state'];
                    expect_true(UnitConditions::has($state, 'target', $conditionName), 'other seats ending turns do not clear the condition');
                }
                expect_same('hero-a', $state['turn']['activeSeatId'], 'the victim is now acting');
                $actions = (new MatchEngine(test_catalog()))->legalActions($state, 'hero-a');
                $moves = array_values(array_filter($actions, static fn(array $action): bool => $action['kind'] === 'moveUnit' && $action['unitId'] === 'target'));
                if ($conditionName === 'Stunned') {
                    expect_same([], $moves, 'the kick prevents movement during the victim turn');
                    expect_same(3, Stats::effective($state, 'target', 'power'), 'the one power penalty survives loading and intervening seats');
                    expect_game_error('unitStunned', static fn() => coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'target', 'to' => '2,0']));
                } else {
                    expect_true(in_array('3,0', $moves[0]['targetHexes'] ?? [], true), 'a slowed unit can still move two hexes');
                    expect_true(!in_array('4,0', $moves[0]['targetHexes'] ?? [], true), 'its previous three hex move is no longer legal');
                    expect_game_error('hexUnreachable', static fn() => coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'target', 'to' => '4,0']));
                }
                $ended = coop_command($state, 'hero-a', 'endTurn');
                expect_true(!UnitConditions::has($ended['state'], 'target', $conditionName), 'the condition ends with the victim turn');
                expect_same([3, 4], [Stats::effective($ended['state'], 'target', 'move'), Stats::effective($ended['state'], 'target', 'power')], 'normal stats return');
                expect_true(in_array('conditionExpired', array_column($ended['events'], 'kind'), true), 'expiry is logged');
            }
        },
        'repeated arrows refresh slowness without stacking and survive the archer defeat' => static function (): void {
            $state = monster_control_battle('Stray');
            $state['units']['second'] = monster_unit('second', 'Stray', '2,0', ['power' => 1]);
            $state = monster_control_attack($state, [1])['state'];
            $state = coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'second', 'targetUnitId' => 'target'], [1])['state'];
            expect_same(2, Stats::effective($state, 'target', 'move'), 'two arrows still cost only one move');
            expect_same(1, count(UnitConditions::forUnit($state, 'target')), 'there is only one slow condition');
            $events = [];
            $state = Wounds::apply($state, 'second', 100, $events);
            expect_same(2, Stats::effective($state, 'target', 'move'), 'defeating the archer does not clear slowness early');
        },
        'control expires at the victim turn end even when an eliminated seat shortens the schedule' => static function (): void {
            foreach ([['Shroom Kicker', 'Stunned', [1, 9]], ['Stray', 'Slowed', [1]]] as [$name, $conditionName, $dice]) {
                $state = monster_control_battle($name);
                $state['units']['other-hero'] = coop_hero('other-hero', 'hero-b', '7,0');
                $state = monster_control_attack($state, $dice)['state'];
                $events = [];
                $state = Wounds::apply($state, 'other-hero', 100, $events);
                $state = coop_command($state, 'void-lord', 'endTurn')['state'];
                expect_same(['hero-a', 2], [$state['turn']['activeSeatId'], $state['turn']['number']], 'the eliminated seat and its void response are skipped');
                expect_true(UnitConditions::has($state, 'target', $conditionName), 'the condition lasts through the victim turn');
                $ended = coop_command($state, 'hero-a', 'endTurn');
                expect_true(!UnitConditions::has($ended['state'], 'target', $conditionName), 'the shorter schedule never extends the condition by another turn');
                expect_same([3, 4], [Stats::effective($ended['state'], 'target', 'move'), Stats::effective($ended['state'], 'target', 'power')], 'the penalty expires at the actual victim turn end');
            }
        },
        'a kick never weakens an existing standard stun and a standard stun upgrades a kick' => static function (): void {
            $events = [];
            $state = monster_control_battle('Shroom Kicker');
            $state = AbilityRegistry::get('Stunning Blow')->onFailedSave($state, 'monster', 'target', 1, new SequenceRandom([]), $events);
            $state = monster_control_attack($state, [1, 9])['state'];
            expect_same(2, Stats::effective($state, 'target', 'power'), 'the stronger existing two power penalty remains');
            expect_same(1, count(UnitConditions::forUnit($state, 'target')), 'stun does not stack');
            $state = monster_control_attack(monster_control_battle('Shroom Kicker'), [1, 9])['state'];
            $state = AbilityRegistry::get('Stunning Blow')->onFailedSave($state, 'idle', 'target', 1, new SequenceRandom([]), $events);
            expect_same(2, Stats::effective($state, 'target', 'power'), 'standard stun upgrades the kick penalty to two');
        },
        'control effects wait for glare and use the final rerolled wound count' => static function (): void {
            foreach (['Shroom Kicker', 'Stray'] as $name) {
                $state = monster_control_battle($name, ['defense' => 1, 'abilities' => [['name' => 'Moonlight Glare', 'type' => 'Essence', 'description' => '']]]);
                $attacked = monster_control_attack($state, [1, 6]);
                expect_true($attacked['state']['pending'] !== null, 'the defender has a reroll before control resolves');
                expect_same([], UnitConditions::forUnit($attacked['state'], 'target'), 'control waits for final damage');
                $result = coop_command($attacked['state'], 'hero-a', 'resolveChoice', ['choiceId' => $attacked['state']['pending']['choice']['choiceId'], 'option' => 'reroll:0'], [4]);
                expect_same([], UnitConditions::forUnit($result['state'], 'target'), 'a rerolled block prevents both control abilities');
                expect_true(!in_array('saveRolled', array_column($result['events'], 'kind'), true), 'the blocked kick rolls no save');
            }
        },
        'lethal and evaded control attacks never apply a condition or roll a save' => static function (): void {
            foreach (['Shroom Kicker', 'Stray'] as $name) {
                $lethal = monster_control_attack(monster_control_battle($name, ['hp' => 1]), [1]);
                expect_same('completed', $lethal['state']['phase'], 'the primary strike ends the battle');
                expect_same([], UnitConditions::forUnit($lethal['state'], 'target'), 'defeated units receive no control condition');
                $evaded = monster_control_attack(monster_control_battle($name, ['abilities' => [['name' => 'Evasion', 'type' => 'Essence', 'description' => '']]]), [11]);
                expect_same([], UnitConditions::forUnit($evaded['state'], 'target'), 'evasion avoids control');
                expect_true(!in_array('saveRolled', array_column($evaded['events'], 'kind'), true), 'no control save follows evasion');
            }
        },
        'new control monsters enable their supported abilities and keep their other rules deferred' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            foreach ([['Shroom Kicker', ['Stunning Kick'], ['Mycelium Network', 'Spore Release']], ['Stray', ['Arrows of Slowness', 'Hollow Bones'], []]] as [$name, $active, $inactive]) {
                $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $unit = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => '7,0'], new SequenceRandom([]))['state']['units']['v-m1'];
                expect_same($active, array_column($unit['abilities'], 'name'), 'the verified control abilities are active');
                expect_same($inactive, array_column($unit['inactiveAbilities'], 'name'), 'other abilities remain deferred');
            }
        },
        'seat-bound control projections describe the victim turn and carry the kick penalty' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $statement = $match['db']->prepare('SELECT state_json FROM game_states WHERE game_id = :game');
            $statement->execute(['game' => $match['gameId']]);
            $state = json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            $hero = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['seatId'] === $match['host']['seat'] && $unit['isHero']))[0];
            $events = [];
            $expires = ['kind' => 'turnEnd', 'seatId' => $match['host']['seat']];
            $state = UnitConditions::apply($state, 'Stunned', $hero['unitId'], 'source', $expires, $events, ['powerPenalty' => 1]);
            $state = UnitConditions::apply($state, 'Slowed', $hero['unitId'], 'source', $expires, $events);
            foreach ([[$match['rival']['seat'], 'endOfNextTurn'], [$match['host']['seat'], 'endOfThisTurn']] as [$activeSeat, $label]) {
                $state['turn']['activeSeatId'] = $activeSeat;
                $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')->execute([
                    'state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId'],
                ]);
                $projection = $match['games']->getGame($match['gameId'], $match['host']['identity']);
                $unit = array_column($projection['units'], null, 'unitId')[$hero['unitId']];
                expect_same([$label, $label], array_column($unit['conditions'], 'expires'), 'both conditions describe the affected commander turn');
                expect_same(1, $unit['conditions'][0]['data']->powerPenalty, 'the one power penalty reaches the client');
                expect_same([3, 2], [$unit['effectivePower'], $unit['effectiveMove']], 'the projected captain stats include both penalties');
            }
        },
    ];
}
