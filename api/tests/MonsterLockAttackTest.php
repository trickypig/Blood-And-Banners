<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Conditions\Condition;
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Movement;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\UnitConditions;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\Wounds;

function monster_lock_battle(string $ability, array $target = []): array
{
    return coop_battle([
        monster_unit('monster', $ability === 'Stone Grip' ? 'Stonekin Boulderfist' : 'Basilisk', '0,0'),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('target', 'hero-a', '1,0', $target + ['defense' => 0, 'hp' => 100]),
        coop_hero('other', 'hero-b', '7,0', ['hp' => 100]),
    ], 'void-lord');
}

function monster_lock_attack(array $state, string $ability, array $rolls = [], array $targets = ['target']): array
{
    return coop_command($state, 'void-lord', 'specialAttack', ['unitId' => 'monster', 'ability' => $ability, 'targetUnitIds' => $targets], $rolls);
}

function monster_lock_events(array $result, string $kind): array
{
    return array_values(array_filter($result['events'], static fn(array $event): bool => $event['kind'] === $kind));
}

function monster_lock_attack_tests(): array
{
    return [
        'petrifying gaze saves on thirteen and never rolls attack or defense dice' => static function (): void {
            foreach ([1, 12, 13, 20] as $save) {
                $result = monster_lock_attack(monster_lock_battle('Petrifying Gaze', ['defense' => 5]), 'Petrifying Gaze', [$save]);
                expect_same($save < 13, UnitConditions::has($result['state'], 'target', 'Petrified'), 'gaze uses the thirteen save boundary');
                expect_same(0, $result['state']['units']['target']['wounds'], 'gaze causes no direct damage');
                expect_same([], monster_lock_events($result, 'attackResolved'), 'gaze rolls no attack dice');
                expect_same($save >= 13, monster_lock_events($result, 'saveRolled')[0]['payload']['saved'], 'gaze logs the save result');
            }
        },
        'stone grip saves on twelve and failed saves resolve a defended power two strike' => static function (): void {
            foreach ([1, 11, 12, 20] as $save) {
                $result = monster_lock_attack(monster_lock_battle('Stone Grip', ['defense' => 1]), 'Stone Grip', [$save, 1, 1, 4]);
                expect_same($save < 12, UnitConditions::has($result['state'], 'target', 'Entrapped'), 'grip uses the twelve save boundary');
                expect_same($save < 12 ? 1 : 0, $result['state']['units']['target']['wounds'], 'one shield blocks one of the two grip hits');
                expect_same($save >= 12, monster_lock_events($result, 'saveRolled')[0]['payload']['saved'], 'grip logs the save result');
            }
        },
        'a blocked or missed stone grip still entraps after a failed save and preserves defense' => static function (): void {
            foreach ([[11, 1, 1, 4, 4], [11, 6, 6, 6, 6]] as $rolls) {
                $result = monster_lock_attack(monster_lock_battle('Stone Grip', ['defense' => 2]), 'Stone Grip', $rolls);
                expect_same(0, $result['state']['units']['target']['wounds'], 'all grip damage is blocked or misses');
                expect_true(UnitConditions::has($result['state'], 'target', 'Entrapped'), 'failed save entraps regardless of damage');
                expect_same(2, Stats::effective($result['state'], 'target', 'defense'), 'entrapped units retain full defense');
            }
        },
        'lock attacks allow evasion before saving and grip never rolls evasion twice' => static function (): void {
            foreach (['Petrifying Gaze', 'Stone Grip'] as $ability) {
                $state = monster_lock_battle($ability, ['abilities' => [['name' => 'Evasion']]]);
                $evaded = monster_lock_attack($state, $ability, [11]);
                expect_same([], UnitConditions::forUnit($evaded['state'], 'target'), 'successful evasion avoids the condition');
                expect_same([], monster_lock_events($evaded, 'saveRolled'), 'successful evasion prevents the saving throw');
                $failed = monster_lock_attack($state, $ability, $ability === 'Stone Grip' ? [10, 1, 1, 1] : [10, 1]);
                expect_same(1, count(monster_lock_events($failed, 'evaded')), 'evasion is checked exactly once');
                expect_same($ability === 'Stone Grip' ? 2 : 0, $failed['state']['units']['target']['wounds'], 'failed evasion permits the save failure effect');
                expect_true(UnitConditions::has($failed['state'], 'target', $ability === 'Stone Grip' ? 'Entrapped' : 'Petrified'), 'failed evasion permits the condition');
            }
        },
        'lock attacks target one visible living enemy within their own range' => static function (): void {
            foreach ([['Petrifying Gaze', 3], ['Stone Grip', 1]] as [$ability, $range]) {
                $state = monster_lock_battle($ability);
                $state['units']['target']['hex'] = $range . ',0';
                $handler = AbilityRegistry::get($ability);
                expect_true($handler !== null, 'the lock attack is registered');
                expect_same(['target'], $handler->targets($state, 'monster'), 'the range boundary is legal');
                $state['units']['target']['hex'] = ($range + 1) . ',0';
                expect_game_error('invalidAbilityTarget', static fn() => monster_lock_attack($state, $ability));
                expect_game_error('invalidAbilityTarget', static fn() => monster_lock_attack($state, $ability, [], ['idle']));
                $state['units']['target']['hex'] = null;
                expect_game_error('invalidAbilityTarget', static fn() => monster_lock_attack($state, $ability));
                $state['units']['target']['hex'] = '1,0';
                expect_game_error('invalidAbilityTarget', static fn() => monster_lock_attack($state, $ability, [], ['target', 'other']));
                $state['units']['target']['hex'] = '2,0';
                $state['board']['1,0']['sightBlocker'] = true;
                expect_game_error('invalidAbilityTarget', static fn() => monster_lock_attack($state, $ability));
            }
        },
        'petrification forces defense to zero through buffs and explicit base overrides' => static function (): void {
            $state = monster_lock_battle('Petrifying Gaze', ['defense' => 4, 'abilities' => [['name' => 'Stoneskin']]]);
            $events = [];
            $state = Effects::add($state, 'Shield Wall', 'target', 'target', 'defense', 3, 'hero-a', Effects::UNTIL_NEXT_TURN, $events);
            $state = monster_lock_attack($state, 'Petrifying Gaze', [1])['state'];
            expect_same(0, Stats::effective($state, 'target', 'defense', ['attackerId' => 'idle', 'defenderId' => 'target']), 'all defense bonuses remain suppressed');
            expect_same(0, Stats::effective($state, 'target', 'defense', [], 12), 'even an explicit defense base stays zero');
            expect_same(2, Stats::effective($state, 'target', 'power'), 'petrification does not replace normal stats with penalties');
        },
        'both lock conditions prohibit manual and offered movement attacks and disengagement' => static function (): void {
            foreach ([['Petrifying Gaze', 'Petrified'], ['Stone Grip', 'Entrapped']] as [$ability, $name]) {
                $state = monster_lock_battle($ability, ['abilities' => [['name' => 'Bolt of Annihilation', 'type' => 'Attack']]]);
                $state = monster_lock_attack($state, $ability, $ability === 'Stone Grip' ? [1, 6, 6] : [1])['state'];
                expect_true(UnitConditions::any($state, 'target', static fn(Condition $condition): bool => $condition->blocksMovement()), 'the condition blocks voluntary movement');
                expect_true(UnitConditions::any($state, 'target', static fn(Condition $condition): bool => $condition->blocksAttacks()), 'the condition blocks both attack kinds');
                expect_same([], Movement::disengagedFrom($state, 'monster', '2,0'), 'the victim cannot make a disengagement attack');
                $state['turn'] = Turn::fresh($state, 'hero-a', 2, 0);
                expect_game_error('unitImmobilized', static fn() => coop_command($state, 'hero-a', 'moveUnit', ['unitId' => 'target', 'to' => '2,0']));
                expect_game_error('unitCannotAttack', static fn() => coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'target', 'targetUnitId' => 'monster']));
                expect_game_error('unitCannotAttack', static fn() => coop_command($state, 'hero-a', 'specialAttack', ['unitId' => 'target', 'ability' => 'Bolt of Annihilation', 'targetUnitIds' => ['monster']]));
                $actions = array_values(array_filter((new BattleRules(100))->legalActions($state, 'hero-a'), static fn(array $action): bool => ($action['unitId'] ?? '') === 'target'));
                expect_same([], array_values(array_filter($actions, static fn(array $action): bool => in_array($action['kind'], ['moveUnit', 'attackUnit', 'specialAttack'], true))), 'neither movement nor attack actions are offered');
            }
        },
        'petrification expires at the caster owning seats next start even after caster defeat and reload' => static function (): void {
            $state = monster_lock_attack(monster_lock_battle('Petrifying Gaze'), 'Petrifying Gaze', [1])['state'];
            expect_same(['kind' => 'turnStart', 'seatId' => 'void-lord'], UnitConditions::named($state, 'target', 'Petrified')['expires'], 'gaze is bound to the caster owner');
            $events = [];
            $state = Wounds::apply($state, 'monster', 100, $events);
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $state = coop_command($state, 'void-lord', 'endTurn')['state'];
            expect_same('hero-b', $state['turn']['activeSeatId'], 'the intervening hero now acts');
            expect_true(UnitConditions::has($state, 'target', 'Petrified'), 'the intervening hero turn does not end gaze');
            $state = coop_command($state, 'hero-b', 'endTurn')['state'];
            expect_same('void-lord', $state['turn']['activeSeatId'], 'the caster seat returns before the victim acts');
            expect_true(!UnitConditions::has($state, 'target', 'Petrified'), 'gaze ends at that seat start even if the caster died');
        },
        'entrapment lasts through the victims next owning turn and ends only at its end' => static function (): void {
            $state = monster_lock_attack(monster_lock_battle('Stone Grip'), 'Stone Grip', [1, 6, 6])['state'];
            expect_same(['kind' => 'turnEnd', 'seatId' => 'hero-a', 'afterTurnNumber' => 1], UnitConditions::named($state, 'target', 'Entrapped')['expires'], 'grip is bound to a future turn of the victim owner');
            $state = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            foreach (['void-lord', 'hero-b', 'void-lord'] as $seat) {
                $state = coop_command($state, $seat, 'endTurn')['state'];
                expect_true(UnitConditions::has($state, 'target', 'Entrapped'), 'intervening seats leave entrapment in place');
            }
            expect_same('hero-a', $state['turn']['activeSeatId'], 'the victim now acts');
            $state = coop_command($state, 'hero-a', 'endTurn')['state'];
            expect_true(!UnitConditions::has($state, 'target', 'Entrapped'), 'entrapment ends with the victim turn');
        },
        'newly placed basilisks and boulderfists expose the verified lock attacks' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            foreach ([['Basilisk', 'Petrifying Gaze'], ['Stonekin Boulderfist', 'Stone Grip']] as [$monster, $ability]) {
                $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $state = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $monster, 'hex' => '7,0'], new SequenceRandom([]))['state'];
                expect_true(in_array($ability, array_column($state['units']['v-m1']['abilities'], 'name'), true), 'newly placed units enable the verified attack');
                expect_true(!in_array($ability, array_column($state['units']['v-m1']['inactiveAbilities'], 'name'), true), 'the lock attack is no longer deferred');
            }
        },
    ];
}
