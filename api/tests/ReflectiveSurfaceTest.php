<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Modes\CoopSetup;
use BloodAndBanners\Rules\Vanilla\StepRunner;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

function reflective_surface_tests(): array
{
    $battle = static fn(array $attacker = []): array => coop_battle([
        monster_unit('golem', 'Crystal Golem', '2,0', ['defense' => 0, 'hp' => 20]),
        ability_unit('attacker', 'hero-a', '0,0', [], $attacker + ['power' => 2, 'defense' => 1, 'hp' => 20, 'range' => 4, 'side' => 'heroes', 'isHero' => true]),
        coop_hero('other', 'hero-b', '8,0'),
    ]);
    return [
        'reflective surface is active on newly placed crystal golems' => static function (): void {
            expect_true(CoopSetup::isAbilityPlayable(['name' => 'Reflective Surface', 'type' => 'Essence']), 'reflection must be playable');
            $engine = new MatchEngine(new Catalog());
            $state = $engine->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop', ['voidLordSeatId' => 'void-lord'])['state'];
            $state = $engine->apply($state, 'void-lord', 'placeMonster', ['monster' => 'Crystal Golem', 'hex' => '7,0'], new SequenceRandom([]))['state'];
            expect_same(['Reflective Surface'], array_column($state['units']['v-m1']['abilities'], 'name'), 'the summoned golem has its essence');
            expect_same([], $state['units']['v-m1']['inactiveAbilities'], 'the essence is no longer deferred');
        },
        'reflective surface reflects at sixteen and uses the attacker defense' => static function () use ($battle): void {
            $state = $battle();
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'attacker', 'targetUnitId' => 'golem'], [16, 1, 1, 4]);
            expect_same(0, $result['state']['units']['golem']['wounds'], 'the golem is unharmed');
            expect_same(1, $result['state']['units']['attacker']['wounds'], 'two swords minus the attacker shield');
            $hits = array_values(array_filter($result['events'], static fn(array $e): bool => $e['kind'] === 'attackResolved'));
            expect_same('attacker', $hits[0]['payload']['targetUnitId'], 'the damage recipient is reported');
            expect_same('golem', $hits[0]['payload']['reflectedByUnitId'], 'the reflection source is reported');
        },
        'reflective surface fails at fifteen and uses the golem defense' => static function () use ($battle): void {
            $events = [];
            $state = (new StepRunner(new SequenceRandom([15, 1, 1]), 100))->run($battle(), [StepRunner::strike('attacker', 'golem')], $events);
            expect_same(2, $state['units']['golem']['wounds'], 'a failed save allows normal damage');
            expect_same(0, $state['units']['attacker']['wounds'], 'a failed save does not reflect');
        },
        'fireball targeted damage reflects while its splash stays at the original target' => static function () use ($battle): void {
            $state = $battle();
            $state['units']['near'] = coop_hero('near', 'hero-b', '3,0', ['hp' => 20]);
            $events = [];
            $state = (new StepRunner(new SequenceRandom([20, 1, 1, 1, 1, 4, 1]), 100))->run($state, AbilityRegistry::get('Fireball')->steps($state, 'attacker', ['golem']), $events);
            expect_same(0, $state['units']['golem']['wounds'], 'primary damage reflects');
            expect_same(3, $state['units']['attacker']['wounds'], 'four swords minus its shield');
            expect_same(1, $state['units']['near']['wounds'], 'splash stays beside the golem');
        },
        'fireball splash on a crystal golem gets no reflection save' => static function () use ($battle): void {
            $state = $battle(['defense' => 0]);
            $state['units']['primary'] = monster_unit('primary', 'Ogre', '3,0', ['defense' => 0, 'hp' => 20]);
            $events = [];
            $state = (new StepRunner(new SequenceRandom([6, 6, 6, 6, 1]), 100))->run($state, AbilityRegistry::get('Fireball')->steps($state, 'attacker', ['primary']), $events);
            expect_same(1, $state['units']['golem']['wounds'], 'splash damages the golem normally');
            expect_true(!in_array('saveRolled', array_column($events, 'kind'), true), 'splash does not roll reflection');
        },
        'dragon breath only reflects its primary target and preserves its shared splash roll' => static function () use ($battle): void {
            $state = $battle(['defense' => 0]);
            $state['units']['secondary'] = monster_unit('secondary', 'Crystal Golem', '3,0', ['defense' => 0, 'hp' => 20]);
            $events = [];
            $state = (new StepRunner(new SequenceRandom([1, 1, 1, 1, 1, 1, 16]), 100))->run($state, AbilityRegistry::get('Dragon Breath')->steps($state, 'attacker', ['golem']), $events);
            expect_same(0, $state['units']['golem']['wounds'], 'the selected golem reflects');
            expect_same(6, $state['units']['attacker']['wounds'], 'the primary shared roll reflects');
            expect_same(5, $state['units']['secondary']['wounds'], 'the other golem takes splash with its sword penalty');
            expect_same(1, count(array_filter($events, static fn(array $e): bool => $e['kind'] === 'saveRolled')), 'only the selected target saves');
        },
        'petrifying gaze still applies to the golem without reflection' => static function () use ($battle): void {
            $state = $battle();
            $events = [];
            $state = (new StepRunner(new SequenceRandom([1]), 100))->run($state, AbilityRegistry::get('Petrifying Gaze')->steps($state, 'attacker', ['golem']), $events);
            expect_true(UnitConditions::has($state, 'golem', 'Petrified'), 'the non-damage effect stays on the target');
            expect_same([], UnitConditions::forUnit($state, 'attacker'), 'the attacker is not petrified');
        },
        'stone grip keeps its entrapment on the golem and reflects only damage' => static function () use ($battle): void {
            $state = $battle(['defense' => 0]);
            $events = [];
            $state = (new StepRunner(new SequenceRandom([1, 16, 1, 1]), 100))->run($state, AbilityRegistry::get('Stone Grip')->steps($state, 'attacker', ['golem']), $events);
            expect_true(UnitConditions::has($state, 'golem', 'Entrapped'), 'entrapment stays on the golem');
            expect_same(0, $state['units']['golem']['wounds'], 'damage reflects');
            expect_same(2, $state['units']['attacker']['wounds'], 'the attacker takes the damage');
        },
        'fireball splash survives a lethal reflection onto its caster' => static function () use ($battle): void {
            $state = $battle(['hp' => 1, 'defense' => 0]);
            $state['units']['near'] = monster_unit('near', 'Crystal Golem', '3,0', ['hp' => 20]);
            $events = [];
            $state = (new StepRunner(new SequenceRandom([16, 1, 1, 1, 1, 1]), 100))->run($state, AbilityRegistry::get('Fireball')->steps($state, 'attacker', ['golem']), $events);
            expect_same(null, $state['units']['attacker']['hex'], 'reflection kills the caster');
            expect_same(1, $state['units']['near']['wounds'], 'splash still damages a golem without reflecting');
        },
        'pyromancer selected splash survives a lethal reflection' => static function () use ($battle): void {
            $state = $battle(['name' => 'Pyromancer', 'hp' => 1, 'defense' => 0]);
            $state['units']['near'] = coop_hero('near', 'hero-b', '3,0', ['hp' => 20]);
            $events = [];
            $state = (new StepRunner(new SequenceRandom([16, 1, 1, 1, 1]), 100))->run($state, AbilityRegistry::get('Fireball')->steps($state, 'attacker', ['golem', 'near']), $events);
            expect_same(null, $state['units']['attacker']['hex'], 'reflection kills the caster');
            expect_same(2, $state['units']['near']['wounds'], 'already launched splash resolves normally');
        },
        'reflected attacks preserve a pending Moonlight Glare defense choice' => static function () use ($battle): void {
            $state = $battle(['power' => 1]);
            $state['units']['attacker']['abilities'] = [['name' => 'Moonlight Glare']];
            $events = [];
            $runner = new StepRunner(new SequenceRandom([16, 1, 6, 4]), 100);
            $state = $runner->run($state, [StepRunner::strike('attacker', 'golem')], $events);
            expect_same('attacker', $state['pending']['choice']['unitId'], 'the attacker owns the defense choice');
            expect_same('defense', $state['pending']['choice']['side'], 'the pending roll is defense');
            $state = $runner->resolve($state, 'reroll:0', $events);
            expect_same(0, $state['units']['attacker']['wounds'], 'the defense reroll blocks reflected damage');
            expect_same(0, $state['units']['golem']['wounds'], 'resuming never repeats reflection or hits the golem');
        },
        'reflected damage does not bounce between reflective surfaces' => static function () use ($battle): void {
            $state = $battle(['defense' => 0]);
            $state['units']['attacker']['abilities'] = [['name' => 'Reflective Surface']];
            $events = [];
            $state = (new StepRunner(new SequenceRandom([16, 1, 1]), 100))->run($state, [StepRunner::strike('attacker', 'golem')], $events);
            expect_same(2, $state['units']['attacker']['wounds'], 'the original attacker takes reflected damage once');
            expect_same(0, $state['units']['golem']['wounds'], 'no reflection loop');
        },
    ];
}
