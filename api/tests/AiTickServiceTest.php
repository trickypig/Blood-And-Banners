<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

/** Commands in one activation: activate-by-move, attack, special attack or ability, item, then end it. */
const AI_TICK_COMMANDS_PER_ACTIVATION = 4;

/**
 * A started 1-hero vs ravager Void Reaches game on the real catalog, the hero's draft finished, and the
 * clock left where the draft ended. $planner replaces the TurnPlanner factory when given.
 */
function ai_tick_match(int &$clock, ?Closure $planner = null): array
{
    $clock = 1000;
    [$db, $auth] = test_services();
    $games = new GameService(
        $db, null, new ScriptedRandom(), new MatchEngine(ai_catalog()),
        static function () use (&$clock): int {
            return $clock;
        },
        $planner
    );
    $host = $auth->createGuest('ai-tick-host-' . bin2hex(random_bytes(4)));
    $created = $games->createGame($host['identityId'], 'coop', 'Void Reaches');
    $match = ['db' => $db, 'auth' => $auth, 'games' => $games, 'gameId' => $created['gameId'],
        'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']]];
    vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'ravager']);
    vanilla_submit($match, 'host', 'setReady', ['ready' => true]);
    $games->startGame($created['gameId'], $host['identityId'], $created['seatToken']);
    $armies = array_column(ai_catalog()->armies(), 'name');
    $heroes = array_column(ai_catalog()->heroes(), 'name');
    vanilla_submit($match, 'host', 'pickHero', ['hero' => $heroes[0]]);
    vanilla_submit($match, 'host', 'pickHero', ['hero' => $heroes[1]]);
    vanilla_submit($match, 'host', 'pickArmy', ['army' => $armies[0]]);
    $match['ai'] = (string)ai_seat_state($match)['voidLordSeatId'];
    expect_same('active', ai_seat_view($match)['lifecycle'], 'the battle has begun');
    return $match;
}

function ai_tick_version(array $match): int
{
    return (int)$match['db']->query("SELECT version FROM games WHERE id = '{$match['gameId']}'")->fetchColumn();
}

/** The seat that owes the next command: the owner of a pending choice, else the active seat. */
function ai_tick_owed(array $match): string
{
    $state = ai_seat_state($match);
    return (string)($state['pending']['choice']['seatId'] ?? $state['turn']['activeSeatId']);
}

/** The hero passes (or answers a choice) until the AI is up; the clock does not move. */
function ai_tick_to_ai_turn(array $match): void
{
    for ($i = 0; $i < 6 && ai_tick_owed($match) !== $match['ai']; $i++) {
        vanilla_submit($match, 'host', 'endTurn', []);
    }
    expect_same($match['ai'], ai_tick_owed($match), 'the AI is up');
}

function ai_tick_poll(array $match): array
{
    return $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0);
}

function ai_tick_tests(): array
{
    return [
        'the ai acts one step per due poll' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            ai_tick_to_ai_turn($match);
            $before = ai_tick_version($match);
            ai_tick_poll($match);
            expect_same($before, ai_tick_version($match), 'no step before the pause is over');
            $clock += 1000;
            ai_tick_poll($match);
            expect_same($before + 1, ai_tick_version($match), 'one step once due');
            ai_tick_poll($match);
            expect_same($before + 1, ai_tick_version($match), 'nothing more at the same clock');
        },
        'the pace sets the pause' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            vanilla_submit($match, 'host', 'setAiPace', ['pace' => 'slow']);
            ai_tick_to_ai_turn($match);
            $before = ai_tick_version($match);
            $clock += 1000;
            ai_tick_poll($match);
            expect_same($before, ai_tick_version($match), 'slow is not due after 1000 ms');
            $clock += 1000;
            ai_tick_poll($match);
            expect_same($before + 1, ai_tick_version($match), 'slow is due after 2000 ms');
            vanilla_submit($match, 'host', 'setAiPace', ['pace' => 'fast']);
            $before = ai_tick_version($match);
            $clock += 300;
            ai_tick_poll($match);
            expect_same($before + 1, ai_tick_version($match), 'fast is due after 300 ms');
        },
        'the ai finishes its turn and hands back' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            ai_tick_to_ai_turn($match);
            $steps = 0;
            $bound = 5 * AI_TICK_COMMANDS_PER_ACTIVATION + 1;
            while (ai_tick_owed($match) === $match['ai'] && $steps <= $bound) {
                $clock += 1000;
                $before = ai_tick_version($match);
                ai_tick_poll($match);
                expect_same($before + 1, ai_tick_version($match), 'each due poll is one step');
                $steps++;
            }
            expect_true(ai_tick_owed($match) !== $match['ai'], "the turn passed back to the hero ({$steps} steps)");
            expect_true($steps <= $bound, "within {$bound} steps ({$steps})");
        },
        'a rejected ai command falls back to ending' => static function (): void {
            $clock = 0;
            $reject = new class {
                public bool $on = false;
                public function next(array $state, string $seatId, int $seed): ?array
                {
                    return ['kind' => 'attackUnit', 'payload' => ['unitId' => 'nope', 'targetUnitId' => 'nobody']];
                }
            };
            $factory = static function (MatchEngine $engine, $personality, int $now, int $deadline, Closure $clockMs) use ($reject) {
                return $reject->on ? $reject : new BloodAndBanners\Ai\TurnPlanner($engine, $personality, $now, $deadline, $clockMs);
            };
            $match = ai_tick_match($clock, $factory);
            ai_tick_to_ai_turn($match);

            // No unit active: the fallback ends the turn.
            $reject->on = true;
            $clock += 1000;
            $before = ai_tick_version($match);
            ai_tick_poll($match);
            expect_same($before + 1, ai_tick_version($match), 'the fallback was applied');
            expect_true(ai_tick_owed($match) !== $match['ai'], 'endTurn passed the turn');

            // A unit active: the fallback ends its activation.
            $reject->on = false;
            ai_tick_to_ai_turn($match);
            $activeUnit = null;
            for ($i = 0; $i < 5 && $activeUnit === null; $i++) {
                $clock += 1000;
                ai_tick_poll($match);
                $activeUnit = ai_seat_state($match)['turn']['activeUnitId'] ?? null;
                if (ai_tick_owed($match) !== $match['ai']) {
                    break;
                }
            }
            expect_true($activeUnit !== null, 'a unit became active');
            $reject->on = true;
            $clock += 1000;
            $before = ai_tick_version($match);
            ai_tick_poll($match);
            expect_same($before + 1, ai_tick_version($match), 'the fallback was applied');
            expect_same(null, ai_seat_state($match)['turn']['activeUnitId'] ?? null, 'endActivation closed the unit');
        },
        'a planner that throws also falls back to ending' => static function (): void {
            $clock = 0;
            $throws = new class {
                public function next(array $state, string $seatId, int $seed): ?array
                {
                    throw new RuntimeException('planner blew up');
                }
            };
            $match = ai_tick_match($clock, static fn() => $throws);
            ai_tick_to_ai_turn($match);
            $clock += 1000;
            $before = ai_tick_version($match);
            $logged = ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai-tick-test.log');
            ai_tick_poll($match);
            ini_set('error_log', (string)$logged);
            expect_same($before + 1, ai_tick_version($match), 'the poll still answered and the turn was ended');
            expect_true(ai_tick_owed($match) !== $match['ai'], 'the turn passed');
        },
        'two polls in one pause step once' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            ai_tick_to_ai_turn($match);
            $before = ai_tick_version($match);
            $clock += 1000;
            ai_tick_poll($match);
            ai_tick_poll($match);
            expect_same($before + 1, ai_tick_version($match), 'one step for two polls');
        },
        'the ai answers its pending choice during a hero turn' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            $state = ai_seat_state($match);
            $unitId = null;
            foreach ($state['units'] as $id => $unit) {
                if (($unit['seatId'] ?? null) === $match['ai'] && $unit['hex'] !== null && empty($unit['fixed'])) {
                    $unitId = (string)$id;
                    break;
                }
            }
            expect_true($unitId !== null, 'the AI has a monster');
            $hex = (string)$state['units'][$unitId]['hex'];
            $state['board'][$hex]['object'] = 'power-potion';
            $state['turn']['activeSeatId'] = $match['host']['seat'];
            $state['turn']['activeUnitId'] = null;
            $state['pending'] = ['choice' => [
                'choiceId' => 'choice-tick', 'seatId' => $match['ai'], 'kind' => 'itemPickup', 'unitId' => $unitId,
                'item' => 'power-potion', 'options' => ['drink', 'keep', 'leave'], 'targetHexes' => [$hex], 'openedAt' => time(),
            ], 'context' => []];
            $match['db']->prepare('UPDATE game_states SET state_json = :json WHERE game_id = :game')
                ->execute(['json' => json_encode($state), 'game' => $match['gameId']]);
            $clock += 1000;
            $before = ai_tick_version($match);
            ai_tick_poll($match);
            expect_same(null, ai_seat_state($match)['pending'] ?? null, 'the choice was answered');
            expect_same($before + 1, ai_tick_version($match), 'exactly one step');
        },
        'a poll that arrives while another is planning does not plan again' => static function (): void {
            $clock = 0;
            $calls = 0;
            $nested = null;
            $stub = new class {
                public ?Closure $during = null;
                public int $calls = 0;
                public function next(array $state, string $seatId, int $seed): ?array
                {
                    $this->calls++;
                    if ($this->during !== null) {
                        ($this->during)();
                    }
                    return ['kind' => 'endTurn', 'payload' => []];
                }
            };
            $match = ai_tick_match($clock, static fn() => $stub);
            ai_tick_to_ai_turn($match);
            $stub->during = static function () use ($match): void {
                // The lease is already taken: this poll is not due and must not reach the planner.
                $match['games']->getWatchedEvents($match['gameId'], 0);
            };
            $clock += 1000;
            $before = ai_tick_version($match);
            ai_tick_poll($match);
            expect_same(1, $stub->calls, 'the planner ran once');
            expect_same($before + 1, ai_tick_version($match), 'one step');
        },
        'a hero turn poll with no choice for the ai writes nothing' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            $stepped = static fn(): mixed => $match['db']->query("SELECT ai_stepped_at FROM games WHERE id = '{$match['gameId']}'")->fetchColumn();
            $seen = $stepped();
            $clock += 5000;
            if (ai_tick_owed($match) !== $match['host']['seat']) {
                vanilla_submit($match, 'host', 'endTurn', []);
                $seen = $stepped();
                $clock += 5000;
            }
            expect_same($match['host']['seat'], ai_tick_owed($match), 'the hero is up');
            ai_tick_poll($match);
            expect_same($seen, $stepped(), 'no lease is taken when the AI owes nothing');
        },
        'the fallback tries another command when the first candidate was the rejected one' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            $state = ai_seat_state($match);
            $unitId = null;
            foreach ($state['units'] as $id => $unit) {
                if (($unit['seatId'] ?? null) === $match['ai'] && $unit['hex'] !== null && empty($unit['fixed'])) {
                    $unitId = (string)$id;
                    break;
                }
            }
            $hex = (string)$state['units'][$unitId]['hex'];
            $state['board'][$hex]['object'] = 'power-potion';
            $state['pending'] = ['choice' => [
                'choiceId' => 'choice-fb', 'seatId' => $match['ai'], 'kind' => 'itemPickup', 'unitId' => $unitId,
                'item' => 'power-potion', 'options' => ['drink', 'keep', 'leave'], 'targetHexes' => [$hex], 'openedAt' => time(),
            ], 'context' => []];
            $fallback = new ReflectionMethod(GameService::class, 'aiFallbackCommand');
            $first = $fallback->invoke($match['games'], $state, $match['ai'], null);
            expect_same('resolveChoice', $first['kind'], 'the choice is answered first');
            $second = $fallback->invoke($match['games'], $state, $match['ai'], $first);
            expect_true($second !== null && $second != $first, 'a different command is offered after a rejection');
        },
        'a draft whose ai horde was never confirmed gets it on the next poll' => static function (): void {
            $clock = 5000;
            $match = ai_seat_match(0, $clock);
            vanilla_submit($match, 'host', 'seatAiVoidLord', ['personality' => 'warden']);
            vanilla_submit($match, 'host', 'setReady', ['ready' => true]);
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $state = ai_seat_state($match);
            $state['units'] = ['v-portal' => $state['units']['v-portal']];
            $state['setup'] = ['spent' => 0, 'nextMonster' => 1, 'confirmed' => false] + $state['setup'];
            $match['db']->prepare('UPDATE game_states SET state_json = :json WHERE game_id = :game')
                ->execute(['json' => json_encode($state), 'game' => $match['gameId']]);
            expect_true(!ai_seat_state($match)['setup']['confirmed'], 'the horde is unconfirmed');
            $before = ai_tick_version($match);
            $match['games']->getWatchedEvents($match['gameId'], 0);
            expect_same($before, ai_tick_version($match), 'the safety net is paced like a step');
            $clock += 1000;
            $match['games']->getWatchedEvents($match['gameId'], 0);
            $after = ai_seat_state($match);
            expect_true($after['setup']['confirmed'], 'the safety net confirmed it');
            expect_true($after['setup']['spent'] >= 1, 'with a real horde');
            expect_same($before + 1, ai_tick_version($match), 'one version');
            $match['games']->getWatchedEvents($match['gameId'], 0);
            expect_same($before + 1, ai_tick_version($match), 'and only once');
        },
        'spectators drive the ai too' => static function (): void {
            $clock = 0;
            $match = ai_tick_match($clock);
            ai_tick_to_ai_turn($match);
            $before = ai_tick_version($match);
            $match['games']->getWatchedEvents($match['gameId'], 0);
            expect_same($before, ai_tick_version($match), 'not due yet');
            $clock += 1000;
            $match['games']->getWatchedEvents($match['gameId'], 0);
            expect_same($before + 1, ai_tick_version($match), 'a watcher poll steps the AI');
        },
    ];
}
