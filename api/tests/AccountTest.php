<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function account_tests(): array
{
    return [
        'account profile starts unnamed and accepts a display name' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'me@example.com');
            $profile = $ctx['app']->handle(new Request('GET', '/api/v1/account', bearer($user)));
            expect_same(['userId' => $user['identityId'], 'email' => 'me@example.com', 'displayName' => ''], $profile->body, 'profile');
            $updated = $ctx['app']->handle(new Request('PUT', '/api/v1/account', bearer($user), ['displayName' => "  Lady \t Ash  "]));
            expect_same('Lady Ash', $updated->body['displayName'], 'whitespace collapsed');
            expect_same('Lady Ash', sign_in($ctx, 'me@example.com')['displayName'], 'name returned on next sign-in');
        },

        'display names must be 1 to 24 characters' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'me@example.com');
            foreach (['   ', str_repeat('x', 25)] as $bad) {
                $response = $ctx['app']->handle(new Request('PUT', '/api/v1/account', bearer($user), ['displayName' => $bad]));
                expect_same(400, $response->status, 'rejected');
                expect_same('invalidDisplayName', $response->body['error']['code'], 'code');
            }
        },

        'guests cannot use account routes' => static function (): void {
            $ctx = account_context();
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $response = $ctx['app']->handle(new Request('GET', '/api/v1/account', bearer($guest)));
            expect_same(403, $response->status, 'forbidden');
            expect_same('accountRequired', $response->body['error']['code'], 'code');
        },

        'resume rotates the seat token' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'r@example.com');
            $created = create_game($ctx, $user);
            $gameId = $created['game']['gameId'];
            $resumed = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/resume", bearer($user)));
            expect_same(200, $resumed->status, 'resume ok');
            expect_true($resumed->body['seatToken'] !== $created['seatToken'], 'new token');
            $old = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$gameId}", bearer($user) + ['x-seat-token' => $created['seatToken']]));
            expect_same(403, $old->status, 'old token rejected');
            $new = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$gameId}", bearer($user) + ['x-seat-token' => $resumed->body['seatToken']]));
            expect_same(200, $new->status, 'new token accepted');
        },

        'resume by a non-member is not found' => static function (): void {
            $ctx = account_context();
            $host = sign_in($ctx, 'host@example.com');
            $other = sign_in($ctx, 'other@example.com');
            $gameId = create_game($ctx, $host)['game']['gameId'];
            $response = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/resume", bearer($other)));
            expect_same(404, $response->status, 'not a member');
        },

        'joining a game you already sit in returns your seat' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'again@example.com');
            $gameId = create_game($ctx, $user)['game']['gameId'];
            $joined = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($user)));
            expect_same(200, $joined->status, 'no 500 from duplicate membership');
            expect_same(1, count($joined->body['game']['memberships']), 'still one seat');
        },

        'signed-in seats start with the display name as commander name' => static function (): void {
            $ctx = account_context();
            $host = sign_in($ctx, 'a@example.com');
            set_display_name($ctx, $host, 'Ash');
            $twin = sign_in($ctx, 'b@example.com');
            set_display_name($ctx, $twin, 'ash');
            $gameId = create_game($ctx, $host)['game']['gameId'];
            $joined = $ctx['app']->handle(new Request('POST', "/api/v1/games/{$gameId}/join", bearer($twin)))->body;
            $names = array_column($joined['game']['memberships'], 'name');
            expect_same(['Ash', null], $names, 'host named; clashing name left empty');
        },
    ];
}
