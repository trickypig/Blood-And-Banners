<?php
declare(strict_types=1);

function coop_draft_tests(): array
{
    return [
        'hero players snake-draft two heroes and an army each' => static function (): void {
            $state = coop_ready_draft();
            $pickers = [];
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $pickers[] = $state['draft']['pickingSeatId'];
                $state = coop_command($state, $seat, $kind, $payload)['state'];
            }
            expect_same(['hero-a', 'hero-b', 'hero-b', 'hero-a', 'hero-a', 'hero-b'], $pickers, 'picks follow snake order');
            expect_same(['Test Captain', 'Test Ranger'], $state['draft']['heroesBySeat']['hero-a'], 'two heroes are kept per seat');
            expect_same('active', $state['phase'], 'the battle begins after the last pick');
        },

        'draft refusals: duplicates, a third hero, wrong turn, setup orders' => static function (): void {
            $state = coop_command(coop_ready_draft(), 'hero-a', 'pickHero', ['hero' => 'Test Captain'])['state'];
            expect_game_error('heroTaken', static fn() => coop_command($state, 'hero-b', 'pickHero', ['hero' => 'Test Captain']));
            expect_game_error('notYourPick', static fn() => coop_command($state, 'hero-a', 'pickHero', ['hero' => 'Test Marshal']));
            expect_game_error('setupConfirmed', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,1']));
            expect_game_error('unknownHero', static fn() => coop_command($state, 'hero-b', 'pickHero', ['hero' => ['x']]));
            $state = coop_command($state, 'hero-b', 'pickHero', ['hero' => 'Test Marshal'])['state'];
            $state = coop_command($state, 'hero-b', 'pickArmy', ['army' => 'Blue Company'])['state'];
            $state = coop_command($state, 'hero-a', 'pickHero', ['hero' => 'Test Warden'])['state'];
            expect_same('hero-a', $state['draft']['pickingSeatId'], 'hero-a picks again at the snake turn');
            expect_game_error('heroesComplete', static fn() => coop_command($state, 'hero-a', 'pickHero', ['hero' => 'Test Ranger']));
            expect_game_error('armyTaken', static fn() => coop_command($state, 'hero-a', 'pickArmy', ['army' => 'Blue Company']));
        },

        'the finished draft deploys every hero seat around the shared start' => static function (): void {
            $state = coop_ready_draft();
            $result = null;
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $result = coop_command($state, $seat, $kind, $payload);
                $state = $result['state'];
            }
            $heroUnits = array_filter($state['units'], static fn(array $unit): bool => $unit['side'] === 'heroes');
            expect_same(16, count($heroUnits), 'two seats field eight units each');
            expect_true(isset($state['units']['s1-hero1'], $state['units']['s1-hero2'], $state['units']['s1-u6'], $state['units']['s2-hero2']), 'ids follow seat position');
            expect_same(true, $state['units']['s1-hero2']['isHero'], 'both drafted heroes are heroes');
            foreach ($heroUnits as $unit) {
                expect_true((int)explode(',', $unit['hex'])[0] < 7, "{$unit['unitId']} never deploys into a spawn zone");
            }
            expect_same('7,4', $state['units']['v-m1']['hex'], 'monsters keep their places');
            expect_same(['hero-a', 0, 1], [$state['turn']['activeSeatId'], $state['turn']['slot'], $state['turn']['round']], 'the first hero seat opens round one');
            expect_same('armiesPlaced', $result['events'][1]['kind'], 'deployment is announced');
        },
    ];
}
