<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Modes\CoopMode;

/** A legacy save: the old flow where setup came before the draft. */
function coop_legacy_setup(): array
{
    $state = coop_start()['state'];
    $state['phase'] = 'setup';
    $state['draft']['pickingSeatId'] = null;
    return $state;
}

function coop_prep_tests(): array
{
    return [
        'co-op opens in prep with the draft and setup both open' => static function (): void {
            $state = coop_start()['state'];
            $engine = new MatchEngine(test_catalog());
            expect_same('prep', $state['phase'], 'preparation is simultaneous');
            expect_same('hero-a', $state['draft']['pickingSeatId'], 'the initiative winner picks at once');
            expect_same(['pickHero', 'pickArmy'], array_column($engine->legalActions($state, 'hero-a'), 'kind'), 'the first hero may pick');
            expect_same([], $engine->legalActions($state, 'hero-b'), 'the second hero waits for their pick');
            expect_same(['placeMonster'], array_values(array_unique(array_column($engine->legalActions($state, 'void-lord'), 'kind'))), 'the void lord places meanwhile');
        },

        'the draft can finish first and waits for the void lord' => static function (): void {
            $state = coop_command(coop_start()['state'], 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $state = coop_command($state, $seat, $kind, $payload)['state'];
            }
            expect_same(['prep', null], [$state['phase'], $state['draft']['pickingSeatId']], 'the heroes wait in prep');
            expect_same(['v-m1'], array_keys($state['units']), 'no hero is deployed yet');
            $done = coop_command($state, 'void-lord', 'confirmSetup');
            expect_same('active', $done['state']['phase'], 'the confirm starts the battle');
            expect_same(['setupConfirmed', 'armiesPlaced'], array_column($done['events'], 'kind'), 'deployment follows the confirm');
            expect_same(['hero-a', 1], [$done['state']['turn']['activeSeatId'], $done['state']['turn']['round']], 'the first hero opens round one');
            expect_same(17, count($done['state']['units']), 'sixteen hero units join the wolf');
        },

        'the void lord can confirm first and the last pick starts the battle' => static function (): void {
            $state = coop_ready_draft();
            expect_same(['prep', true], [$state['phase'], $state['setup']['confirmed']], 'confirming keeps the draft running');
            $result = null;
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $result = coop_command($state, $seat, $kind, $payload);
                $state = $result['state'];
            }
            expect_same('active', $state['phase'], 'the last pick starts the battle');
            expect_same(['draftPicked', 'armiesPlaced'], array_column($result['events'], 'kind'), 'deployment follows the pick');
        },

        'an over-budget horde cannot be confirmed' => static function (): void {
            $state = coop_command(coop_start()['state'], 'void-lord', 'placeMonster', ['monster' => 'Test Wyrm', 'hex' => '7,4'])['state'];
            $state['setup']['budget'] = 2;
            $engine = new MatchEngine(test_catalog());
            expect_true(!in_array('confirmSetup', array_column($engine->legalActions($state, 'void-lord'), 'kind'), true), 'confirm is not offered');
            expect_game_error('budgetExceeded', static fn() => coop_command($state, 'void-lord', 'confirmSetup'));
            $state = coop_command($state, 'void-lord', 'removeMonster', ['unitId' => 'v-m1'])['state'];
            $state = coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            expect_same(true, coop_command($state, 'void-lord', 'confirmSetup')['state']['setup']['confirmed'], 'back in budget it confirms');
        },

        'after confirming the void lord has no setup orders' => static function (): void {
            $state = coop_ready_draft();
            expect_same([], (new MatchEngine(test_catalog()))->legalActions($state, 'void-lord'), 'nothing is offered');
            expect_game_error('setupConfirmed', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,1']));
            expect_game_error('setupConfirmed', static fn() => coop_command($state, 'void-lord', 'removeMonster', ['unitId' => 'v-m1']));
            expect_game_error('setupConfirmed', static fn() => coop_command($state, 'void-lord', 'confirmSetup'));
        },

        'a legacy setup save still runs setup then draft' => static function (): void {
            $state = coop_command(coop_legacy_setup(), 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            expect_same([], (new MatchEngine(test_catalog()))->legalActions($state, 'hero-a'), 'heroes wait in the old setup');
            $confirmed = coop_command($state, 'void-lord', 'confirmSetup');
            expect_same(['monsters' => 1], $confirmed['events'][0]['payload'], 'the old confirm still announces the count');
            $state = $confirmed['state'];
            expect_same(['draft', 'hero-a'], [$state['phase'], $state['draft']['pickingSeatId']], 'the old confirm opens the draft');
            foreach (coop_draft_picks() as [$seat, $kind, $payload]) {
                $state = coop_command($state, $seat, $kind, $payload)['state'];
            }
            expect_same('active', $state['phase'], 'the old draft still ends in battle');
        },

        'start carries the lobby horde over and sets the final budget' => static function (): void {
            $lobby = CoopMode::lobbySetup(coop_board(), 'void-lord', CoopMode::budget(['basePoints' => 4, 'pointsPerHeroPlayer' => 8], 3));
            expect_same(['setup', 28, []], [$lobby['phase'], $lobby['setup']['budget'], $lobby['initiative']['order']], 'the lobby board has no heroes yet');
            $lobby = coop_command($lobby, 'void-lord', 'placeMonster', ['monster' => 'Test Wyrm', 'hex' => '7,4'])['state'];
            $lobby = coop_command($lobby, 'void-lord', 'placeMonster', ['monster' => 'Test Golem', 'hex' => '7,5'])['state'];
            $state = (new MatchEngine(test_catalog()))->start(
                ['hero-a', 'hero-b', 'void-lord'], coop_board(), new SequenceRandom([15, 5]), 'coop',
                ['voidLordSeatId' => 'void-lord', 'coop' => ['basePoints' => 4, 'pointsPerHeroPlayer' => 8], 'setupState' => $lobby]
            )['state'];
            expect_same(['v-m1', 'v-m2'], array_keys($state['units']), 'placed monsters carry over');
            expect_same([20, 6, 3], [$state['setup']['budget'], $state['setup']['spent'], $state['setup']['nextMonster']], 'spend and ids carry over; budget follows the locked roster');
        },

        'handing over moves every monster to the new void lord' => static function (): void {
            $lobby = CoopMode::lobbySetup(coop_board(), 'old-lord', 12);
            $lobby = coop_command($lobby, 'old-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,4'])['state'];
            $moved = CoopMode::handOver($lobby, 'new-lord');
            expect_same(['new-lord', 'new-lord', ['new-lord']], [$moved['voidLordSeatId'], $moved['units']['v-m1']['seatId'], $moved['sides']['void']['seatIds']], 'the horde changes hands');
            expect_game_error('notVoidLord', static fn() => coop_command($moved, 'old-lord', 'removeMonster', ['unitId' => 'v-m1']));
        },
    ];
}
