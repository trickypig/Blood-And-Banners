<?php
declare(strict_types=1);

namespace BloodAndBanners\Http;

final class RequestFactory
{
    public static function fromHttp(
        string $method,
        string $requestUri,
        array $headers,
        string $rawBody,
        array $query,
        string $mountPath = '',
        array $server = [],
    ): Request {
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $mount = '/' . trim($mountPath, '/');
        if ($mount !== '/' && ($path === $mount || str_starts_with($path, $mount . '/'))) {
            $path = substr($path, strlen($mount)) ?: '/';
        }

        $body = $rawBody === '' ? [] : json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        $hasAuthorization = false;
        foreach ($headers as $name => $_value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $hasAuthorization = true;
                break;
            }
        }

        if (!$hasAuthorization) {
            $authorization = $server['HTTP_AUTHORIZATION']
                ?? $server['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;
            if (is_string($authorization) && $authorization !== '') {
                $headers['Authorization'] = $authorization;
            }
        }

        return new Request(
            $method,
            $path,
            $headers,
            is_array($body) ? $body : [],
            $query,
            clientIp: (string)($server['REMOTE_ADDR'] ?? ''),
            rawBody: $rawBody,
        );
    }
}
