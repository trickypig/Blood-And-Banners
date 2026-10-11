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
                'command_receipts',
            ] as $table) {
                expect_true(in_array($table, $tables, true), "missing table {$table}");
            }
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
            $created = $games->createGame($host['identityId'], 'vanillaPvp', 'Training Grounds');
            $joined = $games->joinGame($created['gameId'], $rival['identityId']);
            $games->setReady($created['gameId'], $host['identityId'], true);
            $games->setReady($created['gameId'], $rival['identityId'], true);

            try {
                $games->startGame($created['gameId'], $rival['identityId']);
                throw new RuntimeException('non-host started the game');
            } catch (GameServiceException $error) {
                expect_same('hostRequired', $error->errorCode, 'non-host start should be rejected');
            }

            $started = $games->startGame($created['gameId'], $host['identityId']);
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
            $events = $games->getEvents($created['gameId'], $host['identityId'], 0);

            expect_same(2, $joined['version'], 'joining should advance optimistic concurrency version');
            expect_same('playerJoined', $events['events'][0]['kind'], 'joining should emit a durable event');
        },

        'failed start rolls back lifecycle version and snapshot together' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $host = $auth->createGuest('rollback-host');
            $rival = $auth->createGuest('rollback-rival');
            $game = $games->createGame($host['identityId'], 'vanillaPvp', 'Training Grounds');
            $games->joinGame($game['gameId'], $rival['identityId']);
            $games->setReady($game['gameId'], $host['identityId'], true);
            $games->setReady($game['gameId'], $rival['identityId'], true);
            $before = $games->getGame($game['gameId'], $host['identityId']);
            $db->exec("CREATE TRIGGER reject_start_event BEFORE INSERT ON game_events WHEN NEW.kind = 'gameStarted' BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

            try {
                $games->startGame($game['gameId'], $host['identityId']);
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
            $created = $games->createGame($host['identityId'], 'coop', 'Portal Watch');

            $command = [
                'commandId' => '018f2ec5-bf4c-7000-8000-000000000021',
                'expectedVersion' => 1,
                'kind' => 'setReady',
                'payload' => ['ready' => true],
            ];
            $first = $games->submitCommand($created['gameId'], $host['identityId'], $command);
            $retry = $games->submitCommand($created['gameId'], $host['identityId'], $command);

            expect_same($first, $retry, 'retry must return the cached response');
            expect_same(2, $first['version'], 'accepted command should increment the version once');

            try {
                $games->submitCommand($created['gameId'], $host['identityId'], [
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

        'guest conversion preserves game membership and enables account login' => static function (): void {
            [$db, $auth, $games] = test_services_with_games();
            $guest = $auth->createGuest('convert-device');
            $game = $games->createGame($guest['identityId'], 'coop', 'Portal Watch');

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

            $link = $games->createSpectatorLink($game['gameId'], $host['identityId']);
            $projection = $games->getSpectatorGame($link['token']);
            expect_same('spectator', $projection['viewer']['role'], 'spectator projection should identify the viewer role');
            expect_same(false, $projection['viewer']['canCommand'], 'spectator projection must not expose mutation permission');
            expect_true(!array_key_exists('seatToken', $projection), 'spectator projection must not expose seat credentials');

            $games->revokeSpectatorLink($game['gameId'], $host['identityId'], $link['linkId']);
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
            expect_same(0, $projection['board']['columns'], 'lobby board should be present but empty');
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
