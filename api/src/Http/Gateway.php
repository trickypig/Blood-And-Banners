<?php
declare(strict_types=1);

namespace BloodAndBanners\Http;

use BloodAndBanners\Config\AppConfig;
use Throwable;

final class Gateway
{
    public static function failure(Throwable $error, int $status = 503, ?callable $logger = null): Response
    {
        $incidentId = bin2hex(random_bytes(8));
        $line = sprintf(
            'Game API incident %s: %s in %s:%d',
            $incidentId,
            $error::class,
            basename($error->getFile()),
            $error->getLine(),
        );
        ($logger ?? static fn(string $message): bool => error_log($message))($line);

        return new Response($status, [
            'error' => [
                'code' => 'serviceUnavailable',
                'message' => 'The game service is temporarily unavailable.',
            ],
        ]);
    }

    /** @return array<string, string> */
    public static function corsHeaders(AppConfig $config, string $requestOrigin): array
    {
        if ($requestOrigin === '' || $requestOrigin !== $config->corsOrigin) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $config->corsOrigin,
            'Vary' => 'Origin',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Client-Platform, X-Client-Version, X-Seat-Token',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        ];
    }

    public static function emit(Response $response, ?AppConfig $config, string $requestOrigin): void
    {
        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($config !== null) {
            foreach (self::corsHeaders($config, $requestOrigin) as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($response->status !== 204) {
            echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
    }
}
