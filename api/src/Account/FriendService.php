<?php
declare(strict_types=1);

namespace BloodAndBanners\Account;

use BloodAndBanners\Auth\EmailSignInService;
use PDO;
use Throwable;

/** Friend requests by email or from a shared game; a pair is friends once both sides agree. */
final class FriendService
{
    private const UNNAMED = 'Unnamed commander';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function list(string $me): array
    {
        $friends = $this->connection->prepare(
            "SELECT u.id, u.display_name FROM friendships f
             INNER JOIN users u ON u.id = CASE WHEN f.requester_user_id = :me1 THEN f.addressee_user_id ELSE f.requester_user_id END
             WHERE f.status = 'accepted' AND (f.requester_user_id = :me2 OR f.addressee_user_id = :me3)
             ORDER BY u.display_name, u.id"
        );
        $friends->execute(['me1' => $me, 'me2' => $me, 'me3' => $me]);

        $incoming = $this->connection->prepare(
            "SELECT f.id, u.display_name FROM friendships f INNER JOIN users u ON u.id = f.requester_user_id
             WHERE f.status = 'pending' AND f.addressee_user_id = :me ORDER BY f.created_at, f.id"
        );
        $incoming->execute(['me' => $me]);

        $outgoing = $this->connection->prepare(
            "SELECT f.id, f.addressee_email, f.from_game, u.display_name FROM friendships f
             LEFT JOIN users u ON u.id = f.addressee_user_id
             WHERE f.status = 'pending' AND f.requester_user_id = :me ORDER BY f.created_at, f.id"
        );
        $outgoing->execute(['me' => $me]);

        return [
            'friends' => array_map(static fn(array $row): array => [
                'userId' => $row['id'],
                'displayName' => self::name($row['display_name']),
            ], $friends->fetchAll()),
            'incoming' => array_map(static fn(array $row): array => [
                'requestId' => $row['id'],
                'displayName' => self::name($row['display_name']),
            ], $incoming->fetchAll()),
            'outgoing' => array_map(static fn(array $row): array => [
                'requestId' => $row['id'],
                // A request made from a game never reveals the other player's email address.
                'label' => (int)$row['from_game'] === 1 ? self::name($row['display_name']) : $row['addressee_email'],
            ], $outgoing->fetchAll()),
        ];
    }

    public function requestByEmail(string $me, string $email): array
    {
        $email = EmailSignInService::normalizeEmail($email);
        $statement = $this->connection->prepare('SELECT id FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        $target = $statement->fetchColumn();
        return $this->request($me, is_string($target) ? $target : null, $email, false);
    }

    public function requestFromSeat(string $me, string $gameId, string $seatId): array
    {
        $mine = $this->connection->prepare('SELECT COUNT(*) FROM game_memberships WHERE game_id = :game AND identity_id = :me');
        $mine->execute(['game' => $gameId, 'me' => $me]);
        $seat = $this->connection->prepare(
            'SELECT u.id, u.email FROM game_memberships m INNER JOIN users u ON u.id = m.identity_id WHERE m.game_id = :game AND m.id = :seat'
        );
        $seat->execute(['game' => $gameId, 'seat' => $seatId]);
        $target = $seat->fetch();
        if ((int)$mine->fetchColumn() === 0 || !$target) {
            throw new AccountException(400, 'cannotFriendSeat', 'That commander cannot be friended.');
        }
        return $this->request($me, (string)$target['id'], (string)$target['email'], true);
    }

    public function accept(string $me, string $requestId): array
    {
        $statement = $this->connection->prepare(
            "UPDATE friendships SET status = 'accepted', responded_at = :now WHERE id = :id AND addressee_user_id = :me AND status = 'pending'"
        );
        $statement->execute(['now' => self::now(), 'id' => $requestId, 'me' => $me]);
        if ($statement->rowCount() !== 1) {
            throw new AccountException(404, 'requestNotFound', 'That friend request no longer exists.');
        }
        return $this->list($me);
    }

    public function decline(string $me, string $requestId): array
    {
        $statement = $this->connection->prepare(
            "DELETE FROM friendships WHERE id = :id AND status = 'pending' AND (addressee_user_id = :me1 OR requester_user_id = :me2)"
        );
        $statement->execute(['id' => $requestId, 'me1' => $me, 'me2' => $me]);
        if ($statement->rowCount() !== 1) {
            throw new AccountException(404, 'requestNotFound', 'That friend request no longer exists.');
        }
        return $this->list($me);
    }

    public function remove(string $me, string $otherUserId): array
    {
        $statement = $this->connection->prepare(
            "DELETE FROM friendships WHERE status = 'accepted'
             AND ((requester_user_id = :me1 AND addressee_user_id = :other1) OR (requester_user_id = :other2 AND addressee_user_id = :me2))"
        );
        $statement->execute(['me1' => $me, 'other1' => $otherUserId, 'other2' => $otherUserId, 'me2' => $me]);
        if ($statement->rowCount() === 0) {
            throw new AccountException(404, 'friendNotFound', 'You are not friends with that commander.');
        }
        return $this->list($me);
    }

    /** @return list<string> */
    public function friendIds(string $me): array
    {
        $statement = $this->connection->prepare(
            "SELECT CASE WHEN requester_user_id = :me1 THEN addressee_user_id ELSE requester_user_id END
             FROM friendships WHERE status = 'accepted' AND (requester_user_id = :me2 OR addressee_user_id = :me3)"
        );
        $statement->execute(['me1' => $me, 'me2' => $me, 'me3' => $me]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> Accounts this user has a pending request out to. */
    public function requestedIds(string $me): array
    {
        $statement = $this->connection->prepare(
            "SELECT addressee_user_id FROM friendships WHERE status = 'pending' AND requester_user_id = :me AND addressee_user_id IS NOT NULL"
        );
        $statement->execute(['me' => $me]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function request(string $me, ?string $targetId, string $email, bool $fromGame): array
    {
        $mine = $this->connection->prepare('SELECT email FROM users WHERE id = :me');
        $mine->execute(['me' => $me]);
        if ($targetId === $me || $mine->fetchColumn() === $email) {
            throw new AccountException(400, 'cannotFriendSelf', 'You cannot send a friend request to yourself.');
        }

        $this->connection->beginTransaction();
        try {
            if ($targetId !== null) {
                $reverse = $this->connection->prepare(
                    'SELECT id, status FROM friendships WHERE requester_user_id = :target AND addressee_user_id = :me'
                );
                $reverse->execute(['target' => $targetId, 'me' => $me]);
                $row = $reverse->fetch();
                if ($row && $row['status'] === 'accepted') {
                    throw new AccountException(409, 'alreadyFriends', 'You are already friends.');
                }
                if ($row) {
                    // They already asked: asking back is the same as accepting.
                    $this->connection->prepare("UPDATE friendships SET status = 'accepted', responded_at = :now WHERE id = :id")
                        ->execute(['now' => self::now(), 'id' => $row['id']]);
                    $this->connection->commit();
                    return $this->list($me);
                }
            }

            $existing = $this->connection->prepare('SELECT status FROM friendships WHERE requester_user_id = :me AND addressee_email = :email');
            $existing->execute(['me' => $me, 'email' => $email]);
            $status = $existing->fetchColumn();
            if ($status === 'accepted') {
                throw new AccountException(409, 'alreadyFriends', 'You are already friends.');
            }
            if ($status === 'pending') {
                throw new AccountException(409, 'alreadyRequested', 'You already sent that request.');
            }

            $this->connection->prepare(
                "INSERT INTO friendships (id, requester_user_id, addressee_user_id, addressee_email, status, from_game, created_at, responded_at)
                 VALUES (:id, :me, :target, :email, 'pending', :fromGame, :now, NULL)"
            )->execute([
                'id' => bin2hex(random_bytes(16)),
                'me' => $me,
                'target' => $targetId,
                'email' => $email,
                'fromGame' => $fromGame ? 1 : 0,
                'now' => self::now(),
            ]);
            $this->connection->commit();
            return $this->list($me);
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    private static function name(?string $displayName): string
    {
        return $displayName === null || $displayName === '' ? self::UNNAMED : $displayName;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
