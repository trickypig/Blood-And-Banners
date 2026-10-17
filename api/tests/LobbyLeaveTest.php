<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Http\Request;

function lobby_seat_indexes(array $projection): array
{
    return array_column($projection['memberships'], 'seatIndex', 'seatId');
}

function lobby_event_kinds(array $match, string $who): array
{
    return array_column($match['games']->getEvents($match['gameId'], $match[$who]['identity'], $match[$who]['token'], 0)['events'], 'kind');
}

function lobby_leave_tests(): array
{
    return [
        'a player who leaves the lobby frees their seat and the next joiner takes its number' => static function (): void {
            $match = coop_service_match(2);
            $before = $match['games']->getGame($match['gameId'], $match['host']['identity']);

            $match['games']->leaveGame($match['gameId'], $match['hero1']['identity'], $match['hero1']['token']);

            $after = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same($before['version'] + 1, $after['version'], 'leaving bumps the version');
            expect_same([$match['host']['seat'] => 1, $match['hero2']['seat'] => 3], lobby_seat_indexes($after), 'the other seats keep their numbers');
            expect_true(in_array('playerLeft', lobby_event_kinds($match, 'host'), true), 'leaving is logged');
            expect_game_error('membershipRequired', static fn() => $match['games']->reconnect($match['gameId'], $match['hero1']['identity'], $match['hero1']['token']));

            $late = (new AuthService($match['db'], 'test-signing-key-with-at-least-32-bytes'))->createGuest('late-' . bin2hex(random_bytes(4)));
            $joined = $match['games']->joinGame($match['gameId'], $late['identityId']);
            expect_same(2, lobby_seat_indexes($joined)[$joined['viewer']['seatId']], 'the freed number is reused');
            $rejoined = $match['games']->joinGame($match['gameId'], $match['hero1']['identity']);
            expect_same(4, lobby_seat_indexes($rejoined)[$rejoined['viewer']['seatId']], 'a player who left may join again');
        },

        'when the host leaves the lowest remaining seat becomes host' => static function (): void {
            $match = coop_service_match(2);

            $match['games']->leaveGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $heir = $match['games']->getGame($match['gameId'], $match['hero1']['identity']);
            expect_same(true, $heir['viewer']['isHost'], 'the next seat is now host');
            expect_same([true, false], array_column($heir['memberships'], 'isHost'), 'the host flag sits on the lowest seat');
            expect_same(false, $match['games']->getGame($match['gameId'], $match['hero2']['identity'])['viewer']['isHost'], 'later seats are not host');
            $assigned = vanilla_submit($match, 'hero1', 'assignVoidLord', ['seatId' => $match['hero2']['seat']]);
            expect_same('voidLord', array_column($assigned['memberships'], 'role', 'seatId')[$match['hero2']['seat']], 'the new host has host powers');
        },

        'the last player leaving deletes the room' => static function (): void {
            $match = coop_service_match(0);

            $match['games']->leaveGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            expect_game_error('gameNotFound', static fn() => $match['games']->getWatchedGame($match['gameId']));
        },

        'leaving the void lord seat frees the role' => static function (): void {
            $match = coop_service_match(2);
            vanilla_submit($match, 'hero1', 'claimVoidLord', []);

            $match['games']->leaveGame($match['gameId'], $match['hero1']['identity'], $match['hero1']['token']);

            $after = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same(['player', 'player'], array_column($after['memberships'], 'role'), 'nobody is void lord');
            expect_true(in_array('claimVoidLord', array_column($after['legalActions'], 'kind'), true), 'the role can be claimed again');
        },

        'leaving needs the seat credential and is refused once the match starts' => static function (): void {
            $match = vanilla_service_match();
            expect_game_error('seatCredentialInvalid', static fn() => $match['games']->leaveGame($match['gameId'], $match['rival']['identity'], 'wrong'));
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            expect_game_error('illegalLifecycle', static fn() => $match['games']->leaveGame($match['gameId'], $match['rival']['identity'], $match['rival']['token']));
        },

        'the host removes a seat from the lobby' => static function (): void {
            $match = coop_service_match(2);

            $removed = $match['games']->removeSeat($match['gameId'], $match['host']['identity'], $match['host']['token'], $match['hero1']['seat']);

            expect_same([$match['host']['seat'] => 1, $match['hero2']['seat'] => 3], lobby_seat_indexes($removed), 'the seat is gone');
            expect_true(in_array('playerRemoved', lobby_event_kinds($match, 'host'), true), 'removal is logged');
            expect_game_error('membershipRequired', static fn() => $match['games']->reconnect($match['gameId'], $match['hero1']['identity'], $match['hero1']['token']));
        },

        'only the host removes, never their own seat, and only in the lobby' => static function (): void {
            $match = vanilla_service_match();
            $games = $match['games'];
            expect_game_error('hostRequired', static fn() => $games->removeSeat($match['gameId'], $match['rival']['identity'], $match['rival']['token'], $match['host']['seat']));
            expect_game_error('cannotRemoveSelf', static fn() => $games->removeSeat($match['gameId'], $match['host']['identity'], $match['host']['token'], $match['host']['seat']));
            expect_game_error('seatNotFound', static fn() => $games->removeSeat($match['gameId'], $match['host']['identity'], $match['host']['token'], 'nope'));
            $games->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            expect_game_error('illegalLifecycle', static fn() => $games->removeSeat($match['gameId'], $match['host']['identity'], $match['host']['token'], $match['rival']['seat']));
        },

        'leave and remove are served over HTTP' => static function (): void {
            $app = test_application();
            $tokens = [];
            foreach (['host', 'a', 'b'] as $who) {
                $tokens[$who] = $app->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => "lobby-leave-{$who}"]))->body['accessToken'];
            }
            $auth = static fn(string $who): array => ['authorization' => 'Bearer ' . $tokens[$who]];
            $created = $app->handle(new Request('POST', '/api/v1/games', $auth('host'), ['mode' => 'coop', 'scenarioName' => 'Void Reaches']));
            $gameId = $created->body['game']['gameId'];
            $a = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/join", $auth('a')));
            $b = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/join", $auth('b')));

            $left = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/leave", $auth('a') + ['x-seat-token' => $a->body['seatToken']]));
            expect_same(200, $left->status, 'leave succeeds');
            $seatB = $b->body['game']['viewer']['seatId'];
            $removed = $app->handle(new Request('POST', "/api/v1/games/{$gameId}/seats/{$seatB}/remove", $auth('host') + ['x-seat-token' => $created->body['seatToken']]));
            expect_same(200, $removed->status, 'remove succeeds');
            expect_same(1, count($removed->body['memberships']), 'only the host remains');
            $gone = $app->handle(new Request('GET', "/api/v1/games/{$gameId}", $auth('b') + ['x-seat-token' => $b->body['seatToken']]));
            expect_same(403, $gone->status, 'a removed player is refused');
            expect_same('membershipRequired', $gone->body['error']['code'], 'with a clear code');
        },
    ];
}
