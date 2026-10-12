<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

/** Stun $unitId until the end of its seat's next turn, as Stunning Blow does. */
function stun(array $state, string $unitId, string $sourceUnitId = 'nobody'): array
{
    $events = [];
    $seat = $state['units'][$unitId]['seatId'];
    return UnitConditions::apply($state, 'Stunned', $unitId, $sourceUnitId, UnitConditions::afterTurn(Turn::nextTurnNumberOf($state, $seat)), $events);
}

function vanilla_forced_tests(): array
{
    return [
        'a stunned unit is weakened, rooted and makes no parting strike until its next turn ends' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                vanilla_unit('a1', 'seat-a', '1,0'),
                vanilla_unit('b1', 'seat-b', '0,0', ['power' => 3]),
            ]);
            $state = stun($state, 'b1', 'a1');
            expect_same(1, Stats::effective($state, 'b1', 'power'), 'Stunned costs 2 Power');
            expect_same([['name' => 'Stunned', 'unitId' => 'b1', 'sourceUnitId' => 'a1', 'expires' => ['kind' => 'afterTurn', 'turnNumber' => 2], 'data' => []]], $state['conditions'], 'the stun lasts through seat-b\'s next turn (turn 2)');

            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a1', 'to' => '3,0']);
            expect_same([], $moved['events'][0]['payload']['disengagement'], 'a stunned enemy makes no parting strike');

            $theirTurn = $moved['state']; // seat-a's only activation is spent, so the turn passes
            expect_same(2, $theirTurn['turn']['number'], 'seat-b\'s turn is number two');
            expect_true(!in_array('moveUnit', array_column((new MatchEngine(test_catalog()))->legalActions($theirTurn, 'seat-b'), 'kind'), true), 'a stunned unit has no move');
            expect_game_error('unitStunned', static fn() => vanilla_command($theirTurn, 'seat-b', 'moveUnit', ['unitId' => 'b1', 'to' => '1,0']));

            $over = vanilla_command($theirTurn, 'seat-b', 'endTurn');
            expect_same(['conditionExpired', 'turnEnded'], array_column($over['events'], 'kind'), 'the stun lifts as seat-b\'s turn ends');
            expect_same([], $over['state']['conditions'], 'no conditions remain');
            expect_same(3, Stats::effective($over['state'], 'b1', 'power'), 'Power is back');
        },

        'conditions refresh instead of stacking and survive a JSON round trip' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0', ['power' => 4])]);
            $events = [];
            $state = UnitConditions::apply($state, 'Stunned', 'b1', 'a1', UnitConditions::afterTurn(2), $events);
            $state = UnitConditions::apply($state, 'Stunned', 'b1', 'a1', UnitConditions::afterTurn(4), $events);
            expect_same(['conditionApplied', 'conditionApplied'], array_column($events, 'kind'), 'each application is announced');
            expect_same(1, count($state['conditions']), 'one stun per unit');
            expect_same(4, $state['conditions'][0]['expires']['turnNumber'], 'the later expiry wins');
            expect_same(2, Stats::effective($state, 'b1', 'power'), 'stuns do not stack');
            $saved = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_same(true, UnitConditions::has($saved, 'b1', 'Stunned'), 'the stun survives saving');
        },

        'a defeated unit loses its conditions' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 1]),
                vanilla_unit('b2', 'seat-b', '2,0'),
            ]);
            $state = stun($state, 'b1', 'a1');
            $result = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]);
            expect_same([], $result['state']['conditions'], 'the fallen are no longer stunned');
        },

        'old saved states gain an empty condition list' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(3)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '2,0')]);
            unset($state['conditions']);
            expect_same([], MatchEngine::normalize($state)['conditions'], 'conditions default to empty');
        },

        'a free push moves the unit straight back without dice' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 2, 'a1', new SequenceRandom([]), $events);
            expect_same('3,0', $state['units']['b1']['hex'], 'two hexes back');
            expect_same(
                ['unitId' => 'b1', 'sourceUnitId' => 'a1', 'from' => '1,0', 'to' => '3,0', 'path' => ['1,0', '2,0', '3,0'], 'collisions' => [], 'falls' => [], 'blocked' => null],
                $events[0]['payload'],
                'the push is reported'
            );
            expect_same(['1,0', '2,0', '3,0'], $events[0]['focusHexes'], 'the path is focused');
        },

        'pushing into a unit stops the push and hurts both' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(4)), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 5]),
                vanilla_unit('a2', 'seat-a', '2,0', ['hp' => 5]),
            ]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 1, 'a1', new SequenceRandom([1, 4]), $events);
            expect_same('1,0', $state['units']['b1']['hex'], 'blocked in place');
            expect_same('unit', $events[0]['payload']['blocked'], 'blocked by a unit');
            expect_same([['unitId' => 'b1', 'faces' => ['sword'], 'wounds' => 1], ['unitId' => 'a2', 'faces' => ['shield'], 'wounds' => 0]], $events[0]['payload']['collisions'], 'each rolls one die');
            expect_same(1, $state['units']['b1']['wounds'], 'the pushed unit is hurt');
        },

        'walls, sight blockers and the board edge stop a push and hurt the pushed unit' => static function (): void {
            $cases = [
                'wall' => [vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 2]), 'wall'],
                'blocker' => [vanilla_board(vanilla_line(3), ['2,0']), 'wall'],
                'edge' => [vanilla_board(vanilla_line(2)), 'edge'],
            ];
            foreach ($cases as $label => [$board, $blocked]) {
                $state = vanilla_battle($board, [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 5])]);
                $events = [];
                $state = Forced::push($state, 'b1', [1, 0], 1, 'a1', new SequenceRandom([1]), $events);
                expect_same($blocked, $events[0]['payload']['blocked'], "{$label} blocks");
                expect_same('1,0', $state['units']['b1']['hex'], "{$label} holds the unit in place");
                expect_same(1, $state['units']['b1']['wounds'], "{$label} costs a die of damage");
            }
        },

        'falling two or more levels costs a die per level and the push continues' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 3, '1,0' => 3, '2,0' => 1, '3,0' => 1]), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 5]),
            ]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 2, 'a1', new SequenceRandom([1, 1]), $events);
            expect_same('3,0', $state['units']['b1']['hex'], 'over the ledge and on');
            expect_same([['levels' => 2, 'faces' => ['sword', 'sword'], 'wounds' => 2]], $events[0]['payload']['falls'], 'two levels, two dice');
            expect_same(2, $state['units']['b1']['wounds'], 'the fall hurts');

            $gentle = vanilla_battle(vanilla_board(['0,0' => 1, '1,0' => 1, '2,0' => 0]), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0')]);
            $events = [];
            $gentle = Forced::push($gentle, 'b1', [1, 0], 1, 'a1', new SequenceRandom([]), $events);
            expect_same([], $events[0]['payload']['falls'], 'a one-level drop is a normal step');
        },

        'a unit killed mid-push stops there, and a lethal push can end the match' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 3, '1,0' => 3, '2,0' => 1, '3,0' => 1, '4,0' => 1]), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 1]),
                vanilla_unit('b2', 'seat-b', '4,0'),
            ]);
            $events = [];
            $state = Forced::push($state, 'b1', [1, 0], 2, 'a1', new SequenceRandom([1, 6]), $events);
            expect_same(['unitPushed', 'unitDefeated'], array_column($events, 'kind'), 'the push is reported before the fall kills');
            expect_same(['1,0', '2,0'], $events[0]['payload']['path'], 'the dead do not slide on');

            $last = vanilla_battle(vanilla_board(vanilla_line(2)), [vanilla_unit('a1', 'seat-a', '0,0'), vanilla_unit('b1', 'seat-b', '1,0', ['hp' => 1])]);
            $events = [];
            $last = Forced::push($last, 'b1', [1, 0], 1, 'a1', new SequenceRandom([1]), $events);
            expect_same(['unitPushed', 'unitDefeated', 'matchCompleted'], array_column($events, 'kind'), 'the edge finishes the war');
            expect_same('completed', $last['phase'], 'the match is over');
        },

        'directions and teleport destinations' => static function (): void {
            expect_same([1, 0], Forced::directionFrom('0,0', '1,0'), 'adjacent hexes give a direction');
            expect_same([0, -1], Forced::directionFrom('2,3', '2,2'), 'any of the six');
            expect_same(null, Forced::directionFrom('0,0', '2,0'), 'non-adjacent hexes give none');

            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 2, '3,0' => 4, '4,0' => 0, '0,1' => 0], ['0,1']), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(['2,0'], Forced::teleportDestinations($state, 'a1'), 'occupied, blocked and too-high-or-far hexes are excluded');
        },

        'Knockback drives a target back 1 hex on a failed save and 2 on a natural 1' => static function (): void {
            $setup = static fn(): array => vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $attack = ['unitId' => 'a1', 'targetUnitId' => 'b1'];
            $pushed = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [1, 5]);
            expect_same(['attackResolved', 'saveRolled', 'unitPushed'], array_slice(array_column($pushed['events'], 'kind'), 0, 3), 'hit, save, push');
            expect_same(['unitId' => 'b1', 'sourceUnitId' => 'a1', 'ability' => 'Knockback', 'roll' => 5, 'saved' => false], $pushed['events'][1]['payload'], 'the save is reported');
            expect_same('2,0', $pushed['state']['units']['b1']['hex'], 'one hex back');
            $flung = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [1, 1]);
            expect_same('3,0', $flung['state']['units']['b1']['hex'], 'a natural 1 means two hexes');
            $held = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [1, 11]);
            expect_same('1,0', $held['state']['units']['b1']['hex'], '11 or more resists');
            $glancing = vanilla_command($setup(), 'seat-a', 'attackUnit', $attack, [6, 4]);
            expect_same(['attackResolved', 'saveRolled', 'unitPushed'], array_slice(array_column($glancing['events'], 'kind'), 0, 3), 'a blow without wounds still forces a save');
        },

        'no save after an evaded or lethal attack' => static function (): void {
            $evader = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                ability_unit('b1', 'seat-b', '1,0', ['Evasion'], ['hp' => 9]),
            ]);
            $missed = vanilla_command($evader, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [15]);
            expect_same(['evaded'], array_slice(array_column($missed['events'], 'kind'), 0, 1), 'evaded');
            expect_true(!in_array('saveRolled', array_column($missed['events'], 'kind'), true), 'no save without an attack');
            $doomed = vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 1]),
                vanilla_unit('b2', 'seat-b', '3,0'),
            ]);
            $killed = vanilla_command($doomed, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1]);
            expect_true(!in_array('saveRolled', array_column($killed['events'], 'kind'), true), 'the dead make no save');
        },

        'Knockback waits for a Moonlight Glare decision before its save' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a1', 'seat-a', '0,0', ['Knockback'], ['power' => 1]),
                ability_unit('b1', 'seat-b', '1,0', ['Moonlight Glare'], ['defense' => 1, 'hp' => 9]),
                vanilla_unit('a2', 'seat-a', '4,0'),
            ]);
            $opened = vanilla_command($state, 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [1, 6], 1000);
            expect_same(['choiceOpened'], array_column($opened['events'], 'kind'), 'paused before the save');
            $kept = vanilla_command($opened['state'], 'seat-b', 'resolveChoice', ['choiceId' => 'choice-1', 'option' => 'keep'], [3], 1001);
            expect_same(['choiceResolved', 'attackResolved', 'saveRolled', 'unitPushed'], array_column($kept['events'], 'kind'), 'the save follows the resumed attack');
        },

        'Stunning Blow stuns on a failed save until the end of the victim\'s next turn' => static function (): void {
            $setup = static fn(): array => vanilla_battle(vanilla_board(vanilla_line(4)), [
                ability_unit('a1', 'seat-a', '0,0', ['Stunning Blow'], ['power' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0', ['defense' => 0, 'hp' => 9]),
            ]);
            $stunned = vanilla_command($setup(), 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [6, 10]);
            expect_same(['attackResolved', 'saveRolled', 'conditionApplied'], array_slice(array_column($stunned['events'], 'kind'), 0, 3), 'stunned');
            expect_same(['kind' => 'afterTurn', 'turnNumber' => 2], $stunned['events'][2]['payload']['expires'], 'through seat-b\'s next turn');
            $resisted = vanilla_command($setup(), 'seat-a', 'attackUnit', ['unitId' => 'a1', 'targetUnitId' => 'b1'], [6, 11]);
            expect_same([], $resisted['state']['conditions'], '11 or more resists');
        },

        'Gust of Wind pushes an adjacent quick enemy 2 hexes instead of Thalion moving' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a-hero', 'seat-a', '1,0', ['Gust of Wind'], ['isHero' => true]),
                vanilla_unit('b1', 'seat-b', '2,0', ['move' => 3]),
                vanilla_unit('b2', 'seat-b', '0,0', ['move' => 2]),
            ]);
            $actions = array_values(array_filter((new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'), static fn(array $a): bool => $a['kind'] === 'useAbility'));
            expect_same([['kind' => 'useAbility', 'unitId' => 'a-hero', 'ability' => 'Gust of Wind', 'targetHexes' => ['2,0']]], $actions, 'only enemies with Move 3+ can be blown away');
            $gust = vanilla_command($state, 'seat-a', 'useAbility', ['unitId' => 'a-hero', 'ability' => 'Gust of Wind', 'targetUnitId' => 'b1']);
            expect_same(['abilityUsed', 'unitPushed'], array_slice(array_column($gust['events'], 'kind'), 0, 2), 'the wind blows');
            expect_same('4,0', $gust['state']['units']['b1']['hex'], 'two hexes back');
            expect_game_error('alreadyMoved', static fn() => vanilla_command($gust['state'], 'seat-a', 'moveUnit', ['unitId' => 'a-hero', 'to' => '2,0']));
        },

        'Gust of Wind needs Thalion still and unstunned, and a blocked gust still costs his move' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(5)), [
                ability_unit('a-hero', 'seat-a', '1,0', ['Gust of Wind'], ['isHero' => true]),
                vanilla_unit('b1', 'seat-b', '0,0', ['move' => 3, 'hp' => 5]),
                vanilla_unit('b2', 'seat-b', '3,0'), // in reach after Thalion moves, so his turn stays open
            ]);
            $gust = ['unitId' => 'a-hero', 'ability' => 'Gust of Wind', 'targetUnitId' => 'b1'];
            $moved = vanilla_command($state, 'seat-a', 'moveUnit', ['unitId' => 'a-hero', 'to' => '2,0'], [6]);
            expect_game_error('gustNeedsStillness', static fn() => vanilla_command($moved['state'], 'seat-a', 'useAbility', $gust + ['targetUnitId' => 'b1']));
            expect_game_error('unitStunned', static fn() => vanilla_command(stun($state, 'a-hero'), 'seat-a', 'useAbility', $gust));
            $edge = vanilla_command($state, 'seat-a', 'useAbility', $gust, [1]);
            expect_same('edge', $edge['events'][1]['payload']['blocked'], 'b1 is already at the edge');
            expect_same(1, $edge['state']['units']['b1']['wounds'], 'and takes a die for it');
            expect_same(true, $edge['state']['turn']['moved'], 'Thalion\'s move is spent');
        },

        'Teleport moves a friendly unit (or Malachar) to a valid hex and uses his attack' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 2, '4,0' => 0, '5,0' => 0, '6,0' => 0]), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Teleport'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '1,0'),
                vanilla_unit('b1', 'seat-b', '6,0'),
            ]);
            $actions = array_values(array_filter((new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'), static fn(array $a): bool => $a['kind'] === 'specialAttack'));
            expect_same([['kind' => 'specialAttack', 'unitId' => 'a-hero', 'ability' => 'Teleport', 'targetHexes' => ['0,0', '1,0']]], $actions, 'Malachar and his ally can be moved');
            $payload = static fn(string $who, string $to): array => ['unitId' => 'a-hero', 'ability' => 'Teleport', 'targetUnitIds' => [$who], 'toHex' => $to];
            $blink = vanilla_command($state, 'seat-a', 'specialAttack', $payload('a2', '3,0'));
            expect_same(['unitId' => 'a2', 'casterId' => 'a-hero', 'from' => '1,0', 'to' => '3,0'], $blink['events'][0]['payload'], 'the blink is reported');
            expect_same('3,0', $blink['state']['units']['a2']['hex'], 'two hexes and two levels cost three');
            expect_same(null, $blink['state']['turn']['activeUnitId'], 'the spell was Malachar\'s attack');
            expect_game_error('invalidDestination', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('a2', '5,0')));
            expect_game_error('invalidDestination', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('a2', '6,0')));
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', $payload('b1', '2,0')));
            $self = vanilla_command($state, 'seat-a', 'specialAttack', $payload('a-hero', '2,0'));
            expect_same('2,0', $self['state']['units']['a-hero']['hex'], 'Malachar may teleport himself');
            $locked = $state;
            $locked['units']['a-hero']['flags']['spellsLockedOnTurns'] = [$state['turn']['number']];
            expect_game_error('abilityLocked', static fn() => vanilla_command($locked, 'seat-a', 'specialAttack', $payload('a2', '2,0')));
        },

        'a stunned Malachar cannot teleport himself but can still move an ally' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(6)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Teleport'], ['isHero' => true]),
                vanilla_unit('a2', 'seat-a', '1,0'),
                vanilla_unit('b1', 'seat-b', '5,0'),
            ]);
            $state = stun($state, 'a-hero');
            $actions = array_values(array_filter((new MatchEngine(test_catalog()))->legalActions($state, 'seat-a'), static fn(array $a): bool => $a['kind'] === 'specialAttack'));
            expect_same(['1,0'], $actions[0]['targetHexes'], 'only the ally can be teleported');
            expect_game_error('invalidAbilityTarget', static fn() => vanilla_command($state, 'seat-a', 'specialAttack', ['unitId' => 'a-hero', 'ability' => 'Teleport', 'targetUnitIds' => ['a-hero'], 'toHex' => '3,0']));
        },

        'a lethal Gust of Wind is announced before the match ends' => static function (): void {
            $state = vanilla_battle(vanilla_board(vanilla_line(2)), [
                ability_unit('a-hero', 'seat-a', '0,0', ['Gust of Wind'], ['isHero' => true]),
                vanilla_unit('b1', 'seat-b', '1,0', ['move' => 3, 'hp' => 1]),
            ]);
            $gust = vanilla_command($state, 'seat-a', 'useAbility', ['unitId' => 'a-hero', 'ability' => 'Gust of Wind', 'targetUnitId' => 'b1'], [1]);
            expect_same(['abilityUsed', 'unitPushed', 'unitDefeated', 'matchCompleted'], array_column($gust['events'], 'kind'), 'nothing follows the end of the match');
        },
    ];
}
