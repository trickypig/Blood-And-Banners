<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\UnitConditions;
use BloodAndBanners\Rules\Vanilla\Wounds;

function passive_condition_tests(): array
{
    return [
        'polar bears start hibernating during setup and removal clears their condition' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            $state = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => 'Polar Bear', 'hex' => '7,0'], new SequenceRandom([]))['state'];
            expect_true(UnitConditions::has($state, 'v-m1', 'Hibernating'), 'a newly placed bear is hibernating');
            expect_same(5, Stats::effective($state, 'v-m1', 'defense'), 'hibernation grants two defense');
            expect_same([], $state['units']['v-m1']['inactiveAbilities'], 'hibernation is playable');
            $state = $engine->apply($state, 'void-lord', 'removeMonster', ['unitId' => 'v-m1'], new SequenceRandom([]))['state'];
            expect_same([], $state['conditions'], 'removing a setup bear leaves no dangling condition');
        },
        'activation wakes the bear even without moving or attacking and only a skipped owner turn restores hibernation' => static function (): void {
            $state = coop_battle([monster_unit('bear', 'Polar Bear', '0,0'), coop_hero('hero', 'hero-a', '8,0')], 'void-lord');
            $events = [];
            $state = UnitConditions::apply($state, 'Hibernating', 'bear', 'bear', ['kind' => 'untilCleared'], $events);
            expect_same(5, Stats::effective($state, 'bear', 'defense'), 'hibernation is a defense condition');
            $result = coop_command($state, 'void-lord', 'activateUnit', ['unitId' => 'bear']);
            $state = $result['state'];
            expect_same(false, UnitConditions::has($state, 'bear', 'Hibernating'), 'activation alone wakes it');
            expect_same(3, Stats::effective($state, 'bear', 'defense'), 'its defense immediately returns to base');
            expect_same(1, count(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'conditionExpired')), 'waking is logged');
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            expect_same(false, UnitConditions::has($state, 'bear', 'Hibernating'), 'activated bears stay awake at owner turn end');
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_same(false, UnitConditions::has($state, 'bear', 'Hibernating'), 'an enemy turn cannot restore it');
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            expect_true(UnitConditions::has($state, 'bear', 'Hibernating'), 'skipping the next owner turn restores it');
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_same(5, Stats::effective($state, 'bear', 'defense'), 'the bonus survives saving and reconnecting');
        },
        'implicit attack and movement activations wake a bear but forced movement does not' => static function (): void {
            foreach (['activateUnit', 'moveUnit', 'attackUnit'] as $kind) {
                $state = coop_battle([monster_unit('bear', 'Polar Bear', '0,0'), coop_hero('hero', 'hero-a', '1,0', ['hp' => 20])], 'void-lord');
                $events = [];
                $state = UnitConditions::apply($state, 'Hibernating', 'bear', 'bear', ['kind' => 'untilCleared'], $events);
                $state['board']['0,1'] = $state['board']['0,0'];
                $payload = ['unitId' => 'bear', 'targetUnitId' => 'hero', 'to' => '0,1'];
                $state = coop_command($state, 'void-lord', $kind, $payload, array_fill(0, 30, 6))['state'];
                expect_same(false, UnitConditions::has($state, 'bear', 'Hibernating'), $kind . ' wakes the bear');
            }
            $state = coop_battle([monster_unit('bear', 'Polar Bear', '1,0'), coop_hero('hero', 'hero-a', '8,0')]);
            $events = [];
            $state = UnitConditions::apply($state, 'Hibernating', 'bear', 'bear', ['kind' => 'untilCleared'], $events);
            $state = BloodAndBanners\Rules\Vanilla\Forced::push($state, 'bear', [1, 0], 1, null, new SequenceRandom([]), $events);
            expect_true(UnitConditions::has($state, 'bear', 'Hibernating'), 'being pushed is not activation');
        },
        'forest defeats enrage every surviving treant regardless of allegiance and stack across owner turn decay' => static function (): void {
            $state = coop_battle([
                monster_unit('tree', 'Treant', '0,0'),
                monster_unit('other', 'Treant', '8,0', ['seatId' => 'hero-a', 'side' => 'heroes']),
                monster_unit('dead-tree', 'Treant', null),
                monster_unit('forest-a', 'Satyr Warrior', '2,0'),
                monster_unit('forest-b', 'Satyr Warrior', '3,0', ['seatId' => 'hero-a', 'side' => 'heroes']),
                monster_unit('forest-c', 'Satyr Warrior', '4,0'),
                monster_unit('ice', 'Polar Bear', '5,0'),
                coop_hero('hero', 'hero-a', '7,0'),
            ], 'hero-a');
            $events = [];
            $state = MatchEngine::normalize($state);
            $state = Wounds::apply($state, 'ice', 99, $events);
            expect_same(null, UnitConditions::named($state, 'tree', 'Enraged'), 'nonforest defeats grant nothing');
            $state = Wounds::apply($state, 'forest-a', 99, $events);
            expect_same(2, UnitConditions::named($state, 'tree', 'Enraged')['data']['level'] ?? 0, 'one defeat gives two levels');
            $state = Wounds::apply($state, 'forest-b', 99, $events);
            expect_same(4, UnitConditions::named($state, 'tree', 'Enraged')['data']['level'] ?? 0, 'two defeats stack to four');
            expect_same(4, UnitConditions::named($state, 'other', 'Enraged')['data']['level'] ?? 0, 'an enemy treant also enrages');
            expect_same(null, UnitConditions::named($state, 'dead-tree', 'Enraged'), 'defeated treants never receive rage');
            $state = Wounds::apply($state, 'forest-b', 99, $events);
            expect_same(7, Stats::effective($state, 'tree', 'power'), 'a repeated defeat does not double count');
            expect_same(8, Stats::effective($state, 'tree', 'defense'), 'four levels add four defense');
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_same(4, UnitConditions::named($state, 'tree', 'Enraged')['data']['level'], 'another owner turn does not decay this tree');
            expect_same(3, UnitConditions::named($state, 'other', 'Enraged')['data']['level'], 'the owning tree decays even unactivated');
            $state = coop_command($state, 'void-lord', 'activateUnit', ['unitId' => 'tree'])['state'];
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            expect_same(3, UnitConditions::named($state, 'tree', 'Enraged')['data']['level'], 'activated trees also decay once');
            $state = Wounds::apply($state, 'forest-c', 99, $events);
            expect_same(5, UnitConditions::named($state, 'tree', 'Enraged')['data']['level'], 'a later defeat adds two to the remaining three');
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            expect_same(8, Stats::effective($state, 'tree', 'power'), 'saved rage still modifies power');
            for ($i = 0; $i < 5; $i++) {
                $state = coop_command($state, 'hero-a', 'endTurn')['state'];
                $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            }
            expect_same(null, UnitConditions::named($state, 'tree', 'Enraged'), 'rage is removed at zero');
            expect_same(3, Stats::effective($state, 'tree', 'power'), 'power returns to base');
            expect_same(4, Stats::effective($state, 'tree', 'defense'), 'defense returns to base');
            $rageEvents = array_values(array_filter($events, static fn(array $e): bool => $e['kind'] === 'conditionApplied' && ($e['payload']['unitId'] ?? '') === 'tree'));
            expect_same([2, 4, 5], array_map(static fn(array $e): int => $e['payload']['data']['level'], $rageEvents), 'rage history stores each level rather than reading the final state');
        },
        'a defeated treant enrages survivors and end turn poison defeats apply before rage decays' => static function (): void {
            $state = MatchEngine::normalize(coop_battle([
                monster_unit('tree', 'Treant', '0,0'),
                monster_unit('fallen', 'Treant', '1,0'),
                monster_unit('satyr', 'Satyr Warrior', '2,0', ['wounds' => 2]),
                coop_hero('hero', 'hero-a', '8,0'),
            ], 'void-lord'));
            $events = [];
            $state = Wounds::apply($state, 'fallen', 99, $events);
            expect_same(2, UnitConditions::named($state, 'tree', 'Enraged')['data']['level'] ?? 0, 'a Treant is also a Forest creature');
            expect_same([], UnitConditions::forUnit($state, 'fallen'), 'the fallen tree gets no rage');
            $state = UnitConditions::apply($state, 'Poisoned', 'satyr', null, ['kind' => 'untilCleared'], $events);
            $state = coop_command($state, 'void-lord', 'endTurn', [], [1])['state'];
            expect_same(null, $state['units']['satyr']['hex'], 'poison defeated the forest creature');
            expect_same(3, UnitConditions::named($state, 'tree', 'Enraged')['data']['level'] ?? 0, 'two plus two then one decay gives three');
        },
        'enraged bonuses change actual combat dice including special attack power' => static function (): void {
            $state = MatchEngine::normalize(coop_battle([
                monster_unit('tree', 'Treant', '0,0'),
                monster_unit('satyr', 'Satyr Warrior', '2,0'),
                coop_hero('hero', 'hero-a', '1,0', ['hp' => 30]),
            ], 'void-lord'));
            $events = [];
            $state = Wounds::apply($state, 'satyr', 99, $events);
            $result = coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'tree', 'ability' => 'Column Swing', 'targetUnitIds' => ['hero']], array_fill(0, 40, 6));
            $attack = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'))[0]['payload'];
            expect_same(7, count($attack['attackFaces']), 'Column Swing rolls five printed dice plus two rage dice');
            $state['turn'] = BloodAndBanners\Rules\Vanilla\Turn::fresh($state, 'hero-a', 1);
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'hero', 'targetUnitId' => 'tree'], array_fill(0, 40, 6));
            $attack = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'))[0]['payload'];
            expect_same(6, count($attack['defenseFaces']), 'the tree rolls four printed dice plus two rage dice');
        },
    ];
}
