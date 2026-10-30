<?php
declare(strict_types=1);

namespace BloodAndBanners\Game;

use BloodAndBanners\Account\FriendService;
use BloodAndBanners\Ai\CommandCandidates;
use BloodAndBanners\Ai\HordeBuilder;
use BloodAndBanners\Ai\Personalities;
use BloodAndBanners\Ai\Personality;
use BloodAndBanners\Ai\TurnPlanner;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\SecureRandomSource;
use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Abilities\SpecialAttack;
use BloodAndBanners\Rules\Vanilla\BattleRules;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Effects;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Modes\CoopMode;
use BloodAndBanners\Rules\Vanilla\Modes\CoopSetup;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\UnitConditions;
use BloodAndBanners\Scenario\MapModeCheck;
use BloodAndBanners\Scenario\ScenarioService;
use DomainException;
use PDO;
use Throwable;

final class GameService
{
    private readonly ScenarioService $scenarios;
    private readonly RandomSource $random;
    private readonly MatchEngine $engine;
    /** @var \Closure(): int milliseconds, injectable so AI pacing can be tested */
    private readonly \Closure $clockMs;

    public function __construct(
        private readonly PDO $connection,
        ?ScenarioService $scenarios = null,
        ?RandomSource $random = null,
        ?MatchEngine $engine = null,
        ?\Closure $clockMs = null,
        /** Test seam: fn(MatchEngine, Personality, int $nowSeconds, int $deadlineMs, \Closure $clockMs): object with next() */
        private readonly ?\Closure $plannerFactory = null,
    ) {
        $this->clockMs = $clockMs ?? static fn(): int => (int)floor(microtime(true) * 1000);
        $this->scenarios = $scenarios ?? new ScenarioService($connection);
        $this->random = $random ?? new SecureRandomSource();
        $this->engine = $engine ?? new MatchEngine(new Catalog());
    }

    /** Opens a war room on a built-in scenario, or on a revision of the host's own map when one is given. */
    public function createGame(string $hostIdentityId, string $mode, string $scenarioName, ?string $scenarioRevisionId = null): array
    {
        if (!in_array($mode, ['vanillaPvp', 'coop', 'battleRoyale'], true)) {
            throw new GameServiceException('invalidMode', 'The requested game mode is not supported.');
        }
        $this->connection->beginTransaction();
        try {
            try {
                $seatLimit = null;
                if ($scenarioRevisionId !== null) {
                    $scenario = $this->scenarios->resolveOwnedRevision($scenarioRevisionId, $hostIdentityId, $mode);
                    $scenarioName = (string)$scenario['definition']['name'];
                    $seatLimit = self::ownMapSeats($scenario['definition'], $mode);
                } else {
                    $scenario = $this->scenarios->resolveBuiltInRevision($scenarioName, $mode);
                }
            } catch (DomainException $error) {
                $message = $error->getMessage() === 'scenarioModeIncompatible'
                    ? 'The selected scenario does not support this game mode.'
                    : 'The selected scenario could not be found.';
                throw new GameServiceException($error->getMessage(), $message);
            }
            $gameId = self::id();
            $seatToken = self::token();
            $now = self::now();
            $this->connection->prepare(
                'INSERT INTO games (id, mode, lifecycle, scenario_revision_id, scenario_name, seat_limit, host_identity_id, version, created_at, updated_at)
                 VALUES (:id, :mode, :lifecycle, :revision, :scenario, :seats, :host, 1, :created, :updated)'
            )->execute([
                'id' => $gameId, 'mode' => $mode, 'lifecycle' => 'lobby', 'scenario' => $scenarioName, 'seats' => $seatLimit,
                'revision' => $scenario['revisionId'], 'host' => $hostIdentityId, 'created' => $now, 'updated' => $now,
            ]);
            $this->insertMembership($gameId, $hostIdentityId, 1, true, $seatToken);
            $this->presetCommanderName($gameId, $hostIdentityId);
            if ($mode === 'coop') {
                // The host usually runs the Void Lord, so they start in that seat and can release it.
                $this->connection->prepare("UPDATE game_memberships SET role = 'voidLord' WHERE game_id = :game")
                    ->execute(['game' => $gameId]);
                $this->syncLobbySetup($this->gameRow($gameId), 1);
            }
            $projection = $this->projection($gameId, $hostIdentityId);
            $this->writeSnapshot($gameId, 1, $projection);
            $this->connection->commit();
            return $projection + ['seatToken' => $seatToken];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** Player seats in a game: the mode's usual number, or fewer when the game's own map was found to hold fewer. */
    public static function seatLimit(string $mode, mixed $stored = null): int
    {
        $usual = $mode === 'vanillaPvp' ? 2 : 5;
        return $stored === null ? $usual : min($usual, (int)$stored);
    }

    /** Seats a player's own map holds: its players, plus the Void Lord or the dungeon master outside a duel. */
    private static function ownMapSeats(array $definition, string $mode): int
    {
        $players = MapModeCheck::players($definition, $mode);
        return $mode === 'vanillaPvp' ? $players : $players + 1;
    }

    /** Gives a signed-in member their seat on this device: a fresh seat token replaces the old one everywhere. */
    public function resumeSeat(string $gameId, string $identityId): array
    {
        $this->gameRow($gameId);
        $seatToken = self::token();
        $statement = $this->connection->prepare(
            'UPDATE game_memberships SET seat_token_hash = :hash WHERE game_id = :game AND identity_id = :identity'
        );
        $statement->execute(['hash' => hash('sha256', $seatToken), 'game' => $gameId, 'identity' => $identityId]);
        if ($statement->rowCount() !== 1) {
            throw new GameServiceException('gameNotFound', 'You do not hold a seat in that game.');
        }
        return $this->projection($gameId, $identityId) + ['seatToken' => $seatToken];
    }

    /** A signed-in commander's seat starts with their display name, unless another seat already uses it. */
    private function presetCommanderName(string $gameId, string $identityId): void
    {
        $statement = $this->connection->prepare("SELECT display_name FROM users WHERE id = :id AND display_name <> ''");
        $statement->execute(['id' => $identityId]);
        $name = $statement->fetchColumn();
        if (!is_string($name)) {
            return;
        }
        foreach ($this->memberships($gameId) as $seat) {
            if ($seat['commander_name'] !== null && preg_match('/^' . preg_quote((string)$seat['commander_name'], '/') . '$/iu', $name) === 1) {
                return;
            }
        }
        $this->connection->prepare(
            'UPDATE game_memberships SET commander_name = :name WHERE game_id = :game AND identity_id = :identity AND commander_name IS NULL'
        )->execute(['name' => $name, 'game' => $gameId, 'identity' => $identityId]);
    }

    public function joinGame(string $gameId, string $identityId): array
    {
        if ((int)$this->scalar('SELECT COUNT(*) FROM game_memberships WHERE game_id = :game AND identity_id = :identity', ['game' => $gameId, 'identity' => $identityId]) > 0) {
            return $this->resumeSeat($gameId, $identityId);
        }
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            if ($game['lifecycle'] !== 'lobby') {
                throw new GameServiceException('gameAlreadyStarted', 'Only lobby games can be joined.', (int)$game['version']);
            }
            $count = (int)$this->scalar('SELECT COUNT(*) FROM game_memberships WHERE game_id = :game', ['game' => $gameId]);
            $maximum = self::seatLimit($game['mode'], $game['seat_limit'] ?? null);
            if ($count >= $maximum) {
                throw new GameServiceException('gameFull', 'This game has no open player seats.', (int)$game['version']);
            }
            $taken = array_map('intval', array_column($this->memberships($gameId), 'seat_index'));
            $seatIndex = 1;
            while (in_array($seatIndex, $taken, true)) {
                $seatIndex++;
            }
            $seatToken = self::token();
            $this->insertMembership($gameId, $identityId, $seatIndex, false, $seatToken);
            $this->presetCommanderName($gameId, $identityId);
            $version = (int)$game['version'] + 1;
            $this->bumpVersion($gameId, (int)$game['version'], $version);
            $this->syncLobbySetup($game, $version);
            $this->appendEvent($gameId, $version, 'playerJoined', $identityId, ['seatIndex' => $seatIndex]);
            $projection = $this->projection($gameId, $identityId);
            $this->writeSnapshot($gameId, $version, $projection);
            $this->connection->commit();
            return $projection + ['seatToken' => $seatToken];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** Gives up a lobby seat. The host role passes to the lowest remaining seat; an emptied room is deleted. */
    public function leaveGame(string $gameId, string $identityId, string $seatToken): void
    {
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            $membership = $this->requireSeatCredential($gameId, $identityId, $seatToken);
            $this->vacateLobbySeat($game, $membership, 'playerLeft', $identityId);
            $this->connection->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function removeSeat(string $gameId, string $hostIdentityId, string $hostSeatToken, string $seatId): array
    {
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            $host = $this->requireSeatCredential($gameId, $hostIdentityId, $hostSeatToken);
            if ($game['host_identity_id'] !== $hostIdentityId) {
                throw new GameServiceException('hostRequired', 'Only the host can remove a commander.', (int)$game['version']);
            }
            if ((string)$host['id'] === $seatId) {
                throw new GameServiceException('cannotRemoveSelf', 'Leave the war room instead of removing your own seat.', (int)$game['version']);
            }
            $target = null;
            foreach ($this->memberships($gameId) as $seat) {
                if ((string)$seat['id'] === $seatId) {
                    $target = $seat;
                }
            }
            if ($target === null) {
                throw new GameServiceException('seatNotFound', 'The requested seat does not exist.', (int)$game['version']);
            }
            $this->vacateLobbySeat($game, $target, 'playerRemoved', $hostIdentityId);
            $projection = $this->projection($gameId, $hostIdentityId);
            $this->connection->commit();
            return $projection;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function vacateLobbySeat(array $game, array $seat, string $eventKind, string $actor): void
    {
        $currentVersion = (int)$game['version'];
        if ($game['lifecycle'] !== 'lobby') {
            throw new GameServiceException('illegalLifecycle', 'Seats can only be given up in the war room.', $currentVersion);
        }
        $this->connection->prepare('DELETE FROM game_memberships WHERE game_id = :game AND id = :seat')
            ->execute(['game' => $game['id'], 'seat' => $seat['id']]);
        $remaining = $this->memberships($game['id']);
        $humans = array_values(array_filter($remaining, static fn(array $row): bool => $row['ai_personality'] === null));
        if ($humans === []) {
            $this->connection->prepare('DELETE FROM games WHERE id = :id')->execute(['id' => $game['id']]);
            return;
        }
        if ((bool)$seat['is_host']) {
            $heir = $humans[0];
            $this->connection->prepare('UPDATE game_memberships SET is_host = 1 WHERE game_id = :game AND id = :seat')
                ->execute(['game' => $game['id'], 'seat' => $heir['id']]);
            $this->connection->prepare('UPDATE games SET host_identity_id = :identity WHERE id = :game')
                ->execute(['identity' => $heir['identity_id'], 'game' => $game['id']]);
        }
        $newVersion = $currentVersion + 1;
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
        $this->syncLobbySetup($game, $newVersion);
        $this->appendEvent($game['id'], $newVersion, $eventKind, $actor, ['seatId' => (string)$seat['id'], 'seatIndex' => (int)$seat['seat_index']]);
        $this->writeSnapshot($game['id'], $newVersion, $this->spectatorProjection($this->gameRow($game['id'])));
    }

    public function setReady(string $gameId, string $identityId, string $seatToken, bool $ready): array
    {
        $game = $this->gameRow($gameId);
        return $this->submitCommand($gameId, $identityId, $seatToken, [
            'commandId' => self::id(),
            'expectedVersion' => (int)$game['version'],
            'kind' => 'setReady',
            'payload' => ['ready' => $ready],
        ]);
    }

    public function startGame(string $gameId, string $identityId, string $seatToken): array
    {
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            $this->requireSeatCredential($gameId, $identityId, $seatToken);
            if ($game['host_identity_id'] !== $identityId) {
                throw new GameServiceException('hostRequired', 'Only the host can start the match.', (int)$game['version']);
            }
            $memberships = $this->memberships($gameId);
            $minimum = 2;
            $readyCount = count(array_filter($memberships, static fn(array $seat): bool => (bool)$seat['ready']));
            if (count($memberships) < $minimum || $readyCount !== count($memberships)) {
                throw new GameServiceException('playersNotReady', 'All required players must be ready.', (int)$game['version']);
            }
            if ($game['mode'] === 'battleRoyale') {
                throw new GameServiceException('modeNotPlayable', 'Battle Royale cannot be played in this version yet.', (int)$game['version']);
            }
            $definition = $this->scenarioDefinition($game);
            $options = [];
            if ($game['mode'] === 'coop') {
                $voidLords = array_values(array_filter($memberships, static fn(array $seat): bool => $seat['role'] === 'voidLord'));
                if (count($voidLords) !== 1) {
                    throw new GameServiceException('voidLordRequired', 'A Co-op match needs a Void Lord.', (int)$game['version']);
                }
                $options = ['voidLordSeatId' => (string)$voidLords[0]['id'], 'coop' => $definition['coop'] ?? [], 'setupState' => $this->loadState($gameId)];
            }
            try {
                $started = $this->engine->start(
                    array_column($memberships, 'id'),
                    Board::fromScenario($definition),
                    $this->random,
                    (string)$game['mode'],
                    $options
                );
            } catch (GameServiceException $error) {
                throw new GameServiceException($error->errorCode, $error->getMessage(), (int)$game['version']);
            }
            $aiSeat = $game['mode'] === 'coop' ? $this->aiSeat($memberships) : null;
            $aiEvents = [];
            // Trade-off: if the AI horde cannot be applied, the whole Start rolls back; there is no fallback horde.
            if ($aiSeat !== null) {
                [$started['state'], $aiEvents] = $this->buildAiHorde($started['state'], $aiSeat, $gameId, count($memberships) - 1);
            }
            $version = (int)$game['version'] + 1;
            $this->connection->prepare(
                'UPDATE games SET lifecycle = :lifecycle, version = :version, updated_at = :updated, ai_stepped_at = :stepped WHERE id = :id'
            )->execute([
                'lifecycle' => self::lifecycleOf($started['state']), 'version' => $version, 'updated' => self::now(), 'id' => $gameId,
                'stepped' => $aiSeat === null ? null : ($this->clockMs)(),
            ]);
            $this->saveState($gameId, $version, $started['state']);
            $projection = $this->projection($gameId, $identityId);
            $this->appendEvent($gameId, $version, 'gameStarted', $identityId, ['lifecycle' => self::lifecycleOf($started['state'])]);
            foreach ($started['events'] as $event) {
                $this->appendEvent($gameId, $version, $event['kind'], $identityId, $event['payload'], $event['focusHexes']);
            }
            if ($aiSeat !== null) {
                $this->appendAiEvents($gameId, $version, $aiSeat, $aiEvents);
            }
            $this->writeSnapshot($gameId, $version, $projection);
            $this->connection->commit();
            return $projection;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function submitCommand(string $gameId, string $identityId, string $seatToken, array $command): array
    {
        foreach (['commandId', 'expectedVersion', 'kind', 'payload'] as $required) {
            if (!array_key_exists($required, $command)) {
                throw new GameServiceException('invalidCommand', "Missing command field {$required}.");
            }
        }

        $this->connection->beginTransaction();
        try {
            $membership = $this->requireSeatCredential($gameId, $identityId, $seatToken);
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
            if ((int)$command['expectedVersion'] !== $currentVersion && !$this->skipsVersionCheck($game, (string)$command['kind'])) {
                throw self::versionConflict($currentVersion);
            }
            $newVersion = $currentVersion + 1;
            if (in_array($command['kind'], self::LOBBY_COMMANDS, true)) {
                $this->applyLobbyCommand($game, $membership, $identityId, $command, $newVersion);
            } elseif ($game['lifecycle'] === 'lobby' && in_array($command['kind'], CoopSetup::KINDS, true)) {
                $this->applyLobbySetupCommand($game, (string)$membership['id'], $identityId, $command, $newVersion);
            } else {
                $this->applyMatchCommand($game, (string)$membership['id'], $identityId, $command, $newVersion);
            }
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

    /** Preparation events everyone may see: they only report that a side is finished. */
    private const PUBLIC_PREP_EVENTS = ['setupConfirmed', 'armiesPlaced'];
    private const LOBBY_COMMANDS = ['setReady', 'claimVoidLord', 'releaseVoidLord', 'assignVoidLord', 'setCommanderName', 'seatAiVoidLord', 'setAiPace'];
    private const AI_PACES = ['slow', 'normal', 'fast'];

    /** Co-op preparation orders from the two sides touch disjoint state, so a stale expectedVersion from the other side's bump is tolerated; the rules re-validate against current state. */
    private const SIMULTANEOUS_PREP_COMMANDS = ['placeMonster', 'removeMonster', 'confirmSetup', 'pickHero', 'pickArmy'];

    private function skipsVersionCheck(array $game, string $kind): bool
    {
        if ($kind === 'setAiPace') {
            return true;
        }
        return $game['mode'] === 'coop'
            && in_array($game['lifecycle'], ['lobby', 'draft'], true)
            && in_array($kind, self::SIMULTANEOUS_PREP_COMMANDS, true);
    }

    private const NAMING_LIFECYCLES = ['lobby', 'setup', 'draft'];

    private function applyLobbyCommand(array $game, array $membership, string $identityId, array $command, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        $kind = (string)$command['kind'];
        $payload = is_array($command['payload']) ? $command['payload'] : [];
        if ($kind === 'setCommanderName') {
            $this->setCommanderName($game, $membership, $payload, $newVersion);
            return;
        }
        if ($kind === 'setAiPace') {
            $this->setAiPace($game, $payload, $identityId, $newVersion);
            return;
        }
        if ($kind === 'setReady') {
            if (!is_bool($payload['ready'] ?? null)) {
                throw new GameServiceException('unsupportedCommand', 'This command is not legal in the current game phase.', $currentVersion);
            }
            if ($game['lifecycle'] !== 'lobby') {
                throw new GameServiceException('illegalLifecycle', 'Readiness can only change in the lobby.', $currentVersion);
            }
            $this->connection->prepare(
                'UPDATE game_memberships SET ready = :ready WHERE game_id = :game AND identity_id = :identity'
            )->execute(['ready' => $payload['ready'] ? 1 : 0, 'game' => $game['id'], 'identity' => $identityId]);
            $this->bumpVersion($game['id'], $currentVersion, $newVersion);
            $this->appendEvent($game['id'], $newVersion, 'readinessChanged', $identityId, ['ready' => $payload['ready']]);
            return;
        }
        if ($kind === 'seatAiVoidLord' && $game['mode'] === 'coop') {
            $this->seatAiVoidLord($game, $this->memberships($game['id']), $payload, $identityId, $newVersion);
            return;
        }
        if ($game['mode'] !== 'coop' || $game['lifecycle'] !== 'lobby') {
            throw new GameServiceException('unsupportedCommand', 'This command is not legal in the current game phase.', $currentVersion);
        }
        $memberships = $this->memberships($game['id']);
        $current = null;
        foreach ($memberships as $seat) {
            if ($seat['role'] === 'voidLord') {
                $current = (string)$seat['id'];
            }
        }
        $aiSeat = $this->aiSeat($memberships);
        if ($kind === 'claimVoidLord') {
            if ($current === (string)$membership['id']) {
                throw new GameServiceException('voidLordTaken', 'You are already the Void Lord.', $currentVersion);
            }
            if ($current !== null) {
                throw new GameServiceException('voidLordTaken', 'Another commander is already the Void Lord.', $currentVersion);
            }
            $target = (string)$membership['id'];
        } elseif ($kind === 'releaseVoidLord') {
            if ($current !== (string)$membership['id']) {
                throw new GameServiceException('notVoidLord', 'You are not the Void Lord.', $currentVersion);
            }
            $target = null;
        } else {
            if ($game['host_identity_id'] !== $identityId) {
                throw new GameServiceException('hostRequired', 'Only the host can choose the Void Lord.', $currentVersion);
            }
            $target = $payload['seatId'] ?? null;
            $humanIds = array_map('strval', array_column(array_filter($memberships, static fn(array $seat): bool => $seat['ai_personality'] === null), 'id'));
            if (!is_string($target) || !in_array($target, $humanIds, true)) {
                throw new GameServiceException('seatNotFound', 'The requested seat does not exist.', $currentVersion);
            }
            if ($aiSeat !== null) {
                $this->connection->prepare('DELETE FROM game_memberships WHERE game_id = :game AND id = :seat')
                    ->execute(['game' => $game['id'], 'seat' => $aiSeat['id']]);
            }
        }
        $this->connection->prepare("UPDATE game_memberships SET role = 'player', ready = 0 WHERE game_id = :game")
            ->execute(['game' => $game['id']]);
        $this->connection->prepare('UPDATE game_memberships SET ready = 1 WHERE game_id = :game AND ai_personality IS NOT NULL')
            ->execute(['game' => $game['id']]);
        if ($target !== null) {
            $this->connection->prepare("UPDATE game_memberships SET role = 'voidLord' WHERE game_id = :game AND id = :seat")
                ->execute(['game' => $game['id'], 'seat' => $target]);
        }
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
        $this->syncLobbySetup($game, $newVersion);
        $this->appendEvent($game['id'], $newVersion, 'voidLordChanged', $identityId, ['seatId' => $target]);
    }

    /** @return array|null the AI Void Lord's membership row */
    private function aiSeat(array $memberships): ?array
    {
        foreach ($memberships as $seat) {
            if ($seat['ai_personality'] !== null) {
                return $seat;
            }
        }
        return null;
    }

    /** Seats (or retunes) the AI Void Lord: the host plays a hero, and any human horde is discarded. */
    private function seatAiVoidLord(array $game, array $memberships, array $payload, string $identityId, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        if ($game['host_identity_id'] !== $identityId) {
            throw new GameServiceException('hostRequired', 'Only the host can seat an AI Void Lord.', $currentVersion);
        }
        if ($game['lifecycle'] !== 'lobby') {
            throw new GameServiceException('illegalLifecycle', 'The AI Void Lord can only be seated in the war room.', $currentVersion);
        }
        $personality = is_string($payload['personality'] ?? null) ? (Personalities::all()[$payload['personality']] ?? null) : null;
        if ($personality === null) {
            throw new GameServiceException('unknownPersonality', 'That Void Lord personality does not exist.', $currentVersion);
        }
        $existing = $this->aiSeat($memberships);
        if ($existing !== null) {
            $this->connection->prepare('UPDATE game_memberships SET ai_personality = :personality, commander_name = :name WHERE game_id = :game AND id = :seat')
                ->execute(['personality' => $personality->id, 'name' => $personality->name, 'game' => $game['id'], 'seat' => $existing['id']]);
            $seatId = (string)$existing['id'];
        } else {
            if (count($memberships) >= self::seatLimit((string)$game['mode'], $game['seat_limit'] ?? null)) {
                throw new GameServiceException('gameFull', 'This game has no open player seats.', $currentVersion);
            }
            $this->connection->prepare("UPDATE game_memberships SET role = 'player', ready = 0 WHERE game_id = :game")
                ->execute(['game' => $game['id']]);
            $this->connection->prepare('DELETE FROM game_states WHERE game_id = :game')->execute(['game' => $game['id']]);
            $taken = array_map('intval', array_column($memberships, 'seat_index'));
            $seatIndex = 1;
            while (in_array($seatIndex, $taken, true)) {
                $seatIndex++;
            }
            $seatId = self::id();
            $this->connection->prepare(
                "INSERT INTO game_memberships
                 (id, game_id, identity_id, seat_index, role, is_host, ready, seat_token_hash, joined_at, commander_name, ai_personality)
                 VALUES (:id, :game, :identity, :seat, 'voidLord', 0, 1, :token, :joined, :name, :personality)"
            )->execute([
                'id' => $seatId, 'game' => $game['id'], 'identity' => 'ai' . bin2hex(random_bytes(15)), 'seat' => $seatIndex,
                'token' => hash('sha256', self::token()), 'joined' => self::now(), 'name' => $personality->name, 'personality' => $personality->id,
            ]);
            $this->connection->prepare("UPDATE games SET ai_pace = 'normal' WHERE id = :game AND ai_pace IS NULL")->execute(['game' => $game['id']]);
        }
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
        $this->syncLobbySetup($game, $newVersion);
        $this->appendEvent($game['id'], $newVersion, 'voidLordChanged', $identityId, ['seatId' => $seatId, 'aiPersonality' => $personality->id]);
    }

    /** Game metadata, not game state: the host sets how fast the AI steps; no rules engine, no event. */
    private function setAiPace(array $game, array $payload, string $identityId, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        if ($game['host_identity_id'] !== $identityId) {
            throw new GameServiceException('hostRequired', 'Only the host sets the AI pace.', $currentVersion);
        }
        if ($game['lifecycle'] === 'completed') {
            throw new GameServiceException('illegalLifecycle', 'The match is over.', $currentVersion);
        }
        if ($this->aiSeat($this->memberships($game['id'])) === null) {
            throw new GameServiceException('noAiSeat', 'There is no AI Void Lord in this game.', $currentVersion);
        }
        $pace = $payload['pace'] ?? null;
        if (!is_string($pace) || !in_array($pace, self::AI_PACES, true)) {
            throw new GameServiceException('invalidPace', 'The AI pace must be slow, normal or fast.', $currentVersion);
        }
        $this->connection->prepare('UPDATE games SET ai_pace = :pace WHERE id = :game')->execute(['pace' => $pace, 'game' => $game['id']]);
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
    }

    /**
     * The AI Void Lord's horde, planned from the board and the legal placements alone (never the draft) and
     * applied through the same engine commands a human would send.
     * @return array{0: array, 1: list<array>} the confirmed prep state and its events
     */
    private function buildAiHorde(array $state, array $aiSeat, string $gameId, int $heroPlayers): array
    {
        $seatId = (string)$aiSeat['id'];
        $placeable = [];
        foreach ($this->engine->legalActions($state, $seatId) as $action) {
            if ($action['kind'] === 'placeMonster') {
                $placeable[(string)$action['monster']] = $action['targetHexes'];
            }
        }
        $plan = (new HordeBuilder($this->engine->catalog()))->plan(
            $state['board'], $placeable, (int)$state['setup']['budget'], $heroPlayers,
            Personalities::get((string)$aiSeat['ai_personality']), crc32($gameId)
        );
        $events = [];
        foreach ($plan as $placement) {
            $result = $this->engine->apply($state, $seatId, 'placeMonster', $placement, $this->random);
            $state = $result['state'];
            array_push($events, ...$result['events']);
        }
        $result = $this->engine->apply($state, $seatId, 'confirmSetup', [], $this->random);
        array_push($events, ...$result['events']);
        return [$result['state'], $events];
    }

    /** Seat metadata, not game state: no rules engine, no event, and locked once battle begins. */
    private function setCommanderName(array $game, array $membership, array $payload, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        $raw = $payload['name'] ?? null;
        $collapsed = is_string($raw) ? preg_replace('/\s+/u', ' ', $raw) : null;
        if ($collapsed === null) {
            throw new GameServiceException('unsupportedCommand', 'Commander names must be text.', $currentVersion);
        }
        if (!in_array($game['lifecycle'], self::NAMING_LIFECYCLES, true)) {
            throw new GameServiceException('illegalLifecycle', 'Commander names are locked once battle begins.', $currentVersion);
        }
        $name = trim((string)preg_replace('/[\p{Cc}\p{Cf}]/u', '', $collapsed));
        $name = $name === '' ? null : $name;
        if ($name !== null && preg_match_all('/./su', $name) > 24) {
            throw new GameServiceException('nameTooLong', 'Names can be at most 24 characters.', $currentVersion);
        }
        if ($name !== null) {
            foreach ($this->memberships($game['id']) as $seat) {
                if ((string)$seat['id'] !== (string)$membership['id']
                    && $seat['commander_name'] !== null
                    && preg_match('/^' . preg_quote((string)$seat['commander_name'], '/') . '$/iu', $name) === 1) {
                    throw new GameServiceException('nameTaken', 'Another commander already uses that name.', $currentVersion);
                }
            }
        }
        $this->connection->prepare('UPDATE game_memberships SET commander_name = :name WHERE game_id = :game AND id = :seat')
            ->execute(['name' => $name, 'game' => $game['id'], 'seat' => $membership['id']]);
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
    }

    private function bumpVersion(string $gameId, int $currentVersion, int $newVersion): void
    {
        $bump = $this->connection->prepare(
            'UPDATE games SET version = :version, updated_at = :updated WHERE id = :id AND version = :expected'
        );
        $bump->execute(['version' => $newVersion, 'updated' => self::now(), 'id' => $gameId, 'expected' => $currentVersion]);
        if ($bump->rowCount() === 0) {
            throw self::versionConflict($currentVersion);
        }
    }

    /** Keeps the Void Lord's lobby board in step with the war room: created on a claim, budget on every roster change. */
    private function syncLobbySetup(array $game, int $version): void
    {
        if ($game['mode'] !== 'coop' || $game['lifecycle'] !== 'lobby') {
            return;
        }
        $memberships = $this->memberships($game['id']);
        $voidLord = null;
        foreach ($memberships as $seat) {
            if ($seat['role'] === 'voidLord') {
                $voidLord = (string)$seat['id'];
            }
        }
        if ($voidLord === null) {
            $this->connection->prepare('DELETE FROM game_states WHERE game_id = :game')->execute(['game' => $game['id']]);
            return;
        }
        $definition = $this->scenarioDefinition($game);
        $budget = CoopMode::budget($definition['coop'] ?? [], count($memberships) - 1);
        $state = $this->loadState($game['id']);
        $state = $state === null
            ? CoopMode::lobbySetup(Board::fromScenario($definition), $voidLord, $budget, $this->engine->catalog())
            : CoopMode::withVoidPortal(CoopMode::handOver($state, $voidLord), $this->engine->catalog());
        $state['setup']['budget'] = $budget;
        $state['setup']['cap'] = CoopMode::budget($definition['coop'] ?? [], CoopMode::MAX_HERO_PLAYERS);
        $this->saveState($game['id'], $version, $state);
    }

    /** Simultaneous preparation is shown to clients as the draft. */
    private static function lifecycleOf(array $state): string
    {
        return $state['phase'] === 'prep' ? 'draft' : (string)$state['phase'];
    }

    private function applyLobbySetupCommand(array $game, string $seatId, string $identityId, array $command, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        $state = $game['mode'] === 'coop' ? $this->loadState($game['id']) : null;
        if ($state === null) {
            throw new GameServiceException('unsupportedCommand', 'This command is not legal in the current game phase.', $currentVersion);
        }
        if ($state['voidLordSeatId'] !== $seatId) {
            throw new GameServiceException('notVoidLord', 'Only the Void Lord sets up the monsters.', $currentVersion);
        }
        if ($command['kind'] === 'confirmSetup') {
            throw new GameServiceException('rosterNotLocked', 'The Void Lord confirms once the host starts the match.', $currentVersion);
        }
        try {
            $result = (new CoopSetup($this->engine->catalog()))->apply(
                MatchEngine::normalize($state),
                $seatId,
                (string)$command['kind'],
                is_array($command['payload']) ? $command['payload'] : []
            );
        } catch (GameServiceException $error) {
            throw new GameServiceException($error->errorCode, $error->getMessage(), $currentVersion);
        }
        $this->bumpVersion($game['id'], $currentVersion, $newVersion);
        $this->saveState($game['id'], $newVersion, $result['state']);
        foreach ($result['events'] as $event) {
            $this->appendEvent($game['id'], $newVersion, $event['kind'], $identityId, $event['payload'], $event['focusHexes'], 'void');
        }
    }

    private function lobbyActions(array $game, array $memberships, array $viewer, ?array $lobbySetup): array
    {
        $actions = [['kind' => 'setReady', 'unitId' => null, 'targetHexes' => []], ['kind' => 'setCommanderName', 'unitId' => null, 'targetHexes' => []]];
        if ($game['mode'] !== 'coop') {
            return $actions;
        }
        $roles = array_column($memberships, 'role', 'id');
        $voidLord = array_search('voidLord', $roles, true);
        if ($voidLord === false) {
            $actions[] = ['kind' => 'claimVoidLord', 'unitId' => null, 'targetHexes' => []];
        } elseif ((string)$voidLord === $viewer['seatId']) {
            $actions[] = ['kind' => 'releaseVoidLord', 'unitId' => null, 'targetHexes' => []];
        }
        if ($viewer['isHost']) {
            $actions[] = ['kind' => 'assignVoidLord', 'unitId' => null, 'targetHexes' => []];
            $actions[] = ['kind' => 'seatAiVoidLord', 'unitId' => null, 'targetHexes' => []];
            if ($this->aiSeat($memberships) !== null) {
                $actions[] = ['kind' => 'setAiPace', 'unitId' => null, 'targetHexes' => []];
            }
        }
        if ($lobbySetup !== null && $lobbySetup['voidLordSeatId'] === $viewer['seatId']) {
            foreach ((new CoopSetup($this->engine->catalog()))->legalActions($lobbySetup, (string)$viewer['seatId']) as $action) {
                if ($action['kind'] !== 'confirmSetup') {
                    $actions[] = $action;
                }
            }
        }
        return $actions;
    }

    private static function versionConflict(int $currentVersion): GameServiceException
    {
        return new GameServiceException(
            'versionConflict',
            'The game changed before this command arrived.',
            $currentVersion,
            'Refresh the game projection and retry a still-legal action with a new command ID.'
        );
    }

    private function applyMatchCommand(array $game, string $seatId, string $identityId, array $command, int $newVersion): void
    {
        $currentVersion = (int)$game['version'];
        $state = $this->loadState($game['id']);
        if ($state === null || !in_array($game['lifecycle'], ['setup', 'draft', 'active', 'completed'], true)) {
            throw new GameServiceException('illegalLifecycle', 'This command is not legal in the current game phase.', $currentVersion);
        }
        $concealedSide = ($state['mode'] ?? null) === 'coop' && $state['phase'] === 'prep'
            ? ($seatId === $state['voidLordSeatId'] ? 'void' : 'heroes')
            : null;
        try {
            $result = $this->engine->apply(
                $state,
                $seatId,
                (string)$command['kind'],
                is_array($command['payload']) ? $command['payload'] : [],
                $this->random
            );
        } catch (GameServiceException $error) {
            throw new GameServiceException($error->errorCode, $error->getMessage(), $currentVersion);
        }
        $bump = $this->connection->prepare(
            'UPDATE games SET lifecycle = :lifecycle, version = :version, updated_at = :updated WHERE id = :id AND version = :expected'
        );
        $bump->execute([
            'lifecycle' => self::lifecycleOf($result['state']), 'version' => $newVersion, 'updated' => self::now(),
            'id' => $game['id'], 'expected' => $currentVersion,
        ]);
        if ($bump->rowCount() === 0) {
            throw self::versionConflict($currentVersion);
        }
        $this->saveState($game['id'], $newVersion, $result['state']);
        if ($game['mode'] === 'coop' && $this->scalar('SELECT COUNT(*) FROM game_memberships WHERE game_id = :game AND ai_personality IS NOT NULL', ['game' => $game['id']]) > 0) {
            $this->connection->prepare('UPDATE games SET ai_stepped_at = :stepped WHERE id = :id')
                ->execute(['stepped' => ($this->clockMs)(), 'id' => $game['id']]);
        }
        foreach ($result['events'] as $event) {
            $side = in_array($event['kind'], self::PUBLIC_PREP_EVENTS, true) ? null : $concealedSide;
            $this->appendEvent($game['id'], $newVersion, $event['kind'], $identityId, $event['payload'], $event['focusHexes'], $side);
        }
    }

    /** The pause after the last match command in an AI game, by pace. */
    private const AI_PACE_MS = ['slow' => 2000, 'normal' => 1000, 'fast' => 300];

    private function appendAiEvents(string $gameId, int $version, array $aiSeat, array $events): void
    {
        foreach ($events as $event) {
            $side = in_array($event['kind'], self::PUBLIC_PREP_EVENTS, true) ? null : 'void';
            $this->appendEvent($gameId, $version, $event['kind'], (string)$aiSeat['identity_id'], $event['payload'], $event['focusHexes'], $side);
        }
    }

    /**
     * Runs inside every poll (the server has no background workers): at most one AI step once the pace
     * pause is over. It never fails the poll; whatever goes wrong is logged and the poll answers as usual.
     */
    private function advanceAi(string $gameId): void
    {
        try {
            $statement = $this->connection->prepare('SELECT * FROM games WHERE id = :id');
            $statement->execute(['id' => $gameId]);
            $game = $statement->fetch();
            if (!$game || $game['mode'] !== 'coop' || !in_array($game['lifecycle'], ['draft', 'active'], true)) {
                return;
            }
            $ai = $this->aiSeat($this->memberships($gameId));
            if ($ai === null) {
                return;
            }
            $pause = self::AI_PACE_MS[(string)($game['ai_pace'] ?? 'normal')] ?? self::AI_PACE_MS['normal'];
            if ($game['ai_stepped_at'] !== null && ($this->clockMs)() - (int)$game['ai_stepped_at'] < $pause) {
                return;
            }
            $state = $this->loadState($gameId);
            if ($state === null) {
                return;
            }
            if ($game['lifecycle'] === 'draft') {
                // Paced like a step (the lease), so a malformed legacy state cannot log on every poll.
                if (($state['phase'] ?? null) === 'prep' && !$state['setup']['confirmed'] && $this->takeAiLease($game)) {
                    $this->ensureAiHorde($game, $ai);
                }
                return;
            }
            $seatId = (string)$ai['id'];
            $owed = ($state['phase'] ?? null) === 'active' && ($state['result'] ?? null) === null
                && (($state['pending']['choice']['seatId'] ?? null) ?? ($state['turn']['activeSeatId'] ?? null)) === $seatId;
            // Claim the step before the (costly) search, so concurrent due polls plan once; only then does a poll write.
            if (!$owed || !$this->takeAiLease($game)) {
                return;
            }
            // Fair play: once the battle is active the Void Lord's view conceals nothing, RNG is not kept in the
            // state, and pending.context is resolved engine data, so the planner may read the raw state.
            $make = $this->plannerFactory ?? static fn(MatchEngine $engine, Personality $personality, int $now, int $deadline, \Closure $clockMs): TurnPlanner
                => new TurnPlanner($engine, $personality, $now, $deadline, $clockMs);
            // A fresh planner every step: it keeps per-search state.
            $planner = $make($this->engine, Personalities::get((string)$ai['ai_personality']), time(), ($this->clockMs)() + TurnPlanner::STEP_BUDGET_MS, $this->clockMs);
            try {
                $command = $planner->next($state, $seatId, (int)$game['version']);
            } catch (Throwable $error) {
                self::logAi("planner failed in game {$gameId}", $error);
                $command = $this->aiFallbackCommand($state, $seatId, null);
            }
            if ($command !== null) {
                $this->applyAiCommand($gameId, $ai, (int)$game['version'], $command, true);
            }
        } catch (Throwable $error) {
            $this->rollback();
            self::logAi("step failed in game {$gameId}", $error);
        }
    }

    private static function logAi(string $what, Throwable $error): void
    {
        error_log('[ai] ' . $what . ': ' . get_class($error) . ' ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine());
    }

    /**
     * Claims the AI's next step by moving ai_stepped_at to now, only if it still holds the value this poll read
     * (null-safe for both MySQL and SQLite). A concurrent due poll loses and does not plan.
     */
    private function takeAiLease(array $game): bool
    {
        $claim = $this->connection->prepare(
            'UPDATE games SET ai_stepped_at = :now
             WHERE id = :id AND version = :version AND (ai_stepped_at = :seen OR (ai_stepped_at IS NULL AND :seen2 IS NULL))'
        );
        $seen = $game['ai_stepped_at'] === null ? null : (int)$game['ai_stepped_at'];
        $claim->execute(['now' => ($this->clockMs)(), 'id' => $game['id'], 'version' => (int)$game['version'], 'seen' => $seen, 'seen2' => $seen]);
        return $claim->rowCount() === 1;
    }

    /** One AI command, atomically. A conflict means another poll stepped first; a rejection falls back to ending. */
    private function applyAiCommand(string $gameId, array $ai, int $plannedVersion, array $command, bool $mayFallBack): void
    {
        $seatId = (string)$ai['id'];
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            if ((int)$game['version'] !== $plannedVersion) {
                $this->rollback();
                return;
            }
            $newVersion = $plannedVersion + 1;
            $this->applyMatchCommand($game, $seatId, (string)$ai['identity_id'], $command, $newVersion);
            $this->writeSnapshot($gameId, $newVersion, $this->spectatorProjection($this->gameRow($gameId)));
            $this->connection->commit();
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof GameServiceException && $error->errorCode === 'versionConflict') {
                return;
            }
            self::logAi("command {$command['kind']} rejected in game {$gameId}", $error);
            if (!$mayFallBack) {
                return;
            }
            $state = $this->loadState($gameId);
            $fallback = $state === null ? null : $this->aiFallbackCommand($state, $seatId, $command);
            if ($fallback !== null) {
                $this->applyAiCommand($gameId, $ai, (int)$this->gameRow($gameId)['version'], $fallback, false);
            }
        }
    }

    /** What always ends the AI's obligation: answer its choice, else close the active unit, else end the turn. */
    private function aiFallbackCommand(array $state, string $seatId, ?array $rejected): ?array
    {
        $state = MatchEngine::normalize($state);
        $commands = CommandCandidates::for($state, $seatId, $this->engine->legalActions($state, $seatId, time()));
        foreach (['resolveChoice', 'endActivation', 'endTurn'] as $kind) {
            foreach ($commands as $command) {
                if ($command['kind'] === $kind && $command != $rejected) {
                    return $command;
                }
            }
        }
        return null;
    }

    /** Safety net for a legacy state: a preparation whose AI horde was never confirmed gets it now. */
    private function ensureAiHorde(array $game, array $ai): void
    {
        $gameId = (string)$game['id'];
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            $state = $this->loadState($gameId);
            if ($state === null || ($state['phase'] ?? null) !== 'prep' || $state['setup']['confirmed']
                || $state['voidLordSeatId'] !== (string)$ai['id']) {
                $this->rollback();
                return;
            }
            [$state, $events] = $this->buildAiHorde($state, $ai, $gameId, count($this->memberships($gameId)) - 1);
            $version = (int)$game['version'] + 1;
            $bump = $this->connection->prepare(
                'UPDATE games SET lifecycle = :lifecycle, version = :version, updated_at = :updated, ai_stepped_at = :stepped WHERE id = :id AND version = :expected'
            );
            $bump->execute([
                'lifecycle' => self::lifecycleOf($state), 'version' => $version, 'updated' => self::now(),
                'stepped' => ($this->clockMs)(), 'id' => $gameId, 'expected' => (int)$game['version'],
            ]);
            if ($bump->rowCount() === 0) {
                $this->rollback();
                return;
            }
            $this->saveState($gameId, $version, $state);
            $this->appendAiEvents($gameId, $version, $ai, $events);
            $this->writeSnapshot($gameId, $version, $this->spectatorProjection($this->gameRow($gameId)));
            $this->connection->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function getGame(string $gameId, string $identityId): array
    {
        $this->advanceAi($gameId);
        $this->requireMembership($gameId, $identityId);
        return $this->projection($gameId, $identityId);
    }

    public function reconnect(string $gameId, string $identityId, string $seatToken): array
    {
        $this->advanceAi($gameId);
        $this->requireSeatCredential($gameId, $identityId, $seatToken);
        return $this->projection($gameId, $identityId);
    }

    public function reassignSeat(
        string $gameId,
        string $hostIdentityId,
        string $hostSeatToken,
        string $seatId,
        string $newIdentityId,
    ): array {
        $this->connection->beginTransaction();
        try {
            $game = $this->gameRow($gameId);
            $this->requireSeatCredential($gameId, $hostIdentityId, $hostSeatToken);
            if ($game['host_identity_id'] !== $hostIdentityId) {
                throw new GameServiceException('hostRequired', 'Only the host can reassign a seat.', (int)$game['version']);
            }
            if ($newIdentityId === '') {
                throw new GameServiceException('invalidIdentity', 'A replacement identity is required.', (int)$game['version']);
            }
            $identityExists = (int)$this->scalar(
                'SELECT
                    (SELECT COUNT(*) FROM users WHERE id = :user) +
                    (SELECT COUNT(*) FROM guest_identities WHERE id = :guest AND converted_user_id IS NULL)',
                ['user' => $newIdentityId, 'guest' => $newIdentityId]
            );
            if ($identityExists === 0) {
                throw new GameServiceException('identityNotFound', 'The replacement identity does not exist.', (int)$game['version']);
            }
            $existing = (int)$this->scalar(
                'SELECT COUNT(*) FROM game_memberships WHERE game_id = :game AND identity_id = :identity',
                ['game' => $gameId, 'identity' => $newIdentityId]
            );
            if ($existing > 0) {
                throw new GameServiceException('identityAlreadySeated', 'The replacement identity already controls a seat.', (int)$game['version']);
            }
            $statement = $this->connection->prepare(
                'SELECT id, is_host, ai_personality FROM game_memberships WHERE game_id = :game AND id = :seat'
            );
            $statement->execute(['game' => $gameId, 'seat' => $seatId]);
            $seat = $statement->fetch();
            if (!$seat || $seat['ai_personality'] !== null) {
                throw new GameServiceException('seatNotFound', 'The requested seat does not exist.', (int)$game['version']);
            }

            $newSeatToken = self::token();
            $this->connection->prepare(
                'UPDATE game_memberships
                 SET identity_id = :identity, seat_token_hash = :token, ready = 0
                 WHERE game_id = :game AND id = :seat'
            )->execute([
                'identity' => $newIdentityId,
                'token' => hash('sha256', $newSeatToken),
                'game' => $gameId,
                'seat' => $seatId,
            ]);
            if ((bool)$seat['is_host']) {
                $this->connection->prepare('UPDATE games SET host_identity_id = :identity WHERE id = :game')
                    ->execute(['identity' => $newIdentityId, 'game' => $gameId]);
            }
            $version = (int)$game['version'] + 1;
            $this->connection->prepare('UPDATE games SET version = :version, updated_at = :updated WHERE id = :game')
                ->execute(['version' => $version, 'updated' => self::now(), 'game' => $gameId]);
            $this->appendEvent($gameId, $version, 'seatReassigned', $hostIdentityId, ['seatId' => $seatId]);
            $projection = $this->projection($gameId, $newIdentityId);
            $this->writeSnapshot($gameId, $version, $projection);
            $this->connection->commit();
            return ['seatId' => $seatId, 'seatToken' => $newSeatToken, 'game' => $projection];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function getEvents(string $gameId, string $identityId, string $seatToken, int $afterSequence): array
    {
        $this->advanceAi($gameId);
        $membership = $this->requireSeatCredential($gameId, $identityId, $seatToken);
        return $this->eventPage($gameId, $afterSequence, $membership['role'] === 'voidLord' ? 'void' : 'heroes');
    }

    /** Anyone may read a game's event log by its id; before a Co-op battle it carries neither side's preparations. */
    public function getWatchedEvents(string $gameId, int $afterSequence): array
    {
        $this->advanceAi($gameId);
        $this->gameRow($gameId);
        return $this->eventPage($gameId, $afterSequence, null);
    }

    private function eventPage(string $gameId, int $afterSequence, ?string $side): array
    {
        $revealed = !in_array($this->gameRow($gameId)['lifecycle'], ['lobby', 'draft'], true);
        $statement = $this->connection->prepare(
            'SELECT sequence, version, kind, acting_identity_id, focus_hexes_json, payload_json, created_at
             FROM game_events WHERE game_id = :game AND sequence > :after
               AND (visible_side IS NULL OR visible_side = :side OR :revealed = :yes)
             ORDER BY sequence LIMIT 200'
        );
        $statement->execute(['game' => $gameId, 'after' => $afterSequence, 'side' => $side ?? '', 'revealed' => $revealed ? 'y' : 'n', 'yes' => 'y']);
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
            $this->connection->prepare(
                'UPDATE game_memberships SET identity_id = :to
                 WHERE identity_id = :from
                   AND game_id NOT IN (SELECT game_id FROM (SELECT game_id FROM game_memberships WHERE identity_id = :to2) AS seated)'
            )->execute(['to' => $toIdentityId, 'from' => $fromIdentityId, 'to2' => $toIdentityId]);
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

    public function createSpectatorLink(string $gameId, string $identityId, string $seatToken): array
    {
        $game = $this->gameRow($gameId);
        $this->requireSeatCredential($gameId, $identityId, $seatToken);
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
        $this->advanceAi((string)$gameId);
        return $this->spectatorProjection($this->gameRow($gameId));
    }

    /** Anyone holding the game id may watch; spectators never receive actions or seat credentials. */
    public function getWatchedGame(string $gameId): array
    {
        $this->advanceAi($gameId);
        return $this->spectatorProjection($this->gameRow($gameId));
    }

    private function spectatorProjection(array $game): array
    {
        return $this->buildProjection($game, $this->memberships($game['id']), [
            'role' => 'spectator', 'seatId' => null, 'isHost' => false, 'canCommand' => false,
        ]);
    }

    public function revokeSpectatorLink(string $gameId, string $identityId, string $seatToken, string $linkId): void
    {
        $game = $this->gameRow($gameId);
        $this->requireSeatCredential($gameId, $identityId, $seatToken);
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
        foreach ($memberships as $membership) {
            if ($membership['identity_id'] === $identityId) {
                $viewerSeat = $membership;
            }
        }
        return $this->buildProjection($game, $memberships, [
            'role' => 'player',
            'seatId' => $viewerSeat['id'] ?? null,
            'isHost' => $game['host_identity_id'] === $identityId,
            'canCommand' => true,
        ], $identityId);
    }

    private function buildProjection(array $game, array $memberships, array $viewer, ?string $viewerId = null): array
    {
        $state = $this->loadState($game['id']);
        $state = $state === null ? null : MatchEngine::normalize($state);
        $lobbySetup = null;
        if ($game['lifecycle'] === 'lobby') {
            // The lobby board belongs to the Void Lord alone; the match itself has not begun.
            $lobbySetup = $state;
            $state = null;
        }
        $side = self::viewerSide($memberships, $viewer);
        $concealed = $game['mode'] === 'coop' && ($lobbySetup !== null || ($state['phase'] ?? null) === 'prep');
        $shown = match (true) {
            $lobbySetup !== null => $side === 'void' ? $lobbySetup : null,
            $concealed && $side !== 'void' => self::withoutSide($state, 'void'),
            default => $state,
        };
        $scores = [];
        foreach ($memberships as $membership) {
            $scores[$membership['id']] = 0;
        }
        $legalActions = match (true) {
            $viewer['role'] !== 'player' => [],
            $game['lifecycle'] === 'lobby' => $this->lobbyActions($game, $memberships, $viewer, $lobbySetup),
            $state !== null => $this->engine->legalActions($state, $viewer['seatId']),
            default => [],
        };
        if ($viewer['role'] === 'player' && $viewer['isHost'] && in_array($game['lifecycle'], ['setup', 'draft', 'active'], true)
            && $this->aiSeat($memberships) !== null) {
            $legalActions[] = ['kind' => 'setAiPace', 'unitId' => null, 'targetHexes' => []];
        }
        if ($viewer['role'] === 'player' && in_array($game['lifecycle'], ['setup', 'draft'], true)) {
            $legalActions[] = ['kind' => 'setCommanderName', 'unitId' => null, 'targetHexes' => []];
        }
        $legalActions = array_map(
            static fn(array $action): array => $action + ['ability' => null],
            $legalActions
        );
        $accountFlags = $this->accountFlags($memberships, $viewerId);
        return [
            'gameId' => $game['id'],
            'version' => (int)$game['version'],
            'lifecycle' => $game['lifecycle'],
            'mode' => $game['mode'],
            'scenarioName' => $game['scenario_name'],
            'seatLimit' => self::seatLimit((string)$game['mode'], $game['seat_limit'] ?? null),
            'aiPace' => $this->aiSeat($memberships) === null ? null : (string)($game['ai_pace'] ?? 'normal'),
            'aiPersonalities' => $game['mode'] === 'coop' && $game['lifecycle'] === 'lobby' ? Personalities::catalogue() : [],
            'viewer' => $viewer,
            'board' => $this->boardProjection($game, $shown),
            'sides' => $state === null ? null : (object)$state['sides'],
            'setup' => match (true) {
                $lobbySetup !== null => $side === 'void' ? $this->setupProjection($lobbySetup) : null,
                $state !== null && ($state['mode'] ?? 'vanillaPvp') === 'coop' => $concealed && $side !== 'void' ? self::redactedSetup($state) : $this->setupProjection($state),
                default => null,
            },
            'turn' => [
                'round' => $state['turn']['round'] ?? 0,
                'activeSeatId' => $state['turn']['activeSeatId'] ?? null,
                'activationsRemaining' => $state === null ? 0 : Turn::activationsRemaining($state),
                'activationAllowance' => $state === null || $state['phase'] !== 'active' ? 0 : (int)($state['turn']['allowance'] ?? 0),
                'activeUnitId' => $state['turn']['activeUnitId'] ?? null,
                'respondingToSeatId' => $state !== null && ($state['mode'] ?? null) === 'coop' && $state['phase'] === 'active' && $state['turn']['slot'] % 2 === 1
                    ? $state['initiative']['order'][intdiv($state['turn']['slot'], 2)]
                    : null,
            ],
            'pendingChoice' => $this->pendingChoiceProjection($state, $viewer['seatId']),
            'pendingChoiceSummary' => $this->pendingChoiceSummary($state),
            'legalActions' => $legalActions,
            'scores' => $scores,
            'result' => ($state['result'] ?? null) === null ? null : $state['result'] + [
                'winningSide' => $state['result']['winningSide'] ?? $state['result']['winningSeatId'],
                'finalScores' => $scores,
            ],
            'memberships' => array_map(static fn(array $seat): array => [
                'seatId' => $seat['id'],
                'seatIndex' => (int)$seat['seat_index'],
                'role' => (string)$seat['role'],
                'ready' => (bool)$seat['ready'],
                'isHost' => (bool)$seat['is_host'],
                'name' => $seat['commander_name'] === null ? null : (string)$seat['commander_name'],
                'aiPersonality' => $seat['ai_personality'] === null ? null : (string)$seat['ai_personality'],
            ] + $accountFlags[(string)$seat['identity_id']], $memberships),
            'armies' => $this->armiesProjection($state, !$concealed || $side === 'heroes'),
            'draft' => $state !== null && in_array($state['phase'], ['draft', 'prep'], true)
                ? (($state['mode'] ?? 'vanillaPvp') === 'coop'
                    ? ($concealed && $side !== 'heroes' ? $this->redactedCoopDraft($state) : $this->coopDraftProjection($state))
                    : $this->draftProjection($state))
                : null,
            'units' => $shown === null ? [] : array_values(array_map(fn(array $unit): array => $this->unitProjection($shown, $unit), self::orderedUnits($shown))),
        ];
    }

    /** Each seat's army identity, including the Void Lord's horde, for roster names and emblems. */
    private function armiesProjection(?array $state, bool $heroArmies = true): ?array
    {
        $armies = [];
        foreach ($heroArmies ? ($state['draft']['armyBySeat'] ?? []) : [] as $seatId => $name) {
            if ($name !== null && ($army = $this->engine->catalog()->army($name)) !== null) {
                $armies[(string)$seatId] = ['name' => $army['name'], 'emblem' => $army['emblem']];
            }
        }
        if (($state['mode'] ?? null) === 'coop' && isset($state['voidLordSeatId'])) {
            $armies[(string)$state['voidLordSeatId']] = ['name' => 'Void Lord', 'emblem' => '/images/armies/void-lord.svg'];
        }
        return $armies === [] ? null : $armies;
    }

    private function unitProjection(array $state, array $unit): array
    {
        $id = $unit['unitId'];
        $alive = $unit['hex'] !== null && $state['phase'] === 'active';
        $effective = static fn(string $stat): int => $alive ? Stats::effective($state, $id, $stat) : (int)$unit[$stat];
        $abilities = self::abilityCards($unit['abilities'], $state['turn']['abilitiesUsed'][$id] ?? [], isset($unit['tier']));
        foreach ($abilities as &$card) {
            $handler = AbilityRegistry::get($card['name']);
            if ($handler instanceof SpecialAttack) {
                $unavailable = $handler->unavailable($state, $id);
                $card['coolingDown'] = $unavailable === 'abilityCoolingDown';
                if ($unavailable === 'columnAlreadyTossed') {
                    $card['description'] .= ' The column has been tossed and is unavailable for the rest of the match.';
                }
            }
        }
        unset($card);
        return [
            'unitId' => $id,
            'seatId' => $unit['seatId'],
            'isHero' => $unit['isHero'],
            'name' => $unit['name'],
            'image' => $unit['image'],
            'avatarImage' => $unit['avatarImage'] ?? null,
            'move' => $unit['move'],
            'range' => $unit['range'],
            'power' => $unit['power'],
            'defense' => $unit['defense'],
            'hp' => $unit['hp'],
            'wounds' => $unit['wounds'],
            'hex' => $unit['hex'],
            'activatedThisTurn' => in_array($id, $state['turn']['activated'], true)
                || ($unit['flags']['summonedOnTurn'] ?? null) === $state['turn']['number'],
            'abilities' => $abilities,
            'side' => $unit['side'] ?? $unit['seatId'],
            'tier' => $unit['tier'] ?? null,
            'items' => array_values($unit['items'] ?? []),
            'inactiveAbilities' => array_map(
                static fn(array $card): array => array_merge($card, ['playable' => false]),
                self::abilityCards($unit['inactiveAbilities'] ?? [])
            ),
            'effects' => array_map(static fn(array $effect): array => [
                'name' => $effect['name'],
                'stat' => $effect['stat'],
                'amount' => $effect['amount'],
                'expires' => isset($effect['turns']) ? 'turns' : ($effect['expires']['at'] === Effects::UNTIL_END_OF_TURN ? 'endOfTurn' : 'nextTurn'),
                'turnsLeft' => isset($effect['turns']) ? (int)$effect['turns'] : null,
            ], Effects::forUnit($state, $id)),
            'conditions' => array_map(static fn(array $condition): array => [
                'name' => $condition['name'],
                'expires' => self::conditionExpiry($state, $condition['expires']),
                'data' => (object)($condition['data'] ?? []),
            ], UnitConditions::forUnit($state, $id)),
            'effectiveMove' => $effective('move'),
            'effectiveRange' => $effective('range'),
            'effectivePower' => $effective('power'),
            'effectiveDefense' => $effective('defense'),
        ];
    }

    private static function conditionExpiry(array $state, array $expires): string
    {
        if ($expires['kind'] === 'afterTurn') {
            return $expires['turnNumber'] === $state['turn']['number'] ? 'endOfThisTurn' : 'endOfNextTurn';
        }
        if ($expires['kind'] === 'turnEnd') {
            return $expires['seatId'] === $state['turn']['activeSeatId'] && $state['turn']['number'] > ($expires['afterTurnNumber'] ?? -1) ? 'endOfThisTurn' : 'endOfNextTurn';
        }
        return (string)$expires['kind'];
    }

    private static function abilityCards(array $abilities, array $used = [], bool $monster = false): array
    {
        return array_map(static fn(array $ability): array => [
            'name' => $ability['name'],
            'type' => $ability['type'],
            // Monster Flying uses the shared digital terrain rules, which are narrower than its printed text.
            'description' => $monster && $ability['name'] === 'Flying'
                ? 'Ignores open water and ice. Can cross lava during voluntary movement, but cannot land on it. Poison pits still apply. Forced entry into lava and ending a turn there cause lava damage.'
                : $ability['description'],
            'playable' => AbilityRegistry::isPlayable($ability['name']),
            'usedThisTurn' => in_array($ability['name'], $used, true),
        ], $abilities);
    }

    private function pendingChoiceProjection(?array $state, ?string $seatId): ?array
    {
        $choice = $state['pending']['choice'] ?? null;
        if ($choice === null || $choice['seatId'] !== $seatId) {
            return null;
        }
        return [
            'choiceId' => $choice['choiceId'],
            'seatId' => $choice['seatId'],
            'kind' => $choice['kind'],
            'options' => $choice['options'],
            'unitId' => $choice['unitId'],
            'side' => $choice['side'] ?? null,
            'faces' => $choice['faces'] ?? [],
            'targetHexes' => $choice['targetHexes'] ?? [],
            'item' => $choice['item'] ?? null,
            'expiresAt' => $choice['kind'] === 'moonlightGlare' && isset($choice['openedAt'])
                ? gmdate('Y-m-d\TH:i:s\Z', $choice['openedAt'] + BattleRules::CHOICE_TIMEOUT_SECONDS)
                : null,
        ];
    }

    private function pendingChoiceSummary(?array $state): ?array
    {
        $choice = $state['pending']['choice'] ?? null;
        return $choice === null ? null : [
            'choiceId' => $choice['choiceId'],
            'seatId' => $choice['seatId'],
            'unitId' => $choice['unitId'],
            'kind' => $choice['kind'],
            'keepableAt' => $choice['kind'] === 'moonlightGlare' && isset($choice['openedAt'])
                ? gmdate('Y-m-d\TH:i:s\Z', $choice['openedAt'] + BattleRules::CHOICE_TIMEOUT_SECONDS)
                : null,
        ];
    }

    /** MySQL JSON columns reorder object keys, so units are ordered by seat, hero first, then unit number. */
    private static function orderedUnits(array $state): array
    {
        $units = array_values($state['units']);
        usort($units, static function (array $a, array $b) use ($state): int {
            return [array_search($a['seatId'], $state['seats'], true), $a['isHero'] ? 0 : 1, self::unitNumber($a['unitId'])]
                <=> [array_search($b['seatId'], $state['seats'], true), $b['isHero'] ? 0 : 1, self::unitNumber($b['unitId'])];
        });
        return $units;
    }

    private static function unitNumber(string $unitId): int
    {
        return preg_match('/(\d+)$/', $unitId, $match) === 1 ? (int)$match[1] : 0;
    }

    /** 'void' for the Void Lord's seat, 'heroes' for any other player seat, null for spectators. */
    private static function viewerSide(array $memberships, array $viewer): ?string
    {
        if ($viewer['role'] !== 'player' || $viewer['seatId'] === null) {
            return null;
        }
        foreach ($memberships as $seat) {
            if ((string)$seat['id'] === (string)$viewer['seatId']) {
                return $seat['role'] === 'voidLord' ? 'void' : 'heroes';
            }
        }
        return null;
    }

    private static function withoutSide(array $state, string $side): array
    {
        $state['units'] = array_filter($state['units'], static fn(array $unit): bool => ($unit['side'] ?? null) !== $side);
        return $state;
    }

    /** What the other side may know of the Void Lord's setup: only whether it is finished. */
    private static function redactedSetup(array $state): array
    {
        return ['budget' => 0, 'spent' => 0, 'confirmed' => (bool)$state['setup']['confirmed'], 'spawnZoneHexes' => [], 'roster' => [], 'redacted' => true];
    }

    /** The draft as the Void Lord and spectators see it during preparation: progress, but no picks. */
    private function redactedCoopDraft(array $state): array
    {
        $projection = $this->coopDraftProjection($state);
        $projection['heroesBySeat'] = array_map(static fn(): array => [], $projection['heroesBySeat']);
        $projection['armyBySeat'] = array_map(static fn(): ?string => null, $projection['armyBySeat']);
        $projection['availableHeroes'] = array_map(static fn(array $hero): array => ['takenBySeatId' => null] + $hero, $projection['availableHeroes']);
        $projection['availableArmies'] = array_map(static fn(array $army): array => ['takenBySeatId' => null] + $army, $projection['availableArmies']);
        $projection['redacted'] = true;
        return $projection;
    }

    private function setupProjection(array $state): array
    {
        $spawn = [];
        foreach ($state['board'] as $key => $hex) {
            if (($hex['spawnZone'] ?? null) === 'void') {
                $spawn[] = (string)$key;
            }
        }
        sort($spawn, SORT_STRING);
        return [
            'budget' => $state['setup']['budget'],
            'spent' => $state['setup']['spent'],
            'maxBudget' => $state['setup']['cap'] ?? null,
            'confirmed' => $state['setup']['confirmed'],
            'redacted' => false,
            'spawnZoneHexes' => $spawn,
            'roster' => array_map(static fn(array $monster): array => [
                'name' => $monster['name'],
                'biome' => $monster['biome'],
                'tier' => $monster['tier'],
                'cost' => CoopSetup::cost($monster),
                'image' => $monster['image'],
                'move' => $monster['move'],
                'range' => $monster['range'],
                'power' => $monster['power'],
                'defense' => $monster['defense'],
                'hp' => $monster['hp'],
                'abilities' => array_map(
                    static fn(array $card): array => array_merge($card, ['playable' => CoopSetup::isAbilityPlayable($card)]),
                    self::abilityCards($monster['abilities'], [], true)
                ),
            ], $this->engine->catalog()->monsters()),
        ];
    }

    private function coopDraftProjection(array $state): array
    {
        $draft = $state['draft'];
        $heroOwner = static function (string $name) use ($draft): ?string {
            foreach ($draft['heroesBySeat'] as $seatId => $heroes) {
                if (in_array($name, $heroes, true)) {
                    return (string)$seatId;
                }
            }
            return null;
        };
        $catalog = $this->engine->catalog();
        $picksMade = array_sum(array_map('count', $draft['heroesBySeat']))
            + count(array_filter($draft['armyBySeat'], static fn(?string $army): bool => $army !== null));
        return [
            'picksMade' => $picksMade,
            'picksTotal' => 3 * count($state['initiative']['order']),
            'redacted' => false,
            'initiativeRolls' => $state['initiative']['rolls'],
            'pickingSeatId' => $draft['pickingSeatId'],
            'firstSeatId' => null,
            'startBySeat' => (object)[],
            'heroBySeat' => (object)[],
            'armyBySeat' => $draft['armyBySeat'],
            'heroesBySeat' => $draft['heroesBySeat'],
            'availableHeroes' => array_map(
                static fn(array $hero): array => ['abilities' => self::abilityCards($hero['abilities'])] + $hero + ['takenBySeatId' => $heroOwner($hero['name'])],
                $catalog->heroes()
            ),
            'availableArmies' => array_map(
                static fn(array $army): array => ['units' => array_map(
                    static fn(array $unit): array => ['abilities' => self::abilityCards($unit['abilities'])] + $unit,
                    $army['units']
                )] + $army + ['takenBySeatId' => ($seat = array_search($army['name'], $draft['armyBySeat'], true)) === false ? null : (string)$seat],
                $catalog->armies()
            ),
        ];
    }

    private function draftProjection(array $state): array
    {
        $draft = $state['draft'];
        $takenBy = static function (array $claims, string $name): ?string {
            $seat = array_search($name, $claims, true);
            return $seat === false ? null : (string)$seat;
        };
        $catalog = $this->engine->catalog();
        return [
            'initiativeRolls' => $state['initiative']['rolls'],
            'pickingSeatId' => $draft['pickingSeatId'],
            'firstSeatId' => $draft['firstSeatId'],
            'startBySeat' => $draft['startBySeat'],
            'heroBySeat' => $draft['heroBySeat'],
            'armyBySeat' => $draft['armyBySeat'],
            'availableHeroes' => array_map(
                static fn(array $hero): array => ['abilities' => self::abilityCards($hero['abilities'])] + $hero + ['takenBySeatId' => $takenBy($draft['heroBySeat'], $hero['name'])],
                $catalog->heroes()
            ),
            'availableArmies' => array_map(
                static fn(array $army): array => ['units' => array_map(
                    static fn(array $unit): array => ['abilities' => self::abilityCards($unit['abilities'])] + $unit,
                    $army['units']
                )] + $army + ['takenBySeatId' => $takenBy($draft['armyBySeat'], $army['name'])],
                $catalog->armies()
            ),
        ];
    }

    private function boardProjection(array $game, ?array $state): array
    {
        $definition = $this->scenarioDefinition($game);
        $occupants = [];
        foreach ($state['units'] ?? [] as $unit) {
            if ($unit['hex'] !== null) {
                $occupants[$unit['hex']] = $unit['unitId'];
            }
        }
        return self::mapProjection($definition, static fn(array $hex): ?string => $state !== null
            ? ($occupants["{$hex['q']},{$hex['r']}"] ?? null)
            : (isset($hex['piece']) ? (string)$hex['piece'] : null), $state['crystals'] ?? [], $state['board'] ?? null);
    }

    /** A built-in scenario's empty board, for previewing the battlefield before a game exists. */
    public function builtInScenarios(): array
    {
        return $this->scenarios->builtInSummaries();
    }

    public function builtInScenario(string $scenarioName): array
    {
        return $this->scenarios->builtInDefinition($scenarioName);
    }

    public function playableMaps(string $ownerUserId, string $mode): array
    {
        if (!in_array($mode, ['vanillaPvp', 'coop', 'battleRoyale'], true)) {
            throw new GameServiceException('invalidMode', 'The requested game mode is not supported.');
        }
        return $this->scenarios->playableOwned($ownerUserId, $mode);
    }

    public function ownedMapBoard(string $revisionId, string $ownerUserId): array
    {
        try {
            $definition = $this->scenarios->ownedRevision($revisionId, $ownerUserId);
        } catch (DomainException) {
            throw new GameServiceException('scenarioNotFound', 'The map could not be found.');
        }
        return self::mapProjection($definition, static fn(): ?string => null);
    }

    public function scenarioBoard(string $scenarioName): array
    {
        return self::mapProjection($this->scenarios->builtInDefinition($scenarioName), static fn(): ?string => null);
    }

    private static function mapProjection(array $definition, callable $occupant, array $crystals = [], ?array $board = null): array
    {
        return [
            'columns' => (int)$definition['map']['columns'],
            'rows' => (int)$definition['map']['rows'],
            'hexes' => array_map(static fn(array $hex): array => [
                'q' => (int)$hex['q'],
                'r' => (int)$hex['r'],
                'biome' => (string)$hex['biome'],
                'terrain' => (string)$hex['terrain'],
                'elevation' => (int)$hex['elevation'],
                'sightBlocker' => Board::blocksSight($hex),
                'impassable' => Board::blocksMovement($hex),
                'object' => Board::projectedObject($hex, $board),
                'controlledBy' => isset($crystals["{$hex['q']},{$hex['r']}"]) ? (string)$crystals["{$hex['q']},{$hex['r']}"] : null,
                'radius' => in_array(Board::objectKind($hex), Board::NEXUS_POINTS, true) ? (int)($hex['radius'] ?? 1) : null,
                'occupantId' => $occupant($hex),
                'playerStart' => isset($hex['playerStart']) ? (int)$hex['playerStart'] : null,
                'voidPortal' => $board !== null
                    ? (bool)($board[Hex::key((int)$hex['q'], (int)$hex['r'])]['voidPortal'] ?? false)
                    : (bool)($hex['voidPortal'] ?? false),
            ], $definition['map']['hexes']),
        ];
    }

    private function scenarioDefinition(array $game): array
    {
        $revisionId = (string)($game['scenario_revision_id'] ?? '');
        if ($revisionId !== '') {
            return $this->scenarios->getRevision($revisionId);
        }
        $scenario = $this->scenarios->resolveBuiltInRevision($game['scenario_name'], $game['mode']);
        $this->connection->prepare('UPDATE games SET scenario_revision_id = :revision WHERE id = :game')
            ->execute(['revision' => $scenario['revisionId'], 'game' => $game['id']]);
        return $scenario['definition'];
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

    /** @return array<string, array{isAccount: bool, isFriend: bool, friendRequested: bool}> keyed by identity id. */
    private function accountFlags(array $memberships, ?string $viewerId): array
    {
        $ids = array_values(array_unique(array_map(static fn(array $seat): string => (string)$seat['identity_id'], $memberships)));
        $accounts = [];
        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $statement = $this->connection->prepare("SELECT id FROM users WHERE id IN ({$placeholders})");
            $statement->execute($ids);
            $accounts = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $viewerIsAccount = $viewerId !== null && isset($accounts[$viewerId]);
        $friendService = new FriendService($this->connection);
        $friends = $viewerIsAccount ? array_fill_keys($friendService->friendIds($viewerId), true) : [];
        $requested = $viewerIsAccount ? array_fill_keys($friendService->requestedIds($viewerId), true) : [];
        $flags = [];
        foreach ($ids as $id) {
            $flags[$id] = [
                'isAccount' => isset($accounts[$id]),
                'isFriend' => isset($friends[$id]),
                'friendRequested' => isset($requested[$id]),
            ];
        }
        return $flags;
    }

    private function memberships(string $gameId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, identity_id, seat_index, role, is_host, ready, commander_name, ai_personality FROM game_memberships WHERE game_id = :game ORDER BY seat_index'
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

    private function requireSeatCredential(string $gameId, string $identityId, string $seatToken): array
    {
        $membership = $this->requireMembership($gameId, $identityId);
        if ($seatToken === '') {
            throw new GameServiceException('seatCredentialRequired', 'A seat credential is required to control this seat.');
        }
        if (!hash_equals($membership['seat_token_hash'], hash('sha256', $seatToken))) {
            throw new GameServiceException('seatCredentialInvalid', 'The seat credential is invalid or has been replaced.');
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

    private function appendEvent(string $gameId, int $version, string $kind, string $actor, array $payload, array $focusHexes = [], ?string $visibleSide = null): void
    {
        $sequence = (int)$this->scalar(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM game_events WHERE game_id = :game',
            ['game' => $gameId]
        );
        $this->connection->prepare(
            'INSERT INTO game_events
             (game_id, sequence, version, kind, acting_identity_id, focus_hexes_json, payload_json, created_at, visible_side)
             VALUES (:game, :sequence, :version, :kind, :actor, :focus, :payload, :created, :side)'
        )->execute([
            'game' => $gameId, 'sequence' => $sequence, 'version' => $version, 'kind' => $kind, 'actor' => $actor, 'side' => $visibleSide,
            'focus' => json_encode(array_values($focusHexes), JSON_THROW_ON_ERROR),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created' => self::now(),
        ]);
    }

    private function writeSnapshot(string $gameId, int $version, array $projection): void
    {
        if ($projection['mode'] === 'coop' && in_array($projection['lifecycle'], ['lobby', 'draft'], true)
            && $projection['viewer']['role'] !== 'spectator') {
            // A stored player view would carry one side's hidden preparations.
            $projection = $this->spectatorProjection($this->gameRow($gameId));
        }
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

    private function loadState(string $gameId): ?array
    {
        $json = $this->scalar('SELECT state_json FROM game_states WHERE game_id = :game', ['game' => $gameId]);
        return $json === false ? null : json_decode((string)$json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function saveState(string $gameId, int $version, array $state): void
    {
        $sql = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO game_states (game_id, version, state_json, updated_at) VALUES (:game, :version, :state, :updated)
               ON DUPLICATE KEY UPDATE version = VALUES(version), state_json = VALUES(state_json), updated_at = VALUES(updated_at)'
            : 'INSERT INTO game_states (game_id, version, state_json, updated_at) VALUES (:game, :version, :state, :updated)
               ON CONFLICT(game_id) DO UPDATE SET version = excluded.version, state_json = excluded.state_json, updated_at = excluded.updated_at';
        $this->connection->prepare($sql)->execute([
            'game' => $gameId, 'version' => $version,
            'state' => json_encode($state, JSON_THROW_ON_ERROR), 'updated' => self::now(),
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
