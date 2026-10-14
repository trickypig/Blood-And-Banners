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
    ): Request {
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $mount = '/' . trim($mountPath, '/');
        if ($mount !== '/' && ($path === $mount || str_starts_with($path, $mount . '/'))) {
            $path = substr($path, strlen($mount)) ?: '/';
        }

        $body = $rawBody === '' ? [] : json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        return new Request(
            $method,
            $path,
            $headers,
            is_array($body) ? $body : [],
            $query,
        );
    }
}
