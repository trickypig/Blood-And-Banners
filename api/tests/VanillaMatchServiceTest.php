<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

final class ScriptedRandom implements RandomSource
{
    private int $d20 = 0;

    public function roll(int $sides): int
    {
        if ($sides === 20) {
            return [20, 10][$this->d20++ % 2];
        }
        return 1;
    }
}

function vanilla_service_match(string $scenario = 'Proving Field', string $mode = 'vanillaPvp'): array
{
    [$db, $auth] = test_services();
    $games = new GameService($db, null, new ScriptedRandom(), new MatchEngine(test_catalog()));
    $host = $auth->createGuest('vanilla-host-' . bin2hex(random_bytes(4)));
    $rival = $auth->createGuest('vanilla-rival-' . bin2hex(random_bytes(4)));
    $created = $games->createGame($host['identityId'], $mode, $scenario);
    $joined = $games->joinGame($created['gameId'], $rival['identityId']);
    $games->setReady($created['gameId'], $host['identityId'], $created['seatToken'], true);
    $games->setReady($created['gameId'], $rival['identityId'], $joined['seatToken'], true);
    return [
        'db' => $db,
        'games' => $games,
        'gameId' => $created['gameId'],
        'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']],
        'rival' => ['identity' => $rival['identityId'], 'token' => $joined['seatToken'], 'seat' => $joined['viewer']['seatId']],
    ];
}

function vanilla_submit(array $match, string $who, string $kind, array $payload, ?string $commandId = null): array
{
    $current = $match['games']->getGame($match['gameId'], $match[$who]['identity']);
    return $match['games']->submitCommand($match['gameId'], $match[$who]['identity'], $match[$who]['token'], [
        'commandId' => $commandId ?? bin2hex(random_bytes(8)),
        'expectedVersion' => $current['version'],
        'kind' => $kind,
        'payload' => $payload,
    ]);
}

function vanilla_draft_picks(array $match): array
{
    return [
        ['host', 'chooseFirstPlayer', ['seatId' => $match['host']['seat']]],
        ['rival', 'chooseStartPosition', ['start' => 2]],
        ['rival', 'pickHero', ['hero' => 'Test Marshal']],
        ['host', 'pickHero', ['hero' => 'Test Captain']],
        ['host', 'pickArmy', ['army' => 'Red Company']],
        ['rival', 'pickArmy', ['army' => 'Blue Company']],
    ];
}

function vanilla_match_service_tests(): array
{
    return [
        'starting a vanilla duel rolls initiative and opens the draft for the winner' => static function (): void {
            $match = vanilla_service_match();
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            expect_same('draft', $started['lifecycle'], 'the match enters the draft');
            expect_same($match['host']['seat'], $started['draft']['pickingSeatId'], 'the host rolled 20 and picks first');
            expect_same([20], $started['draft']['initiativeRolls'][$match['host']['seat']], 'rolls are projected per seat');
            expect_same(
                [$match['host']['seat'] => null, $match['rival']['seat'] => null],
                $started['draft']['heroBySeat'],
                'undecided maps always carry both seats'
            );
            expect_same(['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy'], array_column($started['legalActions'], 'kind'), 'the picker sees every open decision');
            expect_same(3, count($started['draft']['availableHeroes']), 'the catalog heroes are offered');
            expect_same(null, $started['draft']['availableHeroes'][0]['takenBySeatId'], 'no hero is taken yet');
            expect_same([], $match['games']->getGame($match['gameId'], $match['rival']['identity'])['legalActions'], 'the rival waits');
            $events = $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)['events'];
            expect_same(['gameStarted', 'initiativeRolled'], array_slice(array_column($events, 'kind'), -2), 'start and initiative are logged');
        },

        'only vanilla duels on a large enough map can start' => static function (): void {
            $coop = vanilla_service_match('Portal Watch', 'coop');
            expect_game_error('modeNotPlayable', static fn() => $coop['games']->startGame($coop['gameId'], $coop['host']['identity'], $coop['host']['token']));
            expect_same('lobby', $coop['games']->getGame($coop['gameId'], $coop['host']['identity'])['lifecycle'], 'a refused start leaves the lobby intact');

            $small = vanilla_service_match('Training Grounds');
            expect_game_error('scenarioTooSmall', static fn() => $small['games']->startGame($small['gameId'], $small['host']['identity'], $small['host']['token']));
        },

        'draft commands are validated versioned and idempotent' => static function (): void {
            $match = vanilla_service_match();
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $first = vanilla_submit($match, 'host', 'pickHero', ['hero' => 'Test Captain'], 'pick-once');
            expect_same($started['version'] + 1, $first['version'], 'a legal pick advances the version');
            expect_same('Test Captain', $first['draft']['heroBySeat'][$match['host']['seat']], 'the pick is projected');

            $replayed = $match['games']->submitCommand($match['gameId'], $match['host']['identity'], $match['host']['token'], [
                'commandId' => 'pick-once', 'expectedVersion' => $started['version'], 'kind' => 'pickHero', 'payload' => ['hero' => 'Test Captain'],
            ]);
            expect_same($first['version'], $replayed['version'], 'a retried command returns the cached projection');

            try {
                vanilla_submit($match, 'rival', 'pickHero', ['hero' => 'Test Captain']);
                throw new RuntimeException('a taken hero was accepted');
            } catch (\BloodAndBanners\Game\GameServiceException $error) {
                expect_same('heroTaken', $error->errorCode, 'taken heroes are refused');
                expect_same($first['version'], $error->currentVersion, 'refusals report the current version');
            }
            expect_same($first['version'], $match['games']->getGame($match['gameId'], $match['host']['identity'])['version'], 'a refusal changes nothing');
        },

        'a drafted match projects units turn state and battle actions' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                $projection = vanilla_submit($match, $who, $kind, $payload);
            }
            $projection = $match['games']->getGame($match['gameId'], $match['host']['identity']);

            expect_same('active', $projection['lifecycle'], 'the finished draft starts the battle');
            expect_same(null, $projection['draft'], 'the draft block disappears after the draft');
            expect_same(14, count($projection['units']), 'both armies are projected');
            expect_same('s1-hero', $projection['units'][0]['unitId'], 'the first seat hero leads the unit list');
            expect_same('s1-u1', $projection['units'][1]['unitId'], 'units follow their hero in numeric order');
            expect_same('s2-hero', $projection['units'][7]['unitId'], 'the second seat hero starts the second army');
            expect_same(
                ['unitId', 'seatId', 'isHero', 'name', 'image', 'move', 'range', 'power', 'defense', 'hp', 'wounds', 'hex', 'activatedThisTurn',
                    'abilities', 'effects', 'effectiveMove', 'effectiveRange', 'effectivePower', 'effectiveDefense'],
                array_keys($projection['units'][0]),
                'unit projections match the shared contract'
            );
            expect_same(['round' => 1, 'activeSeatId' => $match['host']['seat'], 'activationsRemaining' => 4, 'activeUnitId' => null], $projection['turn'], 'the host opens round one');
            $captain = array_values(array_filter($projection['units'], static fn(array $unit): bool => $unit['name'] === 'Test Captain'))[0];
            expect_same(
                [['name' => 'Shield Wall', 'type' => 'Ability', 'description' => 'Grant one adjacent unit +1 Defense until the start of your next turn', 'playable' => true, 'usedThisTurn' => false]],
                $captain['abilities'],
                'units project their abilities'
            );
            expect_same([], $captain['effects'], 'no effects yet');
            expect_same($captain['defense'], $captain['effectiveDefense'], 'effective stats match base with no modifiers');
            foreach ($projection['legalActions'] as $action) {
                expect_true(array_key_exists('ability', $action), 'every legal action carries an ability key');
            }
            expect_same(null, $projection['pendingChoiceSummary'], 'nothing is pending');
            expect_true(in_array('moveUnit', array_column($projection['legalActions'], 'kind'), true), 'the host may move');
            $occupied = array_values(array_filter($projection['board']['hexes'], static fn(array $hex): bool => $hex['q'] === 0 && $hex['r'] === 4));
            expect_same('s1-hero', $occupied[0]['occupantId'], 'the board shows live occupants');

            $link = $match['games']->createSpectatorLink($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $spectated = $match['games']->getSpectatorGame($link['token']);
            expect_same([], $spectated['legalActions'], 'spectators cannot act');
            expect_same(14, count($spectated['units']), 'spectators still see the armies');
        },
    ];
}
