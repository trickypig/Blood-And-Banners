<?php
declare(strict_types=1);

use BloodAndBanners\Http\RequestFactory;

function http_request_factory_tests(): array
{
    return [
        'play mount is removed only at a path boundary' => static function (): void {
            $request = RequestFactory::fromHttp(
                'POST',
                '/play/api/v1/games?invite=abc',
                ['Authorization' => 'Bearer token'],
                '{"mode":"vanillaPvp"}',
                ['invite' => 'abc'],
                '/play'
            );
            expect_same('/api/v1/games', $request->path, 'mounted path mismatch');
            expect_same('abc', $request->query['invite'], 'query mismatch');
            expect_same('vanillaPvp', $request->body['mode'], 'JSON body mismatch');

            $unrelated = RequestFactory::fromHttp('GET', '/playground/api/v1/games', [], '', [], '/play');
            expect_same('/playground/api/v1/games', $unrelated->path, 'prefix without boundary was stripped');
        },

        'mount root normalizes to the application root' => static function (): void {
            $request = RequestFactory::fromHttp('GET', '/play?source=invite', [], '', ['source' => 'invite'], '/play/');
            expect_same('/', $request->path, 'mount root mismatch');
            expect_same([], $request->body, 'empty request should have an empty body');
        },

        'empty mount preserves the API route' => static function (): void {
            $request = RequestFactory::fromHttp('GET', '/api/v1/compatibility', [], '', [], '');
            expect_same('/api/v1/compatibility', $request->path, 'local API path changed');
        },

        'malformed JSON fails before dispatch' => static function (): void {
            try {
                RequestFactory::fromHttp('POST', '/play/api/v1/games', [], '{', [], '/play');
                throw new RuntimeException('malformed JSON was accepted');
            } catch (JsonException) {
            }
        },

        'non-object JSON becomes an empty request body' => static function (): void {
            $request = RequestFactory::fromHttp('POST', '/play/api/v1/games', [], 'null', [], '/play');
            expect_same([], $request->body, 'non-object JSON should not become a request body');
        },

        'redirected CGI authorization is restored for the application' => static function (): void {
            $request = RequestFactory::fromHttp(
                'POST',
                '/play/api/v1/games',
                [],
                '{}',
                [],
                '/play',
                ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer recovered-token']
            );

            expect_same('recovered-token', $request->bearerToken(), 'redirected bearer token was not restored');
        },
    ];
}
