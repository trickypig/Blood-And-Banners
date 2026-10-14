<?php
declare(strict_types=1);

use BloodAndBanners\Config\AppConfig;

function config_tests(): array
{
    return [
        'production env preserves secret punctuation' => static function (): void {
            $path = write_test_env(<<<'ENV'
DB_DRIVER=mysql
DB_HOST=db.example.test
DB_NAME=blood
DB_USER=banner
DB_PASS=p@ss#word=with spaces
APP_SIGNING_KEY=0123456789abcdef0123456789abcdef
APP_ENV=production
CORS_ORIGIN=https://blood.trickypig.com
ENV);
            try {
                $config = AppConfig::fromFile($path);
                expect_same('mysql', $config->dbDriver, 'driver mismatch');
                expect_same('p@ss#word=with spaces', $config->dbPassword, 'password must remain literal');
                expect_same(
                    'mysql:host=db.example.test;dbname=blood;charset=utf8mb4',
                    $config->pdoDsn(),
                    'DSN mismatch'
                );
            } finally {
                unlink($path);
            }
        },

        'matching quotes around env values are removed' => static function (): void {
            $path = write_test_env(<<<'ENV'
DB_DRIVER="mysql"
DB_HOST='db.example.test'
DB_NAME="blood"
DB_USER='banner'
DB_PASS="quoted secret"
APP_SIGNING_KEY="0123456789abcdef0123456789abcdef"
APP_ENV="production"
CORS_ORIGIN="https://blood.trickypig.com"
ENV);
            try {
                $config = AppConfig::fromFile($path);
                expect_same('quoted secret', $config->dbPassword, 'matching quotes should be removed');
            } finally {
                unlink($path);
            }
        },

        'mysql configuration requires every database value' => static function (): void {
            $path = write_test_env(<<<'ENV'
DB_DRIVER=mysql
DB_HOST=db.example.test
DB_NAME=blood
DB_USER=banner
APP_SIGNING_KEY=0123456789abcdef0123456789abcdef
APP_ENV=production
CORS_ORIGIN=https://blood.trickypig.com
ENV);
            try {
                expect_config_error($path, 'DB_PASS is required.');
            } finally {
                unlink($path);
            }
        },

        'configuration errors never include secret values' => static function (): void {
            $secret = 'visible-nowhere-secret';
            $path = write_test_env("DB_DRIVER=oracle\nDB_PASS={$secret}\nAPP_SIGNING_KEY=short\n");
            try {
                try {
                    AppConfig::fromFile($path);
                    throw new RuntimeException('invalid configuration was accepted');
                } catch (RuntimeException $error) {
                    expect_true(!str_contains($error->getMessage(), $secret), 'configuration error leaked DB_PASS');
                    expect_same('DB_DRIVER must be mysql or sqlite.', $error->getMessage(), 'driver error mismatch');
                }
            } finally {
                unlink($path);
            }
        },

        'signing key must contain at least 32 bytes' => static function (): void {
            $path = write_test_env(<<<'ENV'
DB_DRIVER=sqlite
DB_PATH=C:\temp\blood.sqlite
APP_SIGNING_KEY=too-short
APP_ENV=development
CORS_ORIGIN=http://localhost:5016
ENV);
            try {
                expect_config_error($path, 'APP_SIGNING_KEY must contain at least 32 bytes.');
            } finally {
                unlink($path);
            }
        },

        'sqlite configuration uses DB_PATH and ignores mysql values' => static function (): void {
            $path = write_test_env(<<<'ENV'
DB_DRIVER=sqlite
DB_PATH=C:\temp\blood.sqlite
APP_SIGNING_KEY=0123456789abcdef0123456789abcdef
APP_ENV=development
CORS_ORIGIN=http://localhost:5016
ENV);
            try {
                $config = AppConfig::fromFile($path);
                expect_same('sqlite:C:\temp\blood.sqlite', $config->pdoDsn(), 'SQLite DSN mismatch');
                expect_same('', $config->dbHost, 'SQLite should not require a host');
            } finally {
                unlink($path);
            }
        },

        'CORS origin must be an origin without a path' => static function (): void {
            $path = write_test_env(<<<'ENV'
DB_DRIVER=sqlite
DB_PATH=C:\temp\blood.sqlite
APP_SIGNING_KEY=0123456789abcdef0123456789abcdef
APP_ENV=production
CORS_ORIGIN=https://blood.trickypig.com/play/
ENV);
            try {
                expect_config_error($path, 'CORS_ORIGIN must be an http or https origin without a path.');
            } finally {
                unlink($path);
            }
        },
    ];
}

function write_test_env(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'blood-env-');
    if ($path === false || file_put_contents($path, $content . PHP_EOL) === false) {
        throw new RuntimeException('could not create a temporary env file');
    }
    return $path;
}

function expect_config_error(string $path, string $expected): void
{
    try {
        AppConfig::fromFile($path);
        throw new RuntimeException('invalid configuration was accepted');
    } catch (RuntimeException $error) {
        expect_same($expected, $error->getMessage(), 'configuration error mismatch');
    }
}
