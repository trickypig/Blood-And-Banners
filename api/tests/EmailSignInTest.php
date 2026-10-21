<?php
declare(strict_types=1);

use BloodAndBanners\Http\Request;

function email_sign_in_tests(): array
{
    return [
        'start mails a six digit code and a sign-in link' => static function (): void {
            $ctx = account_context();
            $response = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'Hero@Example.com']));
            expect_same(202, $response->status, 'start accepted');
            expect_same('hero@example.com', $ctx['mailer']->sent[0]['to'], 'normalized recipient');
            expect_true(preg_match('/^\d{6}$/', mailed_code($ctx['mailer'])) === 1, 'six digit code');
            expect_true(str_contains($ctx['mailer']->sent[0]['text'], 'https://blood.test/play/?signin='), 'link uses public URL');
        },

        'start rejects an invalid address' => static function (): void {
            $ctx = account_context();
            $response = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'not-an-email']));
            expect_same(400, $response->status, 'invalid email is 400');
            expect_same('invalidEmail', $response->body['error']['code'], 'code');
        },

        'start limits challenges per email and per IP' => static function (): void {
            $ctx = account_context();
            for ($i = 0; $i < 3; $i++) {
                expect_same(202, $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'a@example.com']))->status, "start {$i}");
            }
            $limited = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'A@example.com ']));
            expect_same(429, $limited->status, 'fourth in 15 minutes is limited');
            expect_same('tooManyRequests', $limited->body['error']['code'], 'code');

            $ctx = account_context();
            for ($i = 0; $i < 20; $i++) {
                $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => "p{$i}@example.com"], clientIp: '10.0.0.1'));
            }
            $limited = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'p99@example.com'], clientIp: '10.0.0.1'));
            expect_same(429, $limited->status, 'twenty-first per IP per hour is limited');
        },

        'failed mail still answers 202 and does not count toward the limit' => static function (): void {
            $ctx = account_context();
            $ctx['mailer']->fail = true;
            for ($i = 0; $i < 4; $i++) {
                expect_same(202, $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'a@example.com']))->status, "failed start {$i}");
            }
            $ctx['mailer']->fail = false;
            expect_same(202, $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'a@example.com']))->status, 'not limited');
        },

        'verify by code creates the account once and reuses it, ignoring email case' => static function (): void {
            $ctx = account_context();
            $first = sign_in($ctx, 'bob@example.com');
            expect_same('user', $first['kind'], 'user session');
            expect_same('', $first['displayName'], 'new account has no name yet');
            $second = sign_in($ctx, '  Bob@Example.COM ');
            expect_same($first['identityId'], $second['identityId'], 'same account');
            expect_same(1, (int)$ctx['db']->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'one user row');
        },

        'verify by link token signs in and cannot be reused' => static function (): void {
            $ctx = account_context();
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'link@example.com']));
            $token = mailed_token($ctx['mailer']);
            $ok = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['token' => $token, 'deviceId' => 'd']));
            expect_same(200, $ok->status, 'link verifies');
            $again = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['token' => $token, 'deviceId' => 'd']));
            expect_same(400, $again->status, 'consumed link rejected');
            expect_same('invalidCode', $again->body['error']['code'], 'code');
        },

        'wrong codes lock the challenge after five attempts' => static function (): void {
            $ctx = account_context();
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'x@example.com']));
            $code = mailed_code($ctx['mailer']);
            $wrong = $code === '000000' ? '111111' : '000000';
            for ($i = 0; $i < 5; $i++) {
                $bad = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'x@example.com', 'code' => $wrong, 'deviceId' => 'd']));
                expect_same(400, $bad->status, "wrong attempt {$i}");
            }
            $late = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'x@example.com', 'code' => $code, 'deviceId' => 'd']));
            expect_same(400, $late->status, 'right code after lock is rejected');
        },

        'expired challenges are rejected' => static function (): void {
            $now = 1_800_000_000;
            $ctx = account_context(static function () use (&$now): int { return $now; });
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'late@example.com']));
            $code = mailed_code($ctx['mailer']);
            $now += 15 * 60 + 1;
            $late = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'late@example.com', 'code' => $code, 'deviceId' => 'd']));
            expect_same(400, $late->status, 'expired code rejected');
        },

        'verify requires a device id' => static function (): void {
            $ctx = account_context();
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'd@example.com']));
            $response = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'd@example.com', 'code' => mailed_code($ctx['mailer'])]));
            expect_same(400, $response->status, 'missing device');
            expect_same('deviceIdRequired', $response->body['error']['code'], 'code');
        },

        'verify with a guest token moves the guest seat to the account' => static function (): void {
            $ctx = account_context();
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $created = $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($guest), ['mode' => 'vanillaPvp', 'scenarioName' => 'Four Corners']))->body;
            $user = sign_in($ctx, 'carry@example.com', 'device-1', bearer($guest));
            $state = $ctx['app']->handle(new Request('GET', "/api/v1/games/{$created['game']['gameId']}", bearer($user) + ['x-seat-token' => $created['seatToken']]));
            expect_same(200, $state->status, 'account now holds the guest seat with the same seat token');
            expect_true($state->body['viewer']['isHost'], 'still host');
        },

        'guest carry-over skips games the account already sits in' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'twice@example.com');
            $created = $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($user), ['mode' => 'vanillaPvp', 'scenarioName' => 'Four Corners']))->body;
            $guest = $ctx['app']->handle(new Request('POST', '/api/v1/auth/guest', body: ['deviceId' => 'g']))->body;
            $ctx['app']->handle(new Request('POST', "/api/v1/games/{$created['game']['gameId']}/join", bearer($guest)));
            $again = sign_in($ctx, 'twice@example.com', 'device-2', bearer($guest));
            expect_same($user['identityId'], $again['identityId'], 'same account, no 500');
            $seats = $ctx['db']->prepare('SELECT identity_id FROM game_memberships WHERE game_id = :game');
            $seats->execute(['game' => $created['game']['gameId']]);
            $holders = $seats->fetchAll(PDO::FETCH_COLUMN);
            expect_same(2, count($holders), 'both seats remain');
            expect_true(in_array($guest['identityId'], $holders, true), 'guest seat left as-is');
            expect_true(in_array($user['identityId'], $holders, true), 'account seat kept');
        },

        'a wrong code after a successful verify does not reopen the consumed challenge' => static function (): void {
            $ctx = account_context();
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => 'once@example.com']));
            $code = mailed_code($ctx['mailer']);
            $token = mailed_token($ctx['mailer']);
            $wrong = $code === '000000' ? '111111' : '000000';
            $ok = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'once@example.com', 'code' => $code, 'deviceId' => 'd']));
            expect_same(200, $ok->status, 'code verifies');
            $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['email' => 'once@example.com', 'code' => $wrong, 'deviceId' => 'd']));
            // Replays the guarded wrong-guess UPDATE against the consumed row, as a stale parallel request would.
            $ctx['db']->exec("UPDATE login_challenges SET consumed_at = CASE WHEN attempts + 1 >= 5 THEN '2000-01-01 00:00:00' ELSE consumed_at END, attempts = attempts + 1 WHERE consumed_at IS NULL");
            $row = $ctx['db']->query('SELECT consumed_at, attempts FROM login_challenges')->fetch();
            expect_true($row['consumed_at'] !== null, 'challenge stays consumed');
            expect_same(0, (int)$row['attempts'], 'consumed challenge is not counted further');
            $replay = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', body: ['token' => $token, 'deviceId' => 'd']));
            expect_same(400, $replay->status, 'link token stays dead');
        },

        'logout revokes the refresh token' => static function (): void {
            $ctx = account_context();
            $user = sign_in($ctx, 'out@example.com');
            expect_same(204, $ctx['app']->handle(new Request('POST', '/api/v1/auth/logout', body: ['refreshToken' => $user['refreshToken']]))->status, 'logout');
            $refresh = $ctx['app']->handle(new Request('POST', '/api/v1/auth/refresh', body: ['refreshToken' => $user['refreshToken'], 'deviceId' => 'device-1']));
            expect_same(401, $refresh->status, 'revoked token cannot refresh');
        },
    ];
}
