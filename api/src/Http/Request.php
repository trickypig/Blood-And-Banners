<?php
declare(strict_types=1);

namespace BloodAndBanners\Http;

final class Request
{
    public readonly array $headers;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $headers = [],
        public readonly array $body = [],
        public readonly array $query = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->headers['authorization'] ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1 ? $matches[1] : null;
    }

    public function seatToken(): string
    {
        return trim((string)($this->headers['x-seat-token'] ?? ''));
    }
}
