<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Combat;
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Turn;

/** A battle unit carrying the named abilities (types are irrelevant to the rules, which key on names). */
function ability_unit(string $unitId, string $seatId, ?string $hex, array $abilityNames, array $stats = []): array
{
    $abilities = array_map(
        static fn(string $name): array => ['name' => $name, 'type' => 'Essence', 'description' => ''],
        $abilityNames
    );
    return vanilla_unit($unitId, $seatId, $hex, $stats + ['abilities' => $abilities, 'flags' => []]);
}

function vanilla_ability_tests(): array
{
    return [
        'catalog cards carry their abilities and deployment copies them' => static function (): void {
            $captain = test_catalog()->hero('Test Captain');
            expect_same(
                [['name' => 'Shield Wall', 'type' => 'Ability', 'description' => 'Grant one adjacent unit +1 Defense until the start of your next turn']],
                $captain['abilities'],
                'hero abilities are copied from the catalog'
            );
            expect_same([], test_catalog()->army('Red Company')['units'][0]['abilities'], 'units without abilities get an empty list');

            $engine = new MatchEngine(test_catalog());
            $none = new SequenceRandom([]);
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([17, 5]))['state'];
            foreach ([
                ['seat-a', 'chooseFirstPlayer', ['seatId' => 'seat-a']],
                ['seat-b', 'chooseStartPosition', ['start' => 2]],
                ['seat-b', 'pickHero', ['hero' => 'Test Marshal']],
                ['seat-a', 'pickHero', ['hero' => 'Test Captain']],
                ['seat-a', 'pickArmy', ['army' => 'Red Company']],
                ['seat-b', 'pickArmy', ['army' => 'Blue Company']],
            ] as [$seat, $kind, $payload]) {
                $state = $engine->apply($state, $seat, $kind, $payload, $none)['state'];
            }
            $hero = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['name'] === 'Test Captain'))[0];
            expect_same('Shield Wall', $hero['abilities'][0]['name'], 'the deployed hero carries Shield Wall');
            expect_same([], $hero['flags'], 'deployed units start with no flags');
        },

        'turns are numbered and carry fresh usage records' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            expect_same(1, $state['turn']['number'], 'the first turn is number one');
            expect_same(
                ['attacks' => 0, 'moveSpent' => 0, 'movedAfterAttack' => false, 'abilitiesUsed' => [], 'glareUsed' => []],
                array_intersect_key($state['turn'], array_flip(['attacks', 'moveSpent', 'movedAfterAttack', 'abilitiesUsed', 'glareUsed'])),
                'usage starts empty'
            );
            $next = vanilla_command($state, 'seat-a', 'endTurn')['state'];
            expect_same(2, $next['turn']['number'], 'each seat turn advances the number');
            expect_same(3, Turn::nextTurnNumberOf($next, 'seat-a'), 'seat-a plays next on turn three');
            expect_same(4, Turn::nextTurnNumberOf($next, 'seat-b'), 'the active seat\'s next turn is two ahead');
        },

        'old saved states are normalized with empty ability data' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            unset($state['turn']['number'], $state['turn']['attacks'], $state['turn']['abilitiesUsed']);
            $normal = MatchEngine::normalize($state);
            expect_same([], $normal['effects'], 'effects default to empty');
            expect_same(null, $normal['pending'], 'nothing is pending');
            expect_same([], $normal['units']['a1']['abilities'], 'units default to no abilities');
            expect_same([], $normal['units']['a1']['flags'], 'units default to no flags');
            expect_same(0, $normal['turn']['attacks'], 'attack count defaults to zero');
            expect_same([], $normal['turn']['abilitiesUsed'], 'ability usage defaults to empty');
        },

        'effects modify effective stats, refresh by name and expire at turn boundaries' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['defense' => 2, 'power' => 1]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $events = [];
            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_NEXT_TURN, $events);
            $state = Effects::add($state, 'Shield Wall', 'a1', 'a1', 'defense', 1, 'seat-a', Effects::UNTIL_NEXT_TURN, $events);
            $state = Effects::add($state, 'Warlord\'s Command', 'a1', 'a1', 'power', 1, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::add($state, 'Rune of Distraction', 'b1', 'a1', 'power', -5, 'seat-b', Effects::UNTIL_NEXT_TURN, $events);
            expect_same(4, count($events), 'each application is announced');
            expect_same(3, Stats::effective($state, 'a1', 'defense'), 'the same named effect does not stack');
            expect_same(0, Stats::effective($state, 'a1', 'power'), 'effective stats never drop below zero');

            $passed = vanilla_command($state, 'seat-a', 'endTurn');
            expect_same(['effectExpired', 'turnEnded', 'effectExpired'], array_column($passed['events'], 'kind'), 'end-of-turn effects expire, the turn passes, then seat-b\'s effects end');
            expect_same(['Shield Wall'], array_column(Effects::forUnit($passed['state'], 'a1'), 'name'), 'Shield Wall lasts until seat-a\'s next turn');

            $back = vanilla_command($passed['state'], 'seat-b', 'endTurn');
            expect_same(['turnEnded', 'effectExpired'], array_column($back['events'], 'kind'), 'Shield Wall fades as seat-a starts again');
            expect_same(2, Stats::effective($back['state'], 'a1', 'defense'), 'defense is back to base');
        },

        'movement and range read effective stats' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['move' => 1, 'range' => 1]),
                vanilla_unit('b1', 'seat-b', '3,0'),
            ]);
            $events = [];
            $state = Effects::add($state, 'Test Haste', 'a1', 'a1', 'move', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            $state = Effects::add($state, 'Test Sight', 'a1', 'a1', 'range', 2, 'seat-a', Effects::UNTIL_END_OF_TURN, $events);
            expect_same(['1,0', '2,0'], Movement::reachable($state, 'a1'), 'the extra move opens two more hexes');
            expect_same(['b1'], Combat::targets($state, 'a1'), 'the extra range reaches the enemy');
            expect_same(['1,0' => 1, '2,0' => 2], Movement::costs($state, 'a1'), 'costs count steps');
            expect_same(['1,0'], Movement::reachable($state, 'a1', 1), 'a budget caps the search');
        },

        'the registry only knows implemented abilities' => static function (): void {
            expect_same(false, AbilityRegistry::isPlayable('Teleport'), 'Teleport is deferred');
            expect_same(null, AbilityRegistry::find(ability_unit('x', 'seat-a', '0,0', ['Teleport']), 'Teleport'), 'deferred abilities have no handler');
        },

        'Trembling Fear weakens non-hero attackers only' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('a-hero', 'seat-a', '2,0', ['power' => 3, 'isHero' => true]),
                ability_unit('b-hero', 'seat-b', '1,0', ['Trembling Fear'], ['isHero' => true]),
            ]);
            $vs = ['attackerId' => 'a1', 'defenderId' => 'b-hero'];
            expect_same(2, Stats::effective($state, 'a1', 'power', $vs), 'a unit attacking Urgrok loses one Power');
            expect_same(3, Stats::effective($state, 'a-hero', 'power', ['attackerId' => 'a-hero', 'defenderId' => 'b-hero']), 'heroes are not afraid');
            expect_same(3, Stats::effective($state, 'a1', 'power'), 'outside an attack nothing changes');
        },

        'Reckless Fury adds a Power per wound' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(2)), [
                ability_unit('a1', 'seat-a', '0,0', ['Reckless Fury'], ['power' => 2, 'wounds' => 2, 'hp' => 5]),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(4, Stats::effective($state, 'a1', 'power'), 'two wounds, two more Power');
        },

        'Unbreakable Line needs the other guard or the center adjacent' => static function (): void {
            $guard = ability_unit('a1', 'seat-a', '0,0', ['Unbreakable Line'], ['name' => 'Left Guard', 'defense' => 3]);
            $alone = vanilla_battle(vanilla_board(vanilla_line(4)), [$guard, vanilla_unit('a2', 'seat-a', '2,0', ['name' => 'Center']), vanilla_unit('b1', 'seat-b', '3,0')]);
            expect_same(3, Stats::effective($alone, 'a1', 'defense'), 'no partner adjacent');
            $line = vanilla_battle(vanilla_board(vanilla_line(4)), [$guard, vanilla_unit('a2', 'seat-a', '1,0', ['name' => 'Center']), vanilla_unit('b1', 'seat-b', '3,0')]);
            expect_same(4, Stats::effective($line, 'a1', 'defense'), 'the Center holds the line');
            $enemyCenter = vanilla_battle(vanilla_board(vanilla_line(4)), [$guard, vanilla_unit('b2', 'seat-b', '1,0', ['name' => 'Center']), vanilla_unit('b1', 'seat-b', '3,0')]);
            expect_same(3, Stats::effective($enemyCenter, 'a1', 'defense'), 'an enemy Center is no partner');
        },

        'Evasion makes attacks miss on an 11 or higher' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('a2', 'seat-a', '2,0', ['power' => 3]),
                ability_unit('b1', 'seat-b', '1,0', ['Evasion'], ['defense' => 1, 'hp' => 5]),
            ]);
            $missed = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [11]);
            expect_same(['evaded'], array_column($missed['events'], 'kind'), 'no dice are rolled after a successful evasion');
            expect_same(['unitId' => 'b1', 'attackerId' => 'a1', 'roll' => 11, 'evaded' => true], $missed['events'][0]['payload'], 'the roll is reported');
            $hit = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [10, 1, 1, 1, 6]);
            expect_same(['evaded', 'attackResolved'], array_column($hit['events'], 'kind'), 'a failed evasion still rolls the attack');
            expect_same(3, $hit['state']['units']['b1']['wounds'], 'the attack lands');
        },

        'Evasion applies to disengagement strikes' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '1,0', ['Evasion']),
                vanilla_unit('b1', 'seat-b', '0,0'),
            ]);
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'], [15]);
            expect_same(
                [['byUnitId' => 'b1', 'faces' => [], 'wounds' => 0, 'evasionRoll' => 15, 'evaded' => true]],
                $moved['events'][0]['payload']['disengagement'],
                'the strike is evaded before any die'
            );
        },

        'One Last Horn survives the first defeat at 1 HP' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                ability_unit('b-hero', 'seat-b', '1,0', ['One Last Horn'], ['isHero' => true, 'defense' => 0, 'hp' => 2]),
                vanilla_unit('b2', 'seat-b', '2,0'),
            ]);
            $first = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b-hero'], [1, 1, 1]);
            expect_same(['attackResolved', 'survivedDefeat'], array_slice(array_column($first['events'], 'kind'), 0, 2), 'the horn sounds');
            expect_same(1, $first['state']['units']['b-hero']['wounds'], 'he stands at 1 HP');
            expect_same(true, $first['state']['units']['b-hero']['flags']['lastHornSpent'], 'the horn is spent');
            $later = $first['state'];
            $later['turn'] = Turn::fresh($later, 'seat-a', 3);
            $second = vanilla_command($later, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b-hero'], [1, 1, 1]);
            expect_same(null, $second['state']['units']['b-hero']['hex'], 'the second defeat is final');
        },

        'Fragile Focus locks spells on Malachar\'s next turn' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                ability_unit('b-hero', 'seat-b', '1,0', ['Fragile Focus'], ['isHero' => true, 'defense' => 0, 'hp' => 6]),
            ]);
            $hit = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b-hero'], [1]);
            expect_same(2, $hit['state']['units']['b-hero']['flags']['spellsLockedOnTurn'], 'seat-b\'s next turn is number two');
        },

        'Dual Strike keeps the activation open for a second attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                ability_unit('a1', 'seat-a', '1,0', ['Dual Strike'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '0,0', ['defense' => 0, 'hp' => 5]),
                vanilla_unit('b2', 'seat-b', '2,0', ['defense' => 0, 'hp' => 5]),
            ]);
            $first = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]);
            expect_same('a1', $first['state']['turn']['activeUnitId'], 'the activation stays open');
            expect_game_error('alreadyMoved', static fn() => vanilla_command($first['state'], 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0']));
            $second = vanilla_command($first['state'], 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b2'], [1]);
            expect_same(1, $second['state']['units']['b2']['wounds'], 'the second strike lands');
            expect_same(null, $second['state']['turn']['activeUnitId'], 'two attacks spend the activation');
        },

        'Silent Sting splits its move around the attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a1', 'seat-a', '0,0', ['Silent Sting'], ['move' => 3, 'range' => 4, 'power' => 1]),
                vanilla_unit('b1', 'seat-b', '5,0', ['defense' => 0, 'hp' => 5]),
            ]);
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '1,0'])['state'];
            $struck = vanilla_command($moved, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1])['state'];
            expect_same('a1', $struck['turn']['activeUnitId'], 'the Thornshaft may still move');
            expect_game_error('noAttacksLeft', static fn() => vanilla_command($struck, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]));
            expect_game_error('hexUnreachable', static fn() => vanilla_command($struck, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '4,0']));
            $after = vanilla_command($struck, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0'])['state'];
            expect_same('3,0', $after['units']['a1']['hex'], 'two remaining steps carry it on');
            expect_same(null, $after['turn']['activeUnitId'], 'the move after the attack ends the activation');
        },

        'Bolt of Annihilation strikes at Power 6 from range 5' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Bolt of Annihilation'], ['isHero' => true, 'range' => 1, 'power' => 1]),
                vanilla_unit('b1', 'seat-b', '5,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $actions = (new MatchEngine(test_catalog()))->legalActions($state, 'seat-a');
            expect_same(
                [['kind' => 'specialAttack', 'unitId' => 'a-hero', 'ability' => 'Bolt of Annihilation', 'targetHexes' => ['5,0']]],
                array_values(array_filter($actions, static fn(array $a): bool => $a['kind'] === 'specialAttack')),
                'the bolt reaches what the hero cannot'
            );
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Bolt of Annihilation', 'targetUnitIds' => ['b1']], [1, 1, 1, 1, 1, 6]);
            expect_same('Bolt of Annihilation', $result['events'][0]['payload']['ability'], 'the event names the ability');
            expect_same(6, count($result['events'][0]['payload']['attackFaces']), 'six dice');
            expect_same(5, $result['state']['units']['b1']['wounds'], 'five swords land');
        },

        'special attacks reject unknown, deferred and locked abilities' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Bolt of Annihilation', 'Teleport'], ['isHero' => true]),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $payload = static fn(string $name): array => ['unitId' => 'a-hero', 'ability' => $name, 'targetUnitIds' => ['b1']];
            expect_game_error('unknownAbility', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('Fireball')));
            expect_game_error('abilityNotPlayable', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('Teleport')));
            $locked = $state;
            $locked['units']['a-hero']['flags']['spellsLockedOnTurn'] = $state['turn']['number'];
            expect_game_error('abilityLocked', static fn() => vanilla_command($locked, 'seat-a', 'specialAttack', $payload('Bolt of Annihilation')));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Bolt of Annihilation', 'targetUnitIds' => ['a-hero']]));
        },

        'Chaos Strike doubles on 10 or more, fizzles below, and always lowers defense' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Chaos Strike'], ['isHero' => true, 'power' => 2, 'defense' => 3]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $payload = ['unitId' => 'a-hero', 'ability' => 'Chaos Strike', 'targetUnitIds' => ['b1']];
            $surge = vanilla_command($state, 'seat-a', 'specialAttack', $payload, [10, 1, 1, 1, 1]);
            expect_same(['effectApplied', 'chaosRolled', 'attackResolved'], array_slice(array_column($surge['events'], 'kind'), 0, 3), 'the surge doubles the dice (then the spent turn passes)');
            expect_same(4, $surge['state']['units']['b1']['wounds'], 'four dice from Power 2');
            expect_same(1, Stats::effective($surge['state'], 'a-hero', 'defense'), 'Defense drops by two');
            $fizzle = vanilla_command($state, 'seat-a', 'specialAttack', $payload, [9]);
            expect_same(['effectApplied', 'chaosRolled'], array_slice(array_column($fizzle['events'], 'kind'), 0, 2), 'no attack on a 9');
            expect_same(['unitId' => 'a-hero', 'roll' => 9, 'struck' => false], $fizzle['events'][1]['payload'], 'the roll is reported');
        },

        'Fireball splashes every other adjacent unit for one die without defense' => static function (): void {
            $board = vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 0, '4,0' => 0, '3,-1' => 0]);
            $state = vanilla_battle($board, [
                ability_unit('a1', 'seat-a', '0,0', ['Fireball'], ['range' => 1]),
                vanilla_unit('a2', 'seat-a', '4,0', ['hp' => 3]),
                vanilla_unit('b1', 'seat-b', '3,0', ['defense' => 0, 'hp' => 9]),
                vanilla_unit('b2', 'seat-b', '3,-1', ['hp' => 1]),
            ]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a1', 'ability' => 'Fireball', 'targetUnitIds' => ['b1']], [6, 6, 6, 6, 1, 1]);
            expect_same(['attackResolved', 'splashDamage', 'unitDefeated'], array_slice(array_column($result['events'], 'kind'), 0, 3), 'strike, splash, then a casualty');
            expect_same(
                ['ability' => 'Fireball', 'hits' => [['unitId' => 'a2', 'faces' => ['sword'], 'wounds' => 1], ['unitId' => 'b2', 'faces' => ['sword'], 'wounds' => 1]]],
                $result['events'][1]['payload'],
                'friends are scorched too'
            );
            expect_same(null, $result['state']['units']['b2']['hex'], 'the splash can kill');
        },

        'Fireball splash that wipes out the last enemy ends the match without further steps' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Fireball']),
                vanilla_unit('b1', 'seat-b', '2,0', ['defense' => 0, 'hp' => 1]),
                vanilla_unit('b2', 'seat-b', '3,0', ['hp' => 1]),
            ]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a1', 'ability' => 'Fireball', 'targetUnitIds' => ['b1']], [1, 6, 6, 6, 1]);
            expect_same('completed', $result['state']['phase'], 'the splash finishes the war');
            expect_same(null, $result['state']['pending'], 'nothing is left pending');
        },

        'Mystic Shield gives an ally +3 Defense and uses the attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Mystic Shield'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '4,0', ['defense' => 1]),
                vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $result = vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Mystic Shield', 'targetUnitIds' => ['a2']]);
            expect_same(4, Stats::effective($result['state'], 'a2', 'defense'), 'the ward holds');
            expect_same(null, $result['state']['turn']['activeUnitId'], 'the spell was Malachar\'s attack');
        },
    ];
}
