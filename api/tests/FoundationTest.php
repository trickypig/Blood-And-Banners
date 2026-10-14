<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Game\GameServiceException;

function foundation_tests(): array
{
    return [
        'migrations create the complete persistence model' => static function (): void {
            $db = ConnectionFactory::sqliteMemory();
            (new Migrator($db))->migrate();

            $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")
                ->fetchAll(PDO::FETCH_COLUMN);

            foreach ([
                'users', 'guest_identities', 'refresh_tokens', 'scenarios', 'scenario_revisions',
                'games', 'game_memberships', 'spectator_links', 'game_snapshots', 'game_events',
                'command_receipts', 'game_states',
            ] as $table) {
                expect_true(in_array($table, $tables, true), "missing table {$table}");
            }
        },

        'migrations can run repeatedly without failing' => static function (): void {
            $db = ConnectionFactory::sqliteMemory();
            (new Migrator($db))->migrate();
            (new Migrator($db))->migrate();

            $count = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'game_states'")->fetchColumn();
            expect_same(1, $count, 'game_states should exist exactly once');
        },

        'guest refresh tokens rotate and cannot be replayed' => static function (): void {
            [$db, $auth] = test_services();
            $issued = $auth->createGuest('device-alpha');

            $principal = $auth->authenticate($issued['accessToken']);
            expect_same('guest', $principal['kind'], 'access token should identify a guest');
            expect_same($issued['identityId'], $principal['identityId'], 'access token identity should match');

            $rotated = $auth->refresh($issued['refreshToken'], 'device-alpha');
            expect_true($rotated['refreshToken'] !== $issued['refreshToken'], 'refresh token must rotate');

            try {
                $auth->refresh($issued['refreshToken'], 'device-alpha');
                throw new RuntimeException('replayed refresh token was accepted');
            } catch (DomainException $error) {
                expect_same('invalidRefreshToken', $error->getMessage(), 'replay should be rejected');
            }
        },

        'database timestamps use a MySQL compatible UTC format' => static function (): void {
            [$db, $auth] = test_services();
            $auth->createGuest('timestamp-device');
            $created = (string)$db->query('SELECT created_at FROM guest_identities LIMIT 1')->fetchColumn();
            $expires = (string)$db->query('SELECT expires_at FROM refresh_tokens LIMIT 1')->fetchColumn();

            expect_true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created) === 1, 'created_at must fit MySQL DATETIME');
            expect_true(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires) === 1, 'expires_at must fit MySQL DATETIME');
        },

        'only the host can start a ready vanilla lobby' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('host-device');
            $rival = $auth->createGuest('rival-device');
            $created = $games->createGame($host['identityId'], 'vanillaPvp', 'Proving Field');
            $joined = $games->joinGame($created['gameId'], $rival['identityId']);
            $games->setReady($created['gameId'], $host['identityId'], $created['seatToken'], true);
            $games->setReady($created['gameId'], $rival['identityId'], $joined['seatToken'], true);

            try {
                $games->startGame($created['gameId'], $rival['identityId'], $joined['seatToken']);
                throw new RuntimeException('non-host started the game');
            } catch (GameServiceException $error) {
                expect_same('hostRequired', $error->errorCode, 'non-host start should be rejected');
            }

            $started = $games->startGame($created['gameId'], $host['identityId'], $created['seatToken']);
            expect_same('draft', $started['lifecycle'], 'starting should enter draft');
            expect_same(2, count($started['memberships']), 'vanilla game should retain two seats');
            expect_true($created['seatToken'] !== $joined['seatToken'], 'seat credentials must be isolated');
        },

        'joining advances the version and appends a durable event' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('join-host');
            $rival = $auth->createGuest('join-rival');
            $created = $games->createGame($host['identityId'], 'vanillaPvp', 'Training Grounds');

            $joined = $games->joinGame($created['gameId'], $rival['identityId']);
            $events = $games->getEvents($created['gameId'], $host['identityId'], $created['seatToken'], 0);

            expect_same(2, $joined['version'], 'joining should advance optimistic concurrency version');
            expect_same('playerJoined', $events['events'][0]['kind'], 'joining should emit a durable event');
        },

        'failed start rolls back lifecycle version and snapshot together' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('rollback-host');
            $rival = $auth->createGuest('rollback-rival');
            $game = $games->createGame($host['identityId'], 'vanillaPvp', 'Proving Field');
            $joined = $games->joinGame($game['gameId'], $rival['identityId']);
            $games->setReady($game['gameId'], $host['identityId'], $game['seatToken'], true);
            $games->setReady($game['gameId'], $rival['identityId'], $joined['seatToken'], true);
            $before = $games->getGame($game['gameId'], $host['identityId']);
            $db->exec("CREATE TRIGGER reject_start_event BEFORE INSERT ON game_events WHEN NEW.kind = 'gameStarted' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

            try {
                $games->startGame($game['gameId'], $host['identityId'], $game['seatToken']);
                throw new RuntimeException('forced event failure did not abort start');
            } catch (PDOException) {
            }

            $after = $games->getGame($game['gameId'], $host['identityId']);
            expect_same($before['lifecycle'], $after['lifecycle'], 'failed start must restore lifecycle');
            expect_same($before['version'], $after['version'], 'failed start must restore version');
        },

        'commands are idempotent and stale versions do not mutate the game' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('host-device');
            $created = $games->createGame($host['identityId'], 'coop', 'Void Reaches');

            $command = [
                'commandId' => '018f2ec5-bf4c-7000-8000-000000000021',
                'expectedVersion' => 1,
                'kind' => 'setReady',
                'payload' => ['ready' => true],
            ];
            $first = $games->submitCommand($created['gameId'], $host['identityId'], $created['seatToken'], $command);
            $retry = $games->submitCommand($created['gameId'], $host['identityId'], $created['seatToken'], $command);

            expect_same($first, $retry, 'retry must return the cached response');
            expect_same(2, $first['version'], 'accepted command should increment the version once');

            try {
                $games->submitCommand($created['gameId'], $host['identityId'], $created['seatToken'], [
                    'commandId' => '018f2ec5-bf4c-7000-8000-000000000022',
                    'expectedVersion' => 1,
                    'kind' => 'setReady',
                    'payload' => ['ready' => false],
                ]);
                throw new RuntimeException('stale command was accepted');
            } catch (GameServiceException $error) {
                expect_same('versionConflict', $error->errorCode, 'stale command should return a version conflict');
                expect_same(2, $error->currentVersion, 'conflict should include the current version');
            }

            expect_same(2, $games->getGame($created['gameId'], $host['identityId'])['version'], 'stale command must not mutate state');
        },

        'commands require the credential for the authenticated seat' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('credential-host');
            $rival = $auth->createGuest('credential-rival');
            $created = $games->createGame($host['identityId'], 'vanillaPvp', 'Training Grounds');
            $joined = $games->joinGame($created['gameId'], $rival['identityId']);
            $command = [
                'commandId' => '018f2ec5-bf4c-7000-8000-000000000023',
                'expectedVersion' => 2,
                'kind' => 'setReady',
                'payload' => ['ready' => true],
            ];

            foreach ([
                ['', 'seatCredentialRequired'],
                [$joined['seatToken'], 'seatCredentialInvalid'],
            ] as [$credential, $expectedCode]) {
                try {
                    $games->submitCommand($created['gameId'], $host['identityId'], $credential, $command);
                    throw new RuntimeException('command without the authenticated seat credential was accepted');
                } catch (GameServiceException $error) {
                    expect_same($expectedCode, $error->errorCode, 'seat credential failure should be explicit');
                }
            }

            $accepted = $games->submitCommand(
                $created['gameId'],
                $host['identityId'],
                $created['seatToken'],
                $command
            );
            expect_same(3, $accepted['version'], 'matching identity and credential should authorize the command');
        },

        'host reassignment rotates the seat credential and supports reconnect' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('reassign-host');
            $departing = $auth->createGuest('reassign-departing');
            $replacement = $auth->createGuest('reassign-replacement');
            $created = $games->createGame($host['identityId'], 'vanillaPvp', 'Training Grounds');
            $joined = $games->joinGame($created['gameId'], $departing['identityId']);

            $reassigned = $games->reassignSeat(
                $created['gameId'],
                $host['identityId'],
                $created['seatToken'],
                $joined['viewer']['seatId'],
                $replacement['identityId']
            );

            expect_true($reassigned['seatToken'] !== $joined['seatToken'], 'reassignment must rotate the seat credential');
            try {
                $games->reconnect($created['gameId'], $replacement['identityId'], $joined['seatToken']);
                throw new RuntimeException('the previous seat credential remained valid after reassignment');
            } catch (GameServiceException $error) {
                expect_same('seatCredentialInvalid', $error->errorCode, 'old seat credential should be invalidated');
            }

            $projection = $games->reconnect(
                $created['gameId'],
                $replacement['identityId'],
                $reassigned['seatToken']
            );
            expect_same($joined['viewer']['seatId'], $projection['viewer']['seatId'], 'replacement should reconnect to the same seat');

            try {
                $games->reconnect($created['gameId'], $departing['identityId'], $joined['seatToken']);
                throw new RuntimeException('departing identity retained seat access');
            } catch (GameServiceException $error) {
                expect_same('membershipRequired', $error->errorCode, 'departing identity should lose membership');
            }
        },

        'host cannot reassign a seat to an identity that does not exist' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('invalid-reassign-host');
            $departing = $auth->createGuest('invalid-reassign-seat');
            $created = $games->createGame($host['identityId'], 'vanillaPvp', 'Training Grounds');
            $joined = $games->joinGame($created['gameId'], $departing['identityId']);

            try {
                $games->reassignSeat(
                    $created['gameId'],
                    $host['identityId'],
                    $created['seatToken'],
                    $joined['viewer']['seatId'],
                    'identity-that-does-not-exist'
                );
                throw new RuntimeException('seat was reassigned to a nonexistent identity');
            } catch (GameServiceException $error) {
                expect_same('identityNotFound', $error->errorCode, 'invalid replacement identity should be explicit');
            }

            $projection = $games->reconnect(
                $created['gameId'],
                $departing['identityId'],
                $joined['seatToken']
            );
            expect_same($joined['viewer']['seatId'], $projection['viewer']['seatId'], 'failed reassignment must preserve the old seat owner');
        },

        'guest conversion preserves game membership and enables account login' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $guest = $auth->createGuest('convert-device');
            $game = $games->createGame($guest['identityId'], 'coop', 'Void Reaches');

            $account = $auth->convertGuest(
                $guest['identityId'],
                'captain@example.com',
                'correct horse battery staple',
                'Banner Captain',
                'convert-device'
            );
            $games->transferIdentity($guest['identityId'], $account['identityId']);
            $history = $games->matchHistory($account['identityId']);
            $login = $auth->login('captain@example.com', 'correct horse battery staple', 'second-device');

            expect_same('user', $account['kind'], 'conversion should return an account identity');
            expect_same($game['gameId'], $history[0]['gameId'], 'converted account should retain game history');
            expect_same($account['identityId'], $login['identityId'], 'account should work on another device');
        },

        'spectator links expose read-only public projections and can be revoked' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('spectator-host');
            $game = $games->createGame($host['identityId'], 'battleRoyale', 'Last Banner');

            $link = $games->createSpectatorLink($game['gameId'], $host['identityId'], $game['seatToken']);
            $projection = $games->getSpectatorGame($link['token']);
            expect_same('spectator', $projection['viewer']['role'], 'spectator projection should identify the viewer role');
            expect_same(false, $projection['viewer']['canCommand'], 'spectator projection must not expose mutation permission');
            expect_true(!array_key_exists('seatToken', $projection), 'spectator projection must not expose seat credentials');

            $games->revokeSpectatorLink($game['gameId'], $host['identityId'], $game['seatToken'], $link['linkId']);
            try {
                $games->getSpectatorGame($link['token']);
                throw new RuntimeException('revoked spectator token remained usable');
            } catch (GameServiceException $error) {
                expect_same('spectatorLinkInvalid', $error->errorCode, 'revoked spectator links should be rejected');
            }
        },

        'game projections expose the complete shared client contract' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $guest = $auth->createGuest('contract-device');
            $projection = $games->createGame($guest['identityId'], 'vanillaPvp', 'Training Grounds');

            foreach (['gameId', 'version', 'lifecycle', 'mode', 'viewer', 'board', 'turn', 'pendingChoice', 'legalActions', 'scores', 'result'] as $field) {
                expect_true(array_key_exists($field, $projection), "projection missing {$field}");
            }
            foreach (['role', 'seatId', 'canCommand', 'isHost'] as $field) {
                expect_true(array_key_exists($field, $projection['viewer']), "viewer missing {$field}");
            }
            expect_same('player', $projection['viewer']['role'], 'member projection should be a player view');
            expect_same(3, $projection['board']['columns'], 'lobby projection should include the selected scenario board');
        },

        'games freeze a compatible immutable scenario revision and project its board' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('scenario-freeze-host');
            $rival = $auth->createGuest('scenario-freeze-rival');

            $created = $games->createGame($host['identityId'], 'vanillaPvp', 'Proving Field');
            $revisionId = $db->query('SELECT scenario_revision_id FROM games LIMIT 1')->fetchColumn();
            expect_true(is_string($revisionId) && $revisionId !== '', 'game should reference a concrete scenario revision');
            expect_same(11, $created['board']['columns'], 'selected scenario columns should be projected in the lobby');
            expect_same(99, count($created['board']['hexes']), 'selected scenario hexes should be projected in the lobby');

            $joined = $games->joinGame($created['gameId'], $rival['identityId']);
            $games->setReady($created['gameId'], $host['identityId'], $created['seatToken'], true);
            $games->setReady($created['gameId'], $rival['identityId'], $joined['seatToken'], true);
            $started = $games->startGame($created['gameId'], $host['identityId'], $created['seatToken']);
            $startedRevisionId = $db->query('SELECT scenario_revision_id FROM games LIMIT 1')->fetchColumn();

            expect_same($revisionId, $startedRevisionId, 'starting must retain the revision selected for the match');
            expect_same(99, count($started['board']['hexes']), 'started match should use the frozen scenario board');
        },

        'game creation rejects unknown and mode incompatible scenarios' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('scenario-validation-host');

            foreach ([
                ['Missing Battlefield', 'scenarioNotFound'],
                ['Void Reaches', 'scenarioModeIncompatible'],
            ] as [$scenarioName, $expectedCode]) {
                try {
                    $games->createGame($host['identityId'], 'vanillaPvp', $scenarioName);
                    throw new RuntimeException('invalid scenario selection was accepted');
                } catch (GameServiceException $error) {
                    expect_same($expectedCode, $error->errorCode, 'scenario selection failure should be explicit');
                }
            }
        },
    ];
}

function test_services(): array
{
    $db = ConnectionFactory::sqliteMemory();
    (new Migrator($db))->migrate();
    return [$db, new AuthService($db, 'test-signing-key-with-at-least-32-bytes')];
}

function test_services_with_games(): array
{
    [$db, $auth] = test_services();
    return [$db, $auth, new GameService($db)];
}
