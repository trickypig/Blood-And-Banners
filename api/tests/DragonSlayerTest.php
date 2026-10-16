<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\SkyToss;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Poison;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

function dragon_slayer_tests(): array
{
    return [
        'dragon slayer awards the lethal normal or special attacker and persists across turns and saves' => static function (): void {
            foreach (['attackUnit', 'specialAttack'] as $kind) {
                $state = coop_battle([
                    ability_unit('slayer', 'hero-a', '0,0', ['Flame Strike'], ['isHero' => true, 'side' => 'heroes', 'hp' => 20, 'power' => 2, 'defense' => 1]),
                    monster_unit('dragon', 'Void Dragon', '1,0', ['hp' => 1, 'defense' => 0]),
                    monster_unit('spare', 'Ice Wolf', '8,0'),
                ]);
                $result = coop_command($state, 'hero-a', $kind, ['unitId' => 'slayer', 'targetUnitId' => 'dragon', 'ability' => 'Flame Strike', 'targetUnitIds' => ['dragon']], array_fill(0, 20, 1));
                $state = $result['state'];
                expect_same(1, UnitConditions::named($state, 'slayer', 'Dragon Slayer')['data']['level'] ?? 0, 'the killer receives one level');
                expect_same(3, Stats::effective($state, 'slayer', 'power'), 'the killer gains one power');
                expect_same(2, Stats::effective($state, 'slayer', 'defense'), 'the killer gains one defense');
                $state = coop_command($state, $state['turn']['activeSeatId'], 'endTurn')['state'];
                $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                expect_same(3, Stats::effective($state, 'slayer', 'power'), 'the bonus survives turn boundaries and reconnect');
                expect_same('permanent', UnitConditions::named($state, 'slayer', 'Dragon Slayer')['expires']['kind'], 'the bonus has no turn expiry');
                $awards = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'conditionApplied' && $e['payload']['name'] === 'Dragon Slayer'));
                expect_same(1, count($awards), 'one defeat awards exactly once');
                expect_same(1, $awards[0]['payload']['data']['level'], 'history records the earned level');
            }
        },
        'dragon slayer stacks for repeated dragon kills and changes later attack dice' => static function (): void {
            $state = coop_battle([
                ability_unit('slayer', 'hero-a', '0,0', ['Dual Strike'], ['isHero' => true, 'side' => 'heroes', 'range' => 4, 'power' => 2]),
                monster_unit('one', 'Void Dragon', '1,0', ['hp' => 1, 'defense' => 0]),
                monster_unit('two', 'Void Dragon', '2,0', ['hp' => 1, 'defense' => 0]),
                monster_unit('spare', 'Ice Wolf', '8,0'),
            ]);
            $state = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'slayer', 'targetUnitId' => 'one'], array_fill(0, 20, 1))['state'];
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'slayer', 'targetUnitId' => 'two'], array_fill(0, 20, 1));
            $attack = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'))[0]['payload'];
            expect_same(3, count($attack['attackFaces']), 'the first award adds a die to the second attack');
            expect_same(2, UnitConditions::named($result['state'], 'slayer', 'Dragon Slayer')['data']['level'] ?? 0, 'two kills stack to two levels');
            expect_same(4, Stats::effective($result['state'], 'slayer', 'power'), 'two awards add two power');
        },
        'lethal splash damage credits its caster with dragon slayer' => static function (): void {
            $state = coop_battle([
                ability_unit('caster', 'hero-a', '0,0', ['Fireball'], ['isHero' => true, 'side' => 'heroes', 'hp' => 20]),
                monster_unit('primary', 'Ice Wolf', '1,0', ['hp' => 30]),
                monster_unit('dragon', 'Void Dragon', '2,0', ['hp' => 1]),
                monster_unit('spare', 'Ice Wolf', '8,0'),
            ]);
            $result = coop_command($state, 'hero-a', 'specialAttack', ['unitId' => 'caster', 'ability' => 'Fireball', 'targetUnitIds' => ['primary']], array_fill(0, 50, 1));
            expect_same(null, $result['state']['units']['dragon']['hex'], 'the splash defeats the dragon');
            expect_same(1, UnitConditions::named($result['state'], 'caster', 'Dragon Slayer')['data']['level'] ?? 0, 'the caster earns the reward');
        },
        'a lethal disengagement strike awards dragon slayer to the striking unit' => static function (): void {
            $state = coop_battle([
                coop_hero('slayer', 'hero-a', '0,0'),
                monster_unit('dragon', 'Void Dragon', '1,0', ['hp' => 1]),
                monster_unit('spare', 'Ice Wolf', '8,0'),
            ], 'void-lord');
            $result = coop_command($state, 'void-lord', 'moveUnit', ['unitId' => 'dragon', 'to' => '2,0'], [1]);
            expect_same(null, $result['state']['units']['dragon']['hex'], 'the parting strike defeats the dragon');
            expect_same(1, UnitConditions::named($result['state'], 'slayer', 'Dragon Slayer')['data']['level'] ?? 0, 'the striker earns the reward');
        },
        'poison and attributed sky toss falls do not award dragon slayer' => static function (): void {
            $state = MatchEngine::normalize(coop_battle([
                coop_hero('source', 'hero-a', '0,0'),
                monster_unit('dragon', 'Void Dragon', '1,0', ['hp' => 1]),
                monster_unit('spare', 'Ice Wolf', '8,0'),
            ]));
            $events = [];
            $poisoned = Poison::apply($state, 'dragon', 'source', new SequenceRandom([1]), $events, true);
            expect_same(null, $poisoned['units']['dragon']['hex'], 'poison actually defeats the dragon');
            expect_same(null, UnitConditions::named($poisoned, 'source', 'Dragon Slayer'), 'poison does not grant a reward');
            $tossed = SkyToss::land($state, ['attackerId' => 'source', 'targetId' => 'dragon', 'toHex' => '2,0'], new SequenceRandom([1, 6, 6]), $events);
            expect_same(null, $tossed['units']['dragon']['hex'], 'the fall actually defeats the dragon');
            expect_same(null, UnitConditions::named($tossed, 'source', 'Dragon Slayer'), 'an attributed fall does not grant a reward');
        },
        'forced lava entry and collision defeats do not award dragon slayer' => static function (): void {
            $state = MatchEngine::normalize(coop_battle([
                coop_hero('source', 'hero-a', '0,0'),
                monster_unit('dragon', 'Void Dragon', '1,0', ['hp' => 1]),
                monster_unit('spare', 'Ice Wolf', '8,0'),
            ]));
            $events = [];
            $state['board']['2,0']['terrain'] = 'lava';
            $lava = BloodAndBanners\Rules\Vanilla\TerrainResolver::enter($state, 'dragon', '1,0', '2,0', 'teleport', null, new SequenceRandom([1, 6, 6, 6, 6]), $events)['state'];
            expect_same(null, $lava['units']['dragon']['hex'], 'forced lava entry defeats the dragon');
            expect_same(null, UnitConditions::named($lava, 'source', 'Dragon Slayer'), 'lava grants no reward');
            $state['board']['2,0']['sightBlocker'] = true;
            $collision = BloodAndBanners\Rules\Vanilla\Forced::push($state, 'dragon', [1, 0], 1, 'source', new SequenceRandom([1]), $events);
            expect_same(null, $collision['units']['dragon']['hex'], 'a forced collision defeats the dragon');
            expect_same(null, UnitConditions::named($collision, 'source', 'Dragon Slayer'), 'collision grants no reward');
        },
        'a final dragon defeat awards the bonus before completing the match' => static function (): void {
            $state = coop_battle([coop_hero('slayer', 'hero-a', '0,0', ['power' => 2]), monster_unit('dragon', 'Void Dragon', '1,0', ['hp' => 1, 'defense' => 0])]);
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'slayer', 'targetUnitId' => 'dragon'], [1, 1]);
            expect_same('completed', $result['state']['phase'], 'the last monster defeat wins');
            expect_same(1, UnitConditions::named($result['state'], 'slayer', 'Dragon Slayer')['data']['level'] ?? 0, 'the slayer retains its earned reward');
        },
        'new void dragons enable dragon slayer while old inactive snapshots stay unchanged' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            $state = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => 'Void Dragon', 'hex' => '7,0'], new SequenceRandom([]))['state'];
            expect_true(in_array('Dragon Slayer', array_column($state['units']['v-m1']['abilities'], 'name'), true), 'new dragon cards enable the ability');
            $state['units']['v-m1']['abilities'] = [];
            $state = MatchEngine::normalize($state);
            expect_same([], $state['units']['v-m1']['abilities'], 'saved inactive rosters never gain abilities on load');
        },
    ];
}
