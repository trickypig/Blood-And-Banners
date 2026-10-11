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
                'scenarioName' => 'Portal Watch',
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
                'scenarioName' => 'Portal Watch',
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
                'scenarioName' => 'Portal Watch',
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

        'guest conversion carries match history to authenticated account endpoints' => static function (): void {
            $app = test_application();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'conversion-api-device']));
            $guestAuthorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $game = $app->handle(new Request('POST', '/api/v1/games', $guestAuthorization, [
                'mode' => 'coop',
                'scenarioName' => 'Portal Watch',
            ]));

            $converted = $app->handle(new Request('POST', '/api/v1/auth/convert', $guestAuthorization, [
                'email' => 'api-captain@example.com',
                'password' => 'correct horse battery staple',
                'displayName' => 'API Captain',
                'deviceId' => 'conversion-api-device',
            ]));
            $accountAuthorization = ['authorization' => 'Bearer ' . $converted->body['accessToken']];
            $history = $app->handle(new Request('GET', '/api/v1/account/matches', $accountAuthorization));
            $login = $app->handle(new Request('POST', '/api/v1/auth/login', body: [
                'email' => 'api-captain@example.com',
                'password' => 'correct horse battery staple',
                'deviceId' => 'other-device',
            ]));

            expect_same(200, $converted->status, 'guest conversion should succeed');
            expect_same($game->body['game']['gameId'], $history->body['matches'][0]['gameId'], 'account history should include guest match');
            expect_same(200, $login->status, 'converted account should support login');
        },

        'failed guest conversion rolls back account and membership together' => static function (): void {
            [$app, $db] = test_application_with_db();
            $guest = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'atomic-conversion']));
            $authorization = ['authorization' => 'Bearer ' . $guest->body['accessToken']];
            $app->handle(new Request('POST', '/api/v1/games', $authorization, [
                'mode' => 'coop',
                'scenarioName' => 'Portal Watch',
            ]));
            $db->exec("CREATE TRIGGER reject_identity_transfer BEFORE UPDATE ON game_memberships BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

            $response = $app->handle(new Request('POST', '/api/v1/auth/convert', $authorization, [
                'email' => 'rollback@example.com',
                'password' => 'correct horse battery staple',
                'displayName' => 'Rollback Captain',
                'deviceId' => 'atomic-conversion',
            ]));

            expect_same(500, $response->status, 'forced transfer failure should fail conversion');
            expect_same(0, (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'account insert must roll back');
            expect_same(null, $db->query('SELECT converted_user_id FROM guest_identities LIMIT 1')->fetchColumn(), 'guest must remain convertible');
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
