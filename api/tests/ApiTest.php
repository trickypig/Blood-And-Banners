<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\Request;

function api_tests(): array
{
    return [
        'compatibility endpoint publishes API and minimum client versions' => static function (): void {
            $app = test_application();
            $response = $app->handle(new Request('GET', '/api/v1/compatibility'));

            expect_same(200, $response->status, 'compatibility request should succeed');
            expect_same('1.0', $response->body['apiVersion'], 'API version should be explicit');
            expect_same('1.0.0', $response->body['minimumMauiVersion'], 'minimum MAUI version should be explicit');
        },

        'unsupported MAUI client receives upgrade required' => static function (): void {
            $app = test_application();
            $response = $app->handle(new Request('POST', '/api/v1/auth/guest', [
                'x-client-platform' => 'maui',
                'x-client-version' => '0.9.0',
            ], ['deviceId' => 'old-device']));

            expect_same(426, $response->status, 'obsolete MAUI clients should be rejected');
            expect_same('upgradeRequired', $response->body['error']['code'], 'upgrade error code should be stable');
        },

        'guest can create and poll a server authoritative game' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'api-device']));
            expect_same(201, $guest->status, 'guest creation should return 201');

            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'coop',
                'scenarioName' => 'Void Reaches',
            ]));
            expect_same(201, $created->status, 'game creation should return 201');
            expect_true(array_key_exists('game', $created->body), 'game creation should return a seat session envelope');
            expect_true(!array_key_exists('seatToken', $created->body['game']), 'public game projection must exclude seat credentials');

            $gameId = $created->body['game']['gameId'];
            $seatAuthorization = $authorization + ['x-seat-token' => $created->body['seatToken']];
            $state = $app->handle(new Request('GET', "/api/v1/games/{$gameId}", $seatAuthorization));
            expect_same(200, $state->status, 'state request should succeed');
            expect_same(1, $state->body['version'], 'new game should start at version one');

            $command = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/commands", $seatAuthorization, [
                'commandId' => '018f2ec5-bf4c-7000-8000-000000000031',
                'expectedVersion' => 1,
                'kind' => 'setReady',
                'payload' => ['ready' => true],
            ]));
            expect_same(200, $command->status, 'legal command should succeed');
            expect_same(2, $command->body['version'], 'legal command should advance the version');

            $events = $app->handle(new Request('GET', "/api/v1/games/{$gameId}/events", $seatAuthorization, query: ['after' => '0']));
            expect_same(200, $events->status, 'event polling should succeed');
            expect_same('readinessChanged', $events->body['events'][0]['kind'], 'accepted command should emit an event');
            expect_same(1, $events->body['lastSequence'], 'event cursor should advance');
        },

        'stale API command returns structured recovery guidance' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'stale-device']));
            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'battleRoyale',
                'scenarioName' => 'Last Banner',
            ]));
            $gameId = $created->body['game']['gameId'];
            $seatAuthorization = $authorization + ['x-seat-token' => $created->body['seatToken']];
            $body = [
                'commandId' => '018f2ec5-bf4c-7000-8000-000000000032',
                'expectedVersion' => 0,
                'kind' => 'setReady',
                'payload' => ['ready' => true],
            ];

            $response = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/commands", $seatAuthorization, $body));

            expect_same(409, $response->status, 'stale command should return conflict');
            expect_same('versionConflict', $response->body['error']['code'], 'error code should be stable');
            expect_same(1, $response->body['error']['currentVersion'], 'error should include current version');
            expect_true(str_contains($response->body['error']['recovery'], 'Refresh'), 'error should tell the client how to recover');
        },

        'player API requires a matching seat credential for commands and reconnect' => static function (): void {
            $app = test_application();
            $host = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'seat-api-host']));
            $authorization = ['authorization' => 'Bearer ' . $host->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'vanillaPvp',
                'scenarioName' => 'Training Grounds',
            ]));
            $gameId = $created->body['game']['gameId'];
            $command = [
                'commandId' => '018f2ec5-bf4c-7000-8000-000000000033',
                'expectedVersion' => 1,
                'kind' => 'setReady',
                'payload' => ['ready' => true],
            ];

            $missing = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/commands", $authorization, $command));
            expect_same(403, $missing->status, 'command without a seat credential should be forbidden');
            expect_same('seatCredentialRequired', $missing->body['error']['code'], 'missing credential error should be stable');

            $seatHeaders = $authorization + ['x-seat-token' => $created->body['seatToken']];
            $accepted = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/commands", $seatHeaders, $command));
            expect_same(200, $accepted->status, 'matching seat credential should authorize a command');

            $reconnected = $app->handle(new Request('GET', "/api/v1/games/{$gameId}", $seatHeaders));
            expect_same(200, $reconnected->status, 'matching seat credential should reconnect to the game');
            expect_same($created->body['game']['viewer']['seatId'], $reconnected->body['viewer']['seatId'], 'reconnect should restore the same seat');
        },

        'player event polling requires the authenticated seat credential' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'events-seat-api']));
            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'coop',
                'scenarioName' => 'Void Reaches',
            ]));
            $gameId = $created->body['game']['gameId'];

            $missing = $app->handle(new Request('GET', "/api/v1/games/{$gameId}/events", $authorization));
            expect_same(403, $missing->status, 'event polling without a seat credential should be forbidden');
            expect_same('seatCredentialRequired', $missing->body['error']['code'], 'event credential error should be stable');

            $accepted = $app->handle(new Request(
                'GET',
                "/api/v1/games/{$gameId}/events",
                $authorization + ['x-seat-token' => $created->body['seatToken']]
            ));
            expect_same(200, $accepted->status, 'matching seat credential should authorize event polling');
        },

        'host spectator link management requires the host seat credential' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'spectator-seat-host']));
            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'battleRoyale',
                'scenarioName' => 'Last Banner',
            ]));
            $path = "/api/v1/games/{$created->body['game']['gameId']}/spectator-links";

            $missing = $app->handle(new Request('POST', $path, $authorization));
            expect_same(403, $missing->status, 'spectator link creation without a seat credential should be forbidden');

            $accepted = $app->handle(new Request(
                'POST',
                $path,
                $authorization + ['x-seat-token' => $created->body['seatToken']]
            ));
            expect_same(201, $accepted->status, 'host seat credential should authorize spectator link creation');
        },

        'host can reassign a seat and the replacement reconnects only with the rotated credential' => static function (): void {
            $app = test_application();
            $host = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'api-reassign-host']));
            $departing = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'api-reassign-old']));
            $replacement = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'api-reassign-new']));
            $hostAuthorization = ['authorization' => 'Bearer ' . $host->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $hostAuthorization, [
                'mode' => 'vanillaPvp',
                'scenarioName' => 'Training Grounds',
            ]));
            $gameId = $created->body['game']['gameId'];
            $joined = $app->handle(new Request(
                'POST',
                "/api/v1/games/{$gameId}/join",
                ['authorization' => 'Bearer ' . $departing->body['accessToken']]
            ));
            $seatId = $joined->body['game']['viewer']['seatId'];

            $reassigned = $app->handle(new Request(
                'POST',
                "/api/v1/games/{$gameId}/seats/{$seatId}/reassign",
                $hostAuthorization + ['x-seat-token' => $created->body['seatToken']],
                ['identityId' => $replacement->body['identityId']]
            ));
            expect_same(200, $reassigned->status, 'host should be able to reassign a seat');
            expect_true($reassigned->body['seatToken'] !== $joined->body['seatToken'], 'API should return a rotated credential');

            $replacementAuthorization = ['authorization' => 'Bearer ' . $replacement->body['accessToken']];
            $oldCredential = $app->handle(new Request(
                'GET',
                "/api/v1/games/{$gameId}",
                $replacementAuthorization + ['x-seat-token' => $joined->body['seatToken']]
            ));
            expect_same(403, $oldCredential->status, 'replacement must not reconnect with the old credential');

            $reconnected = $app->handle(new Request(
                'GET',
                "/api/v1/games/{$gameId}",
                $replacementAuthorization + ['x-seat-token' => $reassigned->body['seatToken']]
            ));
            expect_same(200, $reconnected->status, 'replacement should reconnect with the rotated credential');
            expect_same($seatId, $reconnected->body['viewer']['seatId'], 'reassignment should preserve the seat identity');
        },

        'spectator endpoint is public and mutation endpoint is read only' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'spectator-api-host']));
            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'coop',
                'scenarioName' => 'Void Reaches',
            ]));
            $link = $app->handle(new Request(
                'POST',
                "/api/v1/games/{$created->body['game']['gameId']}/spectator-links",
                $authorization + ['x-seat-token' => $created->body['seatToken']]
            ));

            $view = $app->handle(new Request('GET', "/api/v1/spectate/{$link->body['token']}"));
            $mutation = $app->handle(new Request('POST', "/api/v1/spectate/{$link->body['token']}/commands", body: [
                'commandId' => 'blocked-command',
            ]));

            expect_same(200, $view->status, 'spectator state should be available without account auth');
            expect_same(false, $view->body['viewer']['canCommand'], 'spectator API projection should be read only');
            expect_same(403, $mutation->status, 'spectator mutation should be forbidden');
            expect_same('spectatorReadOnly', $mutation->body['error']['code'], 'read-only error should be explicit');
        },

        'watch endpoint shows any game read only by its id alone' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'watch-api-host']));
            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $created = $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'vanillaPvp',
                'scenarioName' => 'Proving Field',
            ]));
            $gameId = $created->body['game']['gameId'];

            $view = $app->handle(new Request('GET', "/api/v1/watch/{$gameId}"));
            $events = $app->handle(new Request('GET', "/api/v1/watch/{$gameId}/events", query: ['after' => '0']));

            expect_same(200, $view->status, 'watching needs no account or seat credential');
            expect_same('lobby', $view->body['lifecycle'], 'a lobby can be watched');
            expect_same(
                ['role' => 'spectator', 'seatId' => null, 'isHost' => false, 'canCommand' => false],
                $view->body['viewer'],
                'watchers are read-only spectators'
            );
            expect_same([], $view->body['legalActions'], 'watchers receive no actions');
            expect_same(null, $view->body['pendingChoice'], 'watchers receive no actionable choice');
            expect_true(!str_contains(json_encode($view->body, JSON_THROW_ON_ERROR), $created->body['seatToken']), 'no seat token leaks to watchers');
            expect_same(200, $events->status, 'the event log can be watched too');
            expect_true(array_key_exists('lastSequence', $events->body), 'watched events use the player page shape');
        },

        'watching an unknown game is a not-found error' => static function (): void {
            $app = test_application();

            $view = $app->handle(new Request('GET', '/api/v1/watch/no-such-game'));
            $events = $app->handle(new Request('GET', '/api/v1/watch/no-such-game/events'));

            expect_same(404, $view->status, 'unknown games are not found');
            expect_same('gameNotFound', $view->body['error']['code'], 'the error code is stable');
            expect_same(404, $events->status, 'unknown game events are not found');
        },

        'scenario board preview is public and marks starting points' => static function (): void {
            $app = test_application();
            $board = $app->handle(new Request('GET', '/api/v1/scenarios/Four%20Corners/board'));
            $missing = $app->handle(new Request('GET', '/api/v1/scenarios/Nowhere/board'));

            expect_same(200, $board->status, 'built-in scenario boards should be readable without auth');
            expect_same(13, $board->body['columns'], 'preview should carry the board width');
            expect_same(143, count($board->body['hexes']), 'preview should carry every hex');
            $starts = array_values(array_filter($board->body['hexes'], static fn(array $hex): bool => $hex['playerStart'] !== null));
            expect_same(2, count($starts), 'preview should mark both starting points');
            expect_same(null, $board->body['hexes'][0]['occupantId'], 'preview should not place any pieces');
            expect_same(404, $missing->status, 'unknown scenarios should be not found');
            expect_same('scenarioNotFound', $missing->body['error']['code'], 'missing scenario error should be explicit');
        },

        'guest conversion route is gone and creates nothing' => static function (): void {
            [$app, $db] = test_application_with_db();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'conversion-api-device']));
            $guestAuthorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $app->handle(new Request('POST', '/api/v1/games', $guestAuthorization, [
                'mode' => 'coop',
                'scenarioName' => 'Void Reaches',
            ]));

            $converted = $app->handle(new Request('POST', '/api/v1/auth/convert', $guestAuthorization, [
                'email' => 'api-captain@example.com',
                'password' => 'correct horse battery staple',
                'displayName' => 'API Captain',
                'deviceId' => 'conversion-api-device',
            ]));
            $anonymous = $app->handle(new Request('POST', '/api/v1/auth/convert', body: [
                'email' => 'api-captain@example.com',
                'password' => 'correct horse battery staple',
            ]));
            $login = $app->handle(new Request('POST', '/api/v1/auth/login', body: [
                'email' => 'api-captain@example.com',
                'password' => 'correct horse battery staple',
                'deviceId' => 'other-device',
            ]));

            expect_same(410, $converted->status, 'guest conversion should be gone');
            expect_same('routeRemoved', $converted->body['error']['code'], 'removed route should say so');
            expect_same(410, $anonymous->status, 'the route is gone without a token too');
            expect_same(0, (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no account may be created');
            expect_same(null, $db->query('SELECT converted_user_id FROM guest_identities LIMIT 1')->fetchColumn(), 'guest stays a guest');
            expect_same('invalidCredentials', $login->body['error']['code'], 'no password login exists for the email');
        },
        'two commanders can play a vanilla duel to annihilation over HTTP' => static function (): void {
            [$db, $auth] = test_services();
            $app = new Application($auth, new GameService($db, null, new ScriptedRandom(), new \BloodAndBanners\Rules\Vanilla\MatchEngine(test_catalog())));
            $headers = [];
            foreach (['host', 'rival'] as $who) {
                $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => "duel-{$who}"]));
                $headers[$who] = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            }
            $created = $app->handle(new Request('POST', '/api/v1/games', $headers['host'], ['mode' => 'vanillaPvp', 'scenarioName' => 'Proving Field']));
            $gameId = $created->body['game']['gameId'];
            $headers['host']['x-seat-token'] = $created->body['seatToken'];
            $joined = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/join", $headers['rival']));
            $headers['rival']['x-seat-token'] = $joined->body['seatToken'];
            $seats = ['host' => $created->body['game']['viewer']['seatId'], 'rival' => $joined->body['game']['viewer']['seatId']];

            $send = static function (string $who, string $kind, array $payload) use ($app, $headers, $gameId): array {
                $view = $app->handle(new Request('GET', "/api/v1/games/{$gameId}", $headers[$who]))->body;
                $response = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/commands", $headers[$who], [
                    'commandId' => bin2hex(random_bytes(8)), 'expectedVersion' => $view['version'], 'kind' => $kind, 'payload' => $payload,
                ]));
                expect_same(200, $response->status, "{$kind} by {$who} should succeed: " . json_encode($response->body));
                return $response->body;
            };
            $send('host', 'setReady', ['ready' => true]);
            $send('rival', 'setReady', ['ready' => true]);
            $started = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/start", $headers['host']));
            expect_same(200, $started->status, 'the host starts the draft');

            $match = ['host' => ['seat' => $seats['host']]];
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                $state = $send($who, $kind, $payload);
            }
            for ($commands = 0; $commands < 600 && $state['lifecycle'] !== 'completed'; $commands++) {
                $who = $state['turn']['activeSeatId'] === $seats['host'] ? 'host' : 'rival';
                $view = $app->handle(new Request('GET', "/api/v1/games/{$gameId}", $headers[$who]))->body;
                [$kind, $payload] = vanilla_bot_choice($view);
                $state = $send($who, $kind, $payload);
            }

            expect_same('completed', $state['lifecycle'], 'the scripted duel should finish');
            expect_same('annihilation', $state['result']['reason'], 'the duel ends by annihilation');
            expect_true(in_array($state['result']['winningSeatId'], $seats, true), 'a seated commander wins');
            $events = $app->handle(new Request('GET', "/api/v1/games/{$gameId}/events", $headers['host'], query: ['after' => '0']))->body['events'];
            expect_true(in_array('attackResolved', array_column($events, 'kind'), true), 'attacks are logged');
        },
    ];
}

function test_application(): Application
{
    return test_application_with_db()[0];
}

function test_application_with_db(): array
{
    $db = ConnectionFactory::sqliteMemory();
    (new Migrator($db))->migrate();
    $auth = new AuthService($db, 'test-signing-key-with-at-least-32-bytes');
    return [new Application($auth, new GameService($db)), $db];
}

function vanilla_bot_choice(array $view): array
{
    $unitsByHex = [];
    $enemyHexes = [];
    foreach ($view['units'] as $unit) {
        if ($unit['hex'] === null) {
            continue;
        }
        $unitsByHex[$unit['hex']] = $unit['unitId'];
        if ($unit['seatId'] !== $view['viewer']['seatId']) {
            $enemyHexes[] = $unit['hex'];
        }
    }
    foreach ($view['legalActions'] as $action) {
        if ($action['kind'] === 'attackUnit') {
            return ['attackUnit', ['unitId' => $action['unitId'], 'targetUnitId' => $unitsByHex[$action['targetHexes'][0]]]];
        }
    }
    $best = null;
    foreach ($view['legalActions'] as $action) {
        if ($action['kind'] !== 'moveUnit') {
            continue;
        }
        foreach ($action['targetHexes'] as $hex) {
            $distance = min(array_map(static fn(string $enemy): int => \BloodAndBanners\Rules\Vanilla\Hex::distance($hex, $enemy), $enemyHexes));
            if ($best === null || $distance < $best[0]) {
                $best = [$distance, $action['unitId'], $hex];
            }
        }
    }
    return $best === null ? ['endTurn', []] : ['moveUnit', ['unitId' => $best[1], 'to' => $best[2]]];
}
