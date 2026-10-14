<?php
declare(strict_types=1);

use BloodAndBanners\Config\AppConfig;
use BloodAndBanners\Http\Gateway;

function gateway_tests(): array
{
    return [
        'gateway failures never expose exception messages' => static function (): void {
            $logs = [];
            $response = Gateway::failure(
                new RuntimeException('password=not-for-clients'),
                logger: static function (string $line) use (&$logs): void {
                    $logs[] = $line;
                }
            );

            $encoded = json_encode($response->body, JSON_THROW_ON_ERROR);
            expect_same(503, $response->status, 'bootstrap failure status mismatch');
            expect_same('serviceUnavailable', $response->body['error']['code'], 'bootstrap failure code mismatch');
            expect_true(!str_contains($encoded, 'not-for-clients'), 'bootstrap response leaked a secret');
            expect_true(!str_contains(implode("\n", $logs), 'not-for-clients'), 'bootstrap log leaked a secret');
            expect_true(!str_contains(implode("\n", $logs), 'password='), 'bootstrap log leaked the exception message');
            expect_same(1, count($logs), 'bootstrap failure should produce one sanitized log line');
        },

        'gateway CORS requires an exact non-empty origin match' => static function (): void {
            $config = gateway_test_config('https://blood.trickypig.com');
            $headers = Gateway::corsHeaders($config, 'https://blood.trickypig.com');

            expect_same('https://blood.trickypig.com', $headers['Access-Control-Allow-Origin'] ?? null, 'allowed origin mismatch');
            expect_same('Origin', $headers['Vary'] ?? null, 'CORS vary header mismatch');
            expect_same(
                'Authorization, Content-Type, X-Client-Platform, X-Client-Version, X-Seat-Token',
                $headers['Access-Control-Allow-Headers'] ?? null,
                'allowed headers mismatch'
            );
            expect_same('GET, POST, PUT, DELETE, OPTIONS', $headers['Access-Control-Allow-Methods'] ?? null, 'allowed methods mismatch');
            expect_same([], Gateway::corsHeaders($config, ''), 'empty origin should not receive CORS headers');
            expect_same([], Gateway::corsHeaders($config, 'https://blood.trickypig.com.evil.test'), 'lookalike origin was allowed');
        },
    ];
}

function gateway_test_config(string $corsOrigin): AppConfig
{
    return new AppConfig(
        'sqlite',
        '',
        '',
        '',
        '',
        ':memory:',
        '0123456789abcdef0123456789abcdef',
        'production',
        $corsOrigin,
    );
}
