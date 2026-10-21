<?php
declare(strict_types=1);

namespace BloodAndBanners\Account;

use PDO;

final class AccountService
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** Same rules as commander names: collapsed whitespace, no control or format characters, 1–24 characters. */
    public static function normalizeName(string $raw): ?string
    {
        $collapsed = (string)preg_replace('/\s+/u', ' ', $raw);
        $name = trim((string)preg_replace('/[\p{Cc}\p{Cf}]/u', '', $collapsed));
        $length = preg_match_all('/./su', $name);
        return $name === '' || $length > 24 ? null : $name;
    }

    public function profile(string $userId): array
    {
        $statement = $this->connection->prepare('SELECT id, email, display_name FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!$user) {
            throw new AccountException(403, 'accountRequired', 'Sign in to use this.');
        }
        return ['userId' => $user['id'], 'email' => $user['email'], 'displayName' => (string)$user['display_name']];
    }

    public function updateDisplayName(string $userId, string $name): array
    {
        $normalized = self::normalizeName($name)
            ?? throw new AccountException(400, 'invalidDisplayName', 'Names must be 1 to 24 characters.');
        $this->connection->prepare('UPDATE users SET display_name = :name WHERE id = :id')
            ->execute(['name' => $normalized, 'id' => $userId]);
        return $this->profile($userId);
    }
}
