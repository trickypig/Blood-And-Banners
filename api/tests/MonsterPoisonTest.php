<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

function monster_poison_battle(string $name, array $attackerStats = [], array $targetStats = []): array
{
    return coop_battle([
        monster_unit('monster', $name, '0,0', $attackerStats + ['power' => 0]),
        monster_unit('idle', 'Ice Wolf', '8,0'),
        coop_hero('target', 'hero-a', '1,0', $targetStats + ['defense' => 0, 'hp' => 6]),
    ], 'void-lord');
}

function monster_poison_attack(array $state, array $rolls): array
{
    return coop_command($state, 'void-lord', 'attackUnit', ['unitId' => 'monster', 'targetUnitId' => 'target'], $rolls);
}

function monster_poison_tests(): array
{
    return [
        'poison arrows save on fifteen and can poison without dealing attack damage' => static function (): void {
            foreach ([[14, true], [15, false], [20, false]] as [$roll, $poisoned]) {
                $result = monster_poison_attack(monster_poison_battle('Fungal Archer'), [$roll]);
                expect_same($poisoned, UnitConditions::has($result['state'], 'target', 'Poisoned'), "Poison Arrows save {$roll}");
                expect_same(0, $result['state']['units']['target']['wounds'], 'the arrows cause no immediate poison roll');
                $saves = array_values(array_filter($result['events'], static fn(array $event): bool => $event['kind'] === 'saveRolled'));
                expect_same(1, count($saves), 'one save is rolled even without a wound');
                expect_same(!$poisoned, $saves[0]['payload']['saved'], 'the log reports the correct save outcome');
            }
        },
        'poison arrows trigger after both damaging and fully blocked attacks' => static function (): void {
            foreach ([[6, 1], [4, 0]] as [$defenseDie, $wounds]) {
                $result = monster_poison_attack(monster_poison_battle('Fungal Archer', ['power' => 1], ['defense' => 1]), [1, $defenseDie, 14]);
                expect_same($wounds, $result['state']['units']['target']['wounds'], 'attack damage is resolved before poison');
                expect_true(UnitConditions::has($result['state'], 'target', 'Poisoned'), 'a failed save poisons either way');
            }
        },
        'venom poisons a wounded target automatically without rolling a save' => static function (): void {
            $result = monster_poison_attack(monster_poison_battle('Sand Scorpion', ['power' => 1]), [1]);
            expect_true(UnitConditions::has($result['state'], 'target', 'Poisoned'), 'a wound automatically applies venom');
            expect_same(1, $result['state']['units']['target']['wounds'], 'venom does not roll immediate damage');
            expect_true(!in_array('saveRolled', array_column($result['events'], 'kind'), true), 'a damaging venom attack has no save');
        },
        'venom without attack damage saves on eight' => static function (): void {
            foreach ([[7, true], [8, false], [20, false]] as [$roll, $poisoned]) {
                $result = monster_poison_attack(monster_poison_battle('Sand Scorpion'), [$roll]);
                expect_same($poisoned, UnitConditions::has($result['state'], 'target', 'Poisoned'), "Venom save {$roll}");
                expect_true(in_array('saveRolled', array_column($result['events'], 'kind'), true), 'a nondamaging attack rolls its save');
            }
        },
        'poisonous bite saves on eleven even without attack damage' => static function (): void {
            foreach ([[10, true], [11, false], [20, false]] as [$roll, $poisoned]) {
                $result = monster_poison_attack(monster_poison_battle('Marsh Hydra'), $poisoned ? [$roll, 6] : [$roll]);
                expect_same($poisoned, UnitConditions::has($result['state'], 'target', 'Poisoned'), "Poisonous Bite save {$roll}");
                $poisonRolls = array_filter($result['events'], static fn(array $event): bool => $event['kind'] === 'poisonRolled');
                expect_same($poisoned ? 1 : 0, count($poisonRolls), 'only a newly poisoned victim rolls immediately');
            }
        },
        'poisonous bite rolls immediate damage without defense after resolving the attack' => static function (): void {
            $result = monster_poison_attack(
                monster_poison_battle('Marsh Hydra', ['power' => 1], ['defense' => 4]),
                [1, 6, 6, 6, 6, 10, 1]
            );
            expect_same(2, $result['state']['units']['target']['wounds'], 'one attack wound plus one poison wound ignores the four defense dice');
            $kinds = array_values(array_filter(array_column($result['events'], 'kind'), static fn(string $kind): bool => in_array($kind, ['attackResolved', 'saveRolled', 'conditionApplied', 'poisonRolled'], true)));
            expect_same(['attackResolved', 'saveRolled', 'conditionApplied', 'poisonRolled'], $kinds, 'the event log preserves attack, save, poison and damage order');
        },
        'poisonous bite does not repeat immediate damage or stack an existing poison condition' => static function (): void {
            $state = monster_poison_battle('Marsh Hydra');
            $events = [];
            $state = UnitConditions::apply($state, 'Poisoned', 'target', null, ['kind' => 'untilCleared'], $events);
            $result = monster_poison_attack($state, [10]);
            expect_true(UnitConditions::has($result['state'], 'target', 'Poisoned'), 'the target stays poisoned');
            expect_same(1, count(UnitConditions::forUnit($result['state'], 'target')), 'poison does not stack');
            expect_true(!in_array('poisonRolled', array_column($result['events'], 'kind'), true), 'already poisoned targets get no immediate roll');
        },
        'the immediate bite roll uses normal poison recovery and pit restrictions' => static function (): void {
            foreach ([['grass', false], ['poison-pits', true]] as [$terrain, $poisoned]) {
                $state = monster_poison_battle('Marsh Hydra');
                $state['board']['1,0']['terrain'] = $terrain;
                $result = monster_poison_attack($state, [10, 4]);
                expect_same($poisoned, UnitConditions::has($result['state'], 'target', 'Poisoned'), 'a shield clears poison only away from pits');
                expect_same(0, $result['state']['units']['target']['wounds'], 'a poison shield causes no wound');
            }
        },
        'immediate bite poison can defeat the final hero and end the match' => static function (): void {
            $result = monster_poison_attack(monster_poison_battle('Marsh Hydra', [], ['hp' => 1]), [10, 1]);
            expect_same('completed', $result['state']['phase'], 'poison can finish the match');
            expect_same('void', $result['state']['result']['winningSide'], 'the void lord wins');
            expect_same(null, $result['state']['units']['target']['hex'], 'the poisoned hero is defeated');
            expect_same([], UnitConditions::forUnit($result['state'], 'target'), 'defeat clears the poison condition');
        },
        'lethal attacks do not roll poison saves or apply poison to defeated units' => static function (): void {
            foreach (['Fungal Archer', 'Sand Scorpion', 'Marsh Hydra'] as $name) {
                $result = monster_poison_attack(monster_poison_battle($name, ['power' => 1], ['hp' => 1]), [1]);
                expect_same('completed', $result['state']['phase'], 'the attack already ends the match');
                expect_same([], UnitConditions::forUnit($result['state'], 'target'), 'a defeated target receives no poison');
                expect_true(!in_array('saveRolled', array_column($result['events'], 'kind'), true), 'no save is rolled after defeat');
            }
        },
        'evasion avoids each poison attack and its poison save' => static function (): void {
            foreach (['Fungal Archer', 'Sand Scorpion', 'Marsh Hydra'] as $name) {
                $state = monster_poison_battle($name, [], ['abilities' => [['name' => 'Evasion', 'type' => 'Essence', 'description' => '']]]);
                $result = monster_poison_attack($state, [11]);
                expect_true(!UnitConditions::has($result['state'], 'target', 'Poisoned'), 'an evaded attack never poisons');
                expect_true(!in_array('saveRolled', array_column($result['events'], 'kind'), true), 'no poison save is rolled after evasion');
            }
        },
        'venom waits for a saved moonlight glare choice and uses the rerolled damage' => static function (): void {
            $state = monster_poison_battle('Sand Scorpion', ['power' => 1], [
                'defense' => 1,
                'abilities' => [['name' => 'Moonlight Glare', 'type' => 'Essence', 'description' => '']],
            ]);
            $attacked = monster_poison_attack($state, [1, 6]);
            expect_true($attacked['state']['pending'] !== null, 'the defender chooses a reroll before poison resolves');
            expect_true(!UnitConditions::has($attacked['state'], 'target', 'Poisoned'), 'poison waits for the choice');
            $saved = json_decode(json_encode($attacked['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $result = coop_command($saved, 'hero-a', 'resolveChoice', [
                'choiceId' => $saved['pending']['choice']['choiceId'], 'option' => 'reroll:0',
            ], [4, 8]);
            expect_same(0, $result['state']['units']['target']['wounds'], 'the rerolled shield blocks the wound');
            expect_true(!UnitConditions::has($result['state'], 'target', 'Poisoned'), 'blocking the wound permits the successful eight-plus venom save');
            expect_true(in_array('saveRolled', array_column($result['events'], 'kind'), true), 'venom uses final attack damage after the reroll');
        },
        'attack poison persists through save and load and rolls on the victim owning turn only' => static function (): void {
            $attacked = monster_poison_attack(monster_poison_battle('Fungal Archer'), [14]);
            $saved = json_decode(json_encode($attacked['state'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $passed = coop_command($saved, 'void-lord', 'endTurn');
            expect_true(!in_array('poisonRolled', array_column($passed['events'], 'kind'), true), 'ending the attacker turn does not roll victim poison');
            expect_same('hero-a', $passed['state']['turn']['activeSeatId'], 'the victim gets its turn');
            $ended = coop_command($passed['state'], 'hero-a', 'endTurn', [], [1]);
            expect_same(1, $ended['state']['units']['target']['wounds'], 'the victim rolls poison at its own turn end');
            expect_true(UnitConditions::has($ended['state'], 'target', 'Poisoned'), 'a sword leaves the poison active');
            $condition = UnitConditions::forUnit($ended['state'], 'target')[0];
            expect_same('monster', $condition['sourceUnitId'], 'poison retains its attacking source');
        },
        'newly placed poison monsters carry their supported essence and keep other abilities deferred' => static function (): void {
            $engine = new MatchEngine(new Catalog());
            foreach ([['Fungal Archer', 'Poison Arrows'], ['Sand Scorpion', 'Venom'], ['Marsh Hydra', 'Poisonous Bite']] as [$name, $ability]) {
                $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
                $placed = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => $name, 'hex' => '7,0'], new SequenceRandom([]))['state']['units']['v-m1'];
                expect_true(in_array($ability, array_column($placed['abilities'], 'name'), true), "{$name} can use {$ability}");
                expect_true(!in_array($ability, array_column($placed['inactiveAbilities'], 'name'), true), 'supported poison is no longer deferred');
            }
        },
    ];
}
