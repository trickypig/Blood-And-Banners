<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\MatchEngine;

function vanilla_draft_tests(): array
{
    return [
        'initiative winner picks first and picks then alternate' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $started = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([5, 17]));
            $state = $started['state'];

            expect_same('draft', $state['phase'], 'a started match enters the draft');
            expect_same('seat-b', $state['draft']['pickingSeatId'], 'the higher roll picks first');
            expect_same('initiativeRolled', $started['events'][0]['kind'], 'initiative is announced');
            expect_same(['seat-a' => [5], 'seat-b' => [17]], $started['events'][0]['payload']['rolls'], 'rolls are recorded per seat');
            expect_same([], $engine->legalActions($state, 'seat-a'), 'the waiting seat has no picks');
            expect_same(
                ['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy'],
                array_column($engine->legalActions($state, 'seat-b'), 'kind'),
                'the picking seat may make any open decision'
            );

            $picked = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Captain'], new SequenceRandom([]));
            expect_same('seat-a', $picked['state']['draft']['pickingSeatId'], 'picks alternate');
            expect_same('Test Captain', $picked['state']['draft']['heroBySeat']['seat-b'], 'the hero is claimed');
            expect_same(
                ['seatId' => 'seat-b', 'decision' => 'pickHero', 'value' => 'Test Captain'],
                $picked['events'][0]['payload'],
                'the pick is announced'
            );
        },

        'heroes and armies can only be claimed once' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([5, 17]))['state'];
            $state = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Captain'], new SequenceRandom([]))['state'];
            expect_game_error('heroTaken', static fn() => $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Captain'], new SequenceRandom([])));
            $state = $engine->apply($state, 'seat-a', 'pickArmy', ['army' => 'Red Company'], new SequenceRandom([]))['state'];
            expect_game_error('armyTaken', static fn() => $engine->apply($state, 'seat-b', 'pickArmy', ['army' => 'Red Company'], new SequenceRandom([])));
        },

        'illegal draft picks are rejected' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([5, 17]))['state'];
            $none = new SequenceRandom([]);
            expect_game_error('notYourPick', static fn() => $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Captain'], $none));
            expect_game_error('unknownHero', static fn() => $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Nobody'], $none));
            expect_game_error('unknownArmy', static fn() => $engine->apply($state, 'seat-b', 'pickArmy', ['army' => 'Nobody'], $none));
            expect_game_error('invalidStart', static fn() => $engine->apply($state, 'seat-b', 'chooseStartPosition', ['start' => 3], $none));
            expect_game_error('invalidSeat', static fn() => $engine->apply($state, 'seat-b', 'chooseFirstPlayer', ['seatId' => 'seat-z'], $none));
            expect_game_error('unsupportedCommand', static fn() => $engine->apply($state, 'seat-b', 'moveUnit', [], $none));

            $state = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Captain'], $none)['state'];
            $state = $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Marshal'], $none)['state'];
            expect_game_error('decisionAlreadyMade', static fn() => $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Warden'], $none));
        },

        'a commander with nothing left to decide is skipped and the draft deploys both armies' => static function (): void {
            $engine = new MatchEngine(test_catalog());
            $none = new SequenceRandom([]);
            $state = $engine->start(['seat-a', 'seat-b'], proving_field_board(), new SequenceRandom([17, 5]))['state'];
            $state = $engine->apply($state, 'seat-a', 'chooseFirstPlayer', ['seatId' => 'seat-b'], $none)['state'];
            $state = $engine->apply($state, 'seat-b', 'pickHero', ['hero' => 'Test Marshal'], $none)['state'];
            $state = $engine->apply($state, 'seat-a', 'chooseStartPosition', ['start' => 1], $none)['state'];
            expect_same(['seat-a' => 1, 'seat-b' => 2], $state['draft']['startBySeat'], 'choosing a start assigns the other one');
            $state = $engine->apply($state, 'seat-b', 'pickArmy', ['army' => 'Blue Company'], $none)['state'];
            $state = $engine->apply($state, 'seat-a', 'pickHero', ['hero' => 'Test Captain'], $none)['state'];
            expect_same('seat-a', $state['draft']['pickingSeatId'], 'seat-b has nothing left, so seat-a picks again');

            $final = $engine->apply($state, 'seat-a', 'pickArmy', ['army' => 'Red Company'], $none);
            $state = $final['state'];
            expect_same('active', $state['phase'], 'the finished draft starts the battle');
            expect_same(['draftPicked', 'armiesPlaced'], array_column($final['events'], 'kind'), 'deployment is announced');
            expect_same(14, count($state['units']), 'both armies take the field');
            expect_same('0,4', $state['units']['s1-hero']['hex'], 'seat-a holds start 1');
            expect_same('1,4', $state['units']['s1-u6']['hex'], 'the last seat-a unit clusters near home');
            expect_same('6,4', $state['units']['s2-hero']['hex'], 'seat-b holds start 2');
            expect_same('Test Marshal', $state['units']['s2-hero']['name'], 'hero stats are copied in');
            expect_same(true, $state['units']['s2-hero']['isHero'], 'the hero is flagged');
            expect_same('Red Mage', $state['units']['s1-u6']['name'], 'army units keep catalog order');
            expect_same(0, $state['units']['s1-u1']['wounds'], 'units start unwounded');
            expect_same(
                ['round' => 1, 'activeSeatId' => 'seat-b', 'activeUnitId' => null, 'activated' => [], 'moved' => false, 'allowance' => 4],
                $state['turn'],
                'the chosen first player opens round one'
            );
        },
    ];
}
