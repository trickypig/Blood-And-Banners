<?php
declare(strict_types=1);

namespace BloodAndBanners\Account;

use BloodAndBanners\Game\GameService;
use PDO;

final class AccountService
{
    private const OPEN_LIFECYCLES = "('lobby', 'setup', 'draft', 'active')";
    private const FEED_LIMIT = 20;

    public function __construct(private readonly PDO $connection, private readonly FriendService $friends)
    {
    }

    /** @return array{yours: list<array>, friends: list<array>} */
    public function games(string $me): array
    {
        $friendIds = $this->friends->friendIds($me);
        $friendSet = array_fill_keys($friendIds, true);

        $yours = $this->connection->prepare(
            'SELECT g.id, g.mode, g.lifecycle, g.scenario_name, g.updated_at FROM games g
             INNER JOIN game_memberships m ON m.game_id = g.id
             WHERE m.identity_id = :me AND g.lifecycle IN ' . self::OPEN_LIFECYCLES . '
             ORDER BY g.updated_at DESC LIMIT ' . self::FEED_LIMIT
        );
        $yours->execute(['me' => $me]);

        $friendRows = [];
        if ($friendIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($friendIds), '?'));
            $statement = $this->connection->prepare(
                'SELECT g.id, g.mode, g.lifecycle, g.scenario_name, g.updated_at FROM games g
                 WHERE g.lifecycle IN ' . self::OPEN_LIFECYCLES . "
                   AND g.id IN (SELECT game_id FROM game_memberships WHERE identity_id IN ({$placeholders}))
                   AND g.id NOT IN (SELECT game_id FROM game_memberships WHERE identity_id = ?)
                 ORDER BY g.updated_at DESC LIMIT " . self::FEED_LIMIT
            );
            $statement->execute([...$friendIds, $me]);
            $friendRows = $statement->fetchAll();
        }

        return [
            'yours' => array_map(fn(array $game): array => $this->row($game, $friendSet), $yours->fetchAll()),
            'friends' => array_map(fn(array $game): array => $this->row($game, $friendSet), $friendRows),
        ];
    }

    private function row(array $game, array $friendSet): array
    {
        $statement = $this->connection->prepare(
            'SELECT seat_index, commander_name, identity_id FROM game_memberships WHERE game_id = :game ORDER BY seat_index'
        );
        $statement->execute(['game' => $game['id']]);
        $seats = $statement->fetchAll();
        return [
            'gameId' => $game['id'],
            'mode' => $game['mode'],
            'scenarioName' => $game['scenario_name'],
            'lifecycle' => $game['lifecycle'],
            'seats' => array_map(static fn(array $seat): array => [
                'seatIndex' => (int)$seat['seat_index'],
                'name' => $seat['commander_name'] === null ? null : (string)$seat['commander_name'],
                'isFriend' => isset($friendSet[$seat['identity_id']]),
            ], $seats),
            'canJoin' => $game['lifecycle'] === 'lobby' && count($seats) < GameService::seatLimit((string)$game['mode']),
            'updatedAt' => (string)$game['updated_at'],
        ];
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
