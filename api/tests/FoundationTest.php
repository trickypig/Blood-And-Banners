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
