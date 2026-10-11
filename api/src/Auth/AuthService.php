<?php
declare(strict_types=1);

namespace BloodAndBanners\Auth;

use DomainException;
use PDO;
use Throwable;

final class AuthService
{
    private readonly TokenCodec $tokens;

    public function __construct(private readonly PDO $connection, string $signingKey)
    {
        $this->tokens = new TokenCodec($signingKey);
    }

    public function createGuest(string $deviceId): array
    {
        if (trim($deviceId) === '') {
            throw new DomainException('deviceIdRequired');
        }
        $identityId = self::id();
        $statement = $this->connection->prepare(
            'INSERT INTO guest_identities (id, device_id, created_at) VALUES (:id, :device, :created)'
        );
        $statement->execute(['id' => $identityId, 'device' => $deviceId, 'created' => self::now()]);
        return $this->issuePair('guest', $identityId, $deviceId);
    }

    public function authenticate(string $accessToken): array
    {
        return $this->tokens->decode($accessToken);
    }

    public function refresh(string $refreshToken, string $deviceId): array
    {
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT id, identity_kind, identity_id, device_id, expires_at, revoked_at
                 FROM refresh_tokens WHERE token_hash = :hash'
            );
            $statement->execute(['hash' => hash('sha256', $refreshToken)]);
            $record = $statement->fetch();
            if (!$record || $record['revoked_at'] !== null || $record['device_id'] !== $deviceId || strtotime($record['expires_at']) <= time()) {
                throw new DomainException('invalidRefreshToken');
            }

            $revoke = $this->connection->prepare('UPDATE refresh_tokens SET revoked_at = :revoked WHERE id = :id');
            $revoke->execute(['revoked' => self::now(), 'id' => $record['id']]);
            $pair = $this->issuePair($record['identity_kind'], $record['identity_id'], $deviceId);
            $this->connection->commit();
            return $pair;
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    private function issuePair(string $kind, string $identityId, string $deviceId): array
    {
        $refreshToken = TokenCodec::randomOpaqueToken();
        $statement = $this->connection->prepare(
            'INSERT INTO refresh_tokens
             (id, identity_kind, identity_id, device_id, token_hash, expires_at, created_at)
             VALUES (:id, :kind, :identity, :device, :hash, :expires, :created)'
        );
        $statement->execute([
            'id' => self::id(),
            'kind' => $kind,
            'identity' => $identityId,
            'device' => $deviceId,
            'hash' => hash('sha256', $refreshToken),
            'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 60 * 60 * 24 * 30),
            'created' => self::now(),
        ]);
        return [
            'identityId' => $identityId,
            'kind' => $kind,
            'accessToken' => $this->tokens->issue($identityId, $kind),
            'refreshToken' => $refreshToken,
        ];
    }

    private static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
