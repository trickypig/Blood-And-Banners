<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function account_games_tests(): array
{
    $games = static fn(array $ctx, array $session): array =>
        $ctx['app']->handle(new Request('GET', '/api/v1/account/games', bearer($session)))->body;

    return [
        'your unfinished games are listed newest first' => static function () use ($games): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $first = create_game($ctx, $me)['game']['gameId'];
            $second = create_game($ctx, $me, 'coop', 'Void Reaches')['game']['gameId'];
            $ctx['db']->prepare("UPDATE games SET updated_at = '2000-01-01 00:00:00' WHERE id = :id")->execute(['id' => $first]);
            $ctx['db']->prepare("UPDATE games SET lifecycle = 'completed' WHERE id = :id")->execute(['id' => $second]);
            $third = create_game($ctx, $me)['game']['gameId'];
            $yours = array_column($games($ctx, $me)['yours'], 'gameId');
            expect_same([$third, $first], $yours, 'completed hidden, newest first');
        },

        'friends games exclude your own seats, pending friends and finished games' => static function () use ($games): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $friend = sign_in($ctx, 'f@example.com');
            set_display_name($ctx, $friend, 'Fen');
            $stranger = sign_in($ctx, 's@example.com');
            befriend($ctx, $me, $friend, 'f@example.com');
            $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($me), ['email' => 's@example.com']));

            $open = create_game($ctx, $friend)['game']['gameId'];
            $shared = create_game($ctx, $friend)['game']['gameId'];
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$shared}/join", bearer($me)));
            $done = create_game($ctx, $friend)['game']['gameId'];
            $ctx['db']->prepare("UPDATE games SET lifecycle = 'completed' WHERE id = :id")->execute(['id' => $done]);
            create_game($ctx, $stranger);

            $rows = $games($ctx, $me)['friends'];
            expect_same([$open], array_column($rows, 'gameId'), 'only the open friend game');
            expect_same(true, $rows[0]['canJoin'], 'lobby with a free seat');
            expect_same([['seatIndex' => 1, 'name' => 'Fen', 'isFriend' => true]], $rows[0]['seats'], 'seats');
        },

        'full or started friend games cannot be joined' => static function () use ($games): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $friend = sign_in($ctx, 'f@example.com');
            befriend($ctx, $me, $friend, 'f@example.com');
            $full = create_game($ctx, $friend)['game']['gameId'];
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$full}/join", bearer($guest)));
            expect_same(false, $games($ctx, $me)['friends'][0]['canJoin'], 'full duel');
        },

        'projection marks account seats, friends and pending requests' => static function (): void {
            $ctx = account_context();
            $me = sign_in($ctx, 'me@example.com');
            $friend = sign_in($ctx, 'f@example.com');
            $other = sign_in($ctx, 'o@example.com');
            befriend($ctx, $me, $friend, 'f@example.com');
            $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($me), ['email' => 'o@example.com']));
            $created = create_game($ctx, $me, 'coop', 'Void Reaches');
            $gameId = $created['game']['gameId'];
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($friend)));
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($other)));
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($guest)));
            $view = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$gameId}", bearer($me) + ['x-seat-token' => $created['seatToken']]))->body;
            $flags = array_map(static fn(array $m): array => [$m['isAccount'], $m['isFriend'], $m['friendRequested']], $view['memberships']);
            expect_same([[true, false, false], [true, true, false], [true, false, true], [false, false, false]], $flags, 'me, friend, requested, guest');
        },
    ];
}
