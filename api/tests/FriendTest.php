<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function friend_tests(): array
{
    $friends = static fn(array $ctx, array $session): array =>
        $ctx['app']->handle(new Request('GET', '/api/v1/friends', bearer($session)))->body;
    $request = static fn(array $ctx, array $session, array $body) =>
        $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($session), $body));

    return [
        'request by email appears as incoming and accept makes friends' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            set_display_name($ctx, $a, 'Ash');
            $b = sign_in($ctx, 'b@example.com');
            set_display_name($ctx, $b, 'Bryn');
            $sent = $request($ctx, $a, ['email' => ' B@Example.com ']);
            expect_same(200, $sent->status, 'request ok');
            expect_same('b@example.com', $sent->body['outgoing'][0]['label'], 'typed email shown to requester');
            $incoming = $friends($ctx, $b)['incoming'];
            expect_same('Ash', $incoming[0]['displayName'], 'incoming shows requester name');
            $accepted = $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$incoming[0]['requestId']}/accept", bearer($b)));
            expect_same([['userId' => $a['identityId'], 'displayName' => 'Ash']], $accepted->body['friends'], 'b sees a');
            expect_same([['userId' => $b['identityId'], 'displayName' => 'Bryn']], $friends($ctx, $a)['friends'], 'a sees b');
        },

        'request to an unknown email waits for that account' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $request($ctx, $a, ['email' => 'later@example.com']);
            expect_same(0, count($ctx['mailer']->sent) - 1, 'no invitation email sent');
            $later = sign_in($ctx, 'Later@example.com');
            expect_same(1, count($friends($ctx, $later)['incoming']), 'claimed on first sign-in');
        },

        'mutual requests accept automatically' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            $request($ctx, $a, ['email' => 'b@example.com']);
            $back = $request($ctx, $b, ['email' => 'a@example.com']);
            expect_same(200, $back->status, 'friend back ok');
            expect_same(1, count($back->body['friends']), 'now friends');
            expect_same([], $back->body['incoming'], 'no leftover request');
        },

        'self and duplicate requests are rejected' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            expect_same('cannotFriendSelf', $request($ctx, $a, ['email' => 'A@example.com'])->body['error']['code'], 'self');
            $request($ctx, $a, ['email' => 'b@example.com']);
            $dup = $request($ctx, $a, ['email' => 'b@example.com']);
            expect_same(409, $dup->status, 'duplicate');
            expect_same('alreadyRequested', $dup->body['error']['code'], 'code');
        },

        'already friends is a conflict' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            befriend($ctx, $a, $b, 'b@example.com');
            expect_same('alreadyFriends', $request($ctx, $b, ['email' => 'a@example.com'])->body['error']['code'], 'reverse');
            expect_same('alreadyFriends', $request($ctx, $a, ['email' => 'b@example.com'])->body['error']['code'], 'same direction');
        },

        'only the addressee can accept; either side can decline' => static function () use ($friends, $request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            $id = $request($ctx, $a, ['email' => 'b@example.com'])->body['outgoing'][0]['requestId'];
            expect_same(404, $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$id}/accept", bearer($a)))->status, 'requester cannot accept');
            $cancel = $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$id}/decline", bearer($a)));
            expect_same([], $cancel->body['outgoing'], 'requester cancelled');
            expect_same([], $friends($ctx, $b)['incoming'], 'gone for addressee');
        },

        'unfriend removes both directions' => static function () use ($friends): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            befriend($ctx, $a, $b, 'b@example.com');
            $removed = $ctx['app']->handle(new Request('DELETE', "/api/v1/friends/{$a['identityId']}", bearer($b)));
            expect_same([], $removed->body['friends'], 'b no longer has a');
            expect_same([], $friends($ctx, $a)['friends'], 'a no longer has b');
            expect_same(404, $ctx['app']->handle(new Request('DELETE', "/api/v1/friends/{$a['identityId']}", bearer($b)))->status, 'second removal 404');
        },

        'request from a shared game seat hides the email' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $b = sign_in($ctx, 'b@example.com');
            set_display_name($ctx, $b, 'Bryn');
            $gameId = create_game($ctx, $a)['game']['gameId'];
            $joined = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($b)))->body;
            $bSeat = $joined['game']['viewer']['seatId'];
            $sent = $request($ctx, $a, ['gameId' => $gameId, 'seatId' => $bSeat]);
            expect_same(200, $sent->status, 'seat request ok');
            expect_same('Bryn', $sent->body['outgoing'][0]['label'], 'label is the name, not the email');
        },

        'seat requests need a shared game and an account seat' => static function () use ($request): void {
            $ctx = account_context();
            $a = sign_in($ctx, 'a@example.com');
            $outsider = sign_in($ctx, 'o@example.com');
            $created = create_game($ctx, $a);
            $gameId = $created['game']['gameId'];
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $guestSeat = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($guest)))->body['game']['viewer']['seatId'];
            expect_same('cannotFriendSeat', $request($ctx, $a, ['gameId' => $gameId, 'seatId' => $guestSeat])->body['error']['code'], 'guest seat');
            $hostSeat = $created['game']['viewer']['seatId'];
            expect_same('cannotFriendSeat', $request($ctx, $outsider, ['gameId' => $gameId, 'seatId' => $hostSeat])->body['error']['code'], 'not in game');
        },
    ];
}
