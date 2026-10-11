<?php
declare(strict_types=1);

namespace BloodAndBanners\Auth;

use DomainException;

final class TokenCodec
{
    public function __construct(private readonly string $signingKey)
    {
        if (strlen($signingKey) < 32) {
            throw new DomainException('signingKeyTooShort');
        }
    }

    public function issue(string $identityId, string $kind, int $lifetimeSeconds = 900): string
    {
        $payload = self::base64UrlEncode(json_encode([
            'identityId' => $identityId,
            'kind' => $kind,
            'expiresAt' => time() + $lifetimeSeconds,
        ], JSON_THROW_ON_ERROR));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->signingKey, true));
        return $payload . '.' . $signature;
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new DomainException('invalidAccessToken');
        }
        [$payload, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->signingKey, true));
        if (!hash_equals($expected, $signature)) {
            throw new DomainException('invalidAccessToken');
        }
        $decoded = json_decode(self::base64UrlDecode($payload), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['expiresAt'] ?? 0) < time()) {
            throw new DomainException('expiredAccessToken');
        }
        return $decoded;
    }

    public static function randomOpaqueToken(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new DomainException('invalidAccessToken');
        }
        return $decoded;
    }
}
