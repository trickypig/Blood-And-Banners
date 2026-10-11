<?php
declare(strict_types=1);

namespace BloodAndBanners\Game;

use PDO;
use Throwable;

final class GameService
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function createGame(string $hostIdentityId, string $mode, string $scenarioName): array
    {
        if (!in_array($mode, ['vanillaPvp', 'coop', 'battleRoyale'], true)) {
            throw new GameServiceException('invalidMode', 'The requested game mode is not supported.');
        }
        $this->connection->beginTransaction();
        try {
            $gameId = self::id();
            $seatToken = self::token();
            $now = self::now();
            $this->connection->prepare(
                'INSERT INTO games (id, mode, lifecycle, scenario_name, host_identity_id, version, created_at, updated_at)
                 VALUES (:id, :mode, :lifecycle, :scenario, :host, 1, :created, :updated)'
            )->execute([
                'id' => $gameId, 'mode' => $mode, 'lifecycle' => 'lobby', 'scenario' => $scenarioName,
                'host' => $hostIdentityId, 'created' => $now, 'updated' => $now,
            ]);
            $this->insertMembership($gameId, $hostIdentityId, 1, true, $seatToken);
            $projection = $this->projection($gameId, $hostIdentityId);
            $this->writeSnapshot($gameId, 1, $projection);
            $this->connection->commit();
            return $projection + ['seatToken' => $seatToken];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function joinGame(string $gameId, string $identityId): array
    {
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            if ($game['lifecycle'] !== 'lobby') {
                throw new GameServiceException('gameAlreadyStarted', 'Only lobby games can be joined.', (int)$game['version']);
            }
            $count = (int)$this->scalar('SELECT COUNT(*) FROM game_memberships WHERE game_id = :game', ['game' => $gameId]);
            $maximum = $game['mode'] === 'vanillaPvp' ? 2 : 5;
            if ($count >= $maximum) {
                throw new GameServiceException('gameFull', 'This game has no open player seats.', (int)$game['version']);
            }
            $seatToken = self::token();
            $this->insertMembership($gameId, $identityId, $count + 1, false, $seatToken);
            $version = (int)$game['version'] + 1;
            $this->connection->prepare('UPDATE games SET version = :version, updated_at = :updated WHERE id = :id')
                ->execute(['version' => $version, 'updated' => self::now(), 'id' => $gameId]);
            $this->appendEvent($gameId, $version, 'playerJoined', $identityId, ['seatIndex' => $count + 1]);
            $projection = $this->projection($gameId, $identityId);
            $this->writeSnapshot($gameId, $version, $projection);
            $this->connection->commit();
            return $projection + ['seatToken' => $seatToken];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function setReady(string $gameId, string $identityId, bool $ready): array
    {
        $game = $this->gameRow($gameId);
        return $this->submitCommand($gameId, $identityId, [
            'commandId' => self::id(),
            'expectedVersion' => (int)$game['version'],
            'kind' => 'setReady',
            'payload' => ['ready' => $ready],
        ]);
    }

    public function startGame(string $gameId, string $identityId): array
    {
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            if ($game['host_identity_id'] !== $identityId) {
                throw new GameServiceException('hostRequired', 'Only the host can start the match.', (int)$game['version']);
            }
            $memberships = $this->memberships($gameId);
            $minimum = 2;
            $readyCount = count(array_filter($memberships, static fn(array $seat): bool => (bool)$seat['ready']));
            if (count($memberships) < $minimum || $readyCount !== count($memberships)) {
                throw new GameServiceException('playersNotReady', 'All required players must be ready.', (int)$game['version']);
            }
            $version = (int)$game['version'] + 1;
            $this->connection->prepare(
                'UPDATE games SET lifecycle = :lifecycle, version = :version, updated_at = :updated WHERE id = :id'
            )->execute(['lifecycle' => 'draft', 'version' => $version, 'updated' => self::now(), 'id' => $gameId]);
            $projection = $this->projection($gameId, $identityId);
            $this->appendEvent($gameId, $version, 'gameStarted', $identityId, ['lifecycle' => 'draft']);
            $this->writeSnapshot($gameId, $version, $projection);
            $this->connection->commit();
            return $projection;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function submitCommand(string $gameId, string $identityId, array $command): array
    {
        foreach (['commandId', 'expectedVersion', 'kind', 'payload'] as $required) {
            if (!array_key_exists($required, $command)) {
                throw new GameServiceException('invalidCommand', "Missing command field {$required}.");
            }
        }

        $this->connection->beginTransaction();
        try {
            $receipt = $this->connection->prepare(
                'SELECT response_json FROM command_receipts
                 WHERE game_id = :game AND command_id = :command AND identity_id = :identity'
            );
            $receipt->execute(['game' => $gameId, 'command' => $command['commandId'], 'identity' => $identityId]);
            $cached = $receipt->fetchColumn();
            if ($cached !== false) {
                $this->connection->commit();
                return json_decode($cached, true, flags: JSON_THROW_ON_ERROR);
            }

            $game = $this->gameRow($gameId);
            $currentVersion = (int)$game['version'];
            if ((int)$command['expectedVersion'] !== $currentVersion) {
                throw new GameServiceException(
                    'versionConflict',
                    'The game changed before this command arrived.',
                    $currentVersion,
                    'Refresh the game projection and retry a still-legal action with a new command ID.'
                );
            }
            $this->requireMembership($gameId, $identityId);
            if ($command['kind'] !== 'setReady' || !is_bool($command['payload']['ready'] ?? null)) {
                throw new GameServiceException('unsupportedCommand', 'This command is not legal in the current game phase.', $currentVersion);
            }
            if ($game['lifecycle'] !== 'lobby') {
                throw new GameServiceException('illegalLifecycle', 'Readiness can only change in the lobby.', $currentVersion);
            }

            $this->connection->prepare(
                'UPDATE game_memberships SET ready = :ready WHERE game_id = :game AND identity_id = :identity'
            )->execute(['ready' => $command['payload']['ready'] ? 1 : 0, 'game' => $gameId, 'identity' => $identityId]);
            $newVersion = $currentVersion + 1;
            $this->connection->prepare('UPDATE games SET version = :version, updated_at = :updated WHERE id = :id')
                ->execute(['version' => $newVersion, 'updated' => self::now(), 'id' => $gameId]);
            $this->appendEvent($gameId, $newVersion, 'readinessChanged', $identityId, ['ready' => $command['payload']['ready']]);
            $response = $this->projection($gameId, $identityId);
            $this->writeSnapshot($gameId, $newVersion, $response);
            $this->connection->prepare(
                'INSERT INTO command_receipts (game_id, command_id, identity_id, response_json, created_at)
                 VALUES (:game, :command, :identity, :response, :created)'
            )->execute([
                'game' => $gameId, 'command' => $command['commandId'], 'identity' => $identityId,
                'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created' => self::now(),
            ]);
            $this->connection->commit();
            return $response;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function getGame(string $gameId, string $identityId): array
    {
        $this->requireMembership($gameId, $identityId);
        return $this->projection($gameId, $identityId);
    }

    public function getEvents(string $gameId, string $identityId, int $afterSequence): array
    {
        $this->requireMembership($gameId, $identityId);
        $statement = $this->connection->prepare(
            'SELECT sequence, version, kind, acting_identity_id, focus_hexes_json, payload_json, created_at
             FROM game_events WHERE game_id = :game AND sequence > :after ORDER BY sequence LIMIT 200'
        );
        $statement->execute(['game' => $gameId, 'after' => $afterSequence]);
        $events = array_map(static fn(array $event): array => [
            'sequence' => (int)$event['sequence'],
            'version' => (int)$event['version'],
            'kind' => $event['kind'],
            'actingSeat' => $event['acting_identity_id'],
            'focusHexes' => json_decode($event['focus_hexes_json'], true, flags: JSON_THROW_ON_ERROR),
            'payload' => json_decode($event['payload_json'], true, flags: JSON_THROW_ON_ERROR),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', strtotime($event['created_at'])),
        ], $statement->fetchAll());
        $lastSequence = $afterSequence;
        if ($events !== []) {
            $lastSequence = $events[array_key_last($events)]['sequence'];
        }
        return ['events' => $events, 'lastSequence' => $lastSequence];
    }

    public function transferIdentity(string $fromIdentityId, string $toIdentityId): void
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        try {
            $this->connection->prepare('UPDATE game_memberships SET identity_id = :to WHERE identity_id = :from')
                ->execute(['to' => $toIdentityId, 'from' => $fromIdentityId]);
            $this->connection->prepare('UPDATE games SET host_identity_id = :to WHERE host_identity_id = :from')
                ->execute(['to' => $toIdentityId, 'from' => $fromIdentityId]);
            $this->connection->prepare('UPDATE game_events SET acting_identity_id = :to WHERE acting_identity_id = :from')
                ->execute(['to' => $toIdentityId, 'from' => $fromIdentityId]);
            $this->connection->prepare('UPDATE command_receipts SET identity_id = :to WHERE identity_id = :from')
                ->execute(['to' => $toIdentityId, 'from' => $fromIdentityId]);
            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                $this->rollback();
            }
            throw $error;
        }
    }

    public function matchHistory(string $identityId): array
    {
        $statement = $this->connection->prepare(
            'SELECT g.id, g.mode, g.lifecycle, g.scenario_name, g.version, g.updated_at
             FROM games g INNER JOIN game_memberships m ON m.game_id = g.id
             WHERE m.identity_id = :identity ORDER BY g.updated_at DESC'
        );
        $statement->execute(['identity' => $identityId]);
        return array_map(static fn(array $game): array => [
            'gameId' => $game['id'],
            'mode' => $game['mode'],
            'lifecycle' => $game['lifecycle'],
            'scenarioName' => $game['scenario_name'],
            'version' => (int)$game['version'],
            'updatedAt' => $game['updated_at'],
        ], $statement->fetchAll());
    }

    public function createSpectatorLink(string $gameId, string $identityId): array
    {
        $game = $this->gameRow($gameId);
        if ($game['host_identity_id'] !== $identityId) {
            throw new GameServiceException('hostRequired', 'Only the host can manage spectator links.', (int)$game['version']);
        }
        $linkId = self::id();
        $token = self::token();
        $this->connection->prepare(
            'INSERT INTO spectator_links
             (id, game_id, token_hash, enabled, created_by_identity_id, created_at)
             VALUES (:id, :game, :token, 1, :identity, :created)'
        )->execute([
            'id' => $linkId,
            'game' => $gameId,
            'token' => hash('sha256', $token),
            'identity' => $identityId,
            'created' => self::now(),
        ]);
        return ['linkId' => $linkId, 'token' => $token];
    }

    public function getSpectatorGame(string $token): array
    {
        $statement = $this->connection->prepare(
            'SELECT game_id FROM spectator_links
             WHERE token_hash = :token AND enabled = 1 AND revoked_at IS NULL'
        );
        $statement->execute(['token' => hash('sha256', $token)]);
        $gameId = $statement->fetchColumn();
        if ($gameId === false) {
            throw new GameServiceException('spectatorLinkInvalid', 'This spectator link is invalid or has been revoked.');
        }
        $game = $this->gameRow($gameId);
        return [
            'gameId' => $gameId,
            'version' => (int)$game['version'],
            'lifecycle' => $game['lifecycle'],
            'mode' => $game['mode'],
            'scenarioName' => $game['scenario_name'],
            'viewer' => ['role' => 'spectator', 'seatId' => null, 'isHost' => false, 'canCommand' => false],
            'board' => ['columns' => 0, 'rows' => 0, 'hexes' => []],
            'turn' => ['round' => 0, 'activeSeatId' => null, 'activationsRemaining' => 0],
            'pendingChoice' => null,
            'memberships' => array_map(static fn(array $seat): array => [
                'seatId' => $seat['id'],
                'seatIndex' => (int)$seat['seat_index'],
                'ready' => (bool)$seat['ready'],
                'isHost' => (bool)$seat['is_host'],
            ], $this->memberships($gameId)),
            'legalActions' => [],
            'scores' => [],
            'result' => null,
        ];
    }

    public function revokeSpectatorLink(string $gameId, string $identityId, string $linkId): void
    {
        $game = $this->gameRow($gameId);
        if ($game['host_identity_id'] !== $identityId) {
            throw new GameServiceException('hostRequired', 'Only the host can manage spectator links.', (int)$game['version']);
        }
        $statement = $this->connection->prepare(
            'UPDATE spectator_links SET enabled = 0, revoked_at = :revoked WHERE id = :id AND game_id = :game'
        );
        $statement->execute(['revoked' => self::now(), 'id' => $linkId, 'game' => $gameId]);
    }

    private function projection(string $gameId, string $identityId): array
    {
        $game = $this->gameRow($gameId);
        $memberships = $this->memberships($gameId);
        $viewerSeat = null;
        $scores = [];
        foreach ($memberships as $membership) {
            $scores[$membership['id']] = 0;
            if ($membership['identity_id'] === $identityId) {
                $viewerSeat = $membership;
            }
        }
        return [
            'gameId' => $gameId,
            'version' => (int)$game['version'],
            'lifecycle' => $game['lifecycle'],
            'mode' => $game['mode'],
            'scenarioName' => $game['scenario_name'],
            'viewer' => [
                'role' => 'player',
                'seatId' => $viewerSeat['id'] ?? null,
                'isHost' => $game['host_identity_id'] === $identityId,
                'canCommand' => true,
            ],
            'board' => ['columns' => 0, 'rows' => 0, 'hexes' => []],
            'turn' => ['round' => 0, 'activeSeatId' => null, 'activationsRemaining' => 0],
            'pendingChoice' => null,
            'legalActions' => $game['lifecycle'] === 'lobby'
                ? [['kind' => 'setReady', 'unitId' => null, 'targetHexes' => []]]
                : [],
            'scores' => $scores,
            'result' => null,
            'memberships' => array_map(static fn(array $seat): array => [
                'seatId' => $seat['id'],
                'seatIndex' => (int)$seat['seat_index'],
                'ready' => (bool)$seat['ready'],
                'isHost' => (bool)$seat['is_host'],
            ], $memberships),
        ];
    }

    private function insertMembership(string $gameId, string $identityId, int $seatIndex, bool $isHost, string $seatToken): void
    {
        $this->connection->prepare(
            'INSERT INTO game_memberships
             (id, game_id, identity_id, seat_index, role, is_host, ready, seat_token_hash, joined_at)
             VALUES (:id, :game, :identity, :seat, :role, :host, 0, :token, :joined)'
        )->execute([
            'id' => self::id(), 'game' => $gameId, 'identity' => $identityId, 'seat' => $seatIndex,
            'role' => 'player', 'host' => $isHost ? 1 : 0, 'token' => hash('sha256', $seatToken), 'joined' => self::now(),
        ]);
    }

    private function memberships(string $gameId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, identity_id, seat_index, is_host, ready FROM game_memberships WHERE game_id = :game ORDER BY seat_index'
        );
        $statement->execute(['game' => $gameId]);
        return $statement->fetchAll();
    }

    private function requireMembership(string $gameId, string $identityId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM game_memberships WHERE game_id = :game AND identity_id = :identity'
        );
        $statement->execute(['game' => $gameId, 'identity' => $identityId]);
        $membership = $statement->fetch();
        if (!$membership) {
            throw new GameServiceException('membershipRequired', 'This identity does not control a seat in the game.');
        }
        return $membership;
    }

    private function gameRow(string $gameId): array
    {
        $statement = $this->connection->prepare('SELECT * FROM games WHERE id = :id');
        $statement->execute(['id' => $gameId]);
        $game = $statement->fetch();
        if (!$game) {
            throw new GameServiceException('gameNotFound', 'The requested game does not exist.');
        }
        return $game;
    }

    private function appendEvent(string $gameId, int $version, string $kind, string $actor, array $payload): void
    {
        $sequence = (int)$this->scalar(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM game_events WHERE game_id = :game',
            ['game' => $gameId]
        );
        $this->connection->prepare(
            'INSERT INTO game_events
             (game_id, sequence, version, kind, acting_identity_id, focus_hexes_json, payload_json, created_at)
             VALUES (:game, :sequence, :version, :kind, :actor, :focus, :payload, :created)'
        )->execute([
            'game' => $gameId, 'sequence' => $sequence, 'version' => $version, 'kind' => $kind,
            'actor' => $actor, 'focus' => '[]', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created' => self::now(),
        ]);
    }

    private function writeSnapshot(string $gameId, int $version, array $projection): void
    {
        $sql = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO game_snapshots (game_id, version, state_json, updated_at) VALUES (:game, :version, :state, :updated)
               ON DUPLICATE KEY UPDATE version = VALUES(version), state_json = VALUES(state_json), updated_at = VALUES(updated_at)'
            : 'INSERT INTO game_snapshots (game_id, version, state_json, updated_at) VALUES (:game, :version, :state, :updated)
               ON CONFLICT(game_id) DO UPDATE SET version = excluded.version, state_json = excluded.state_json, updated_at = excluded.updated_at';
        $this->connection->prepare($sql)->execute([
            'game' => $gameId, 'version' => $version,
            'state' => json_encode($projection, JSON_THROW_ON_ERROR), 'updated' => self::now(),
        ]);
    }

    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    private function rollback(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    private static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
