<?php
declare(strict_types=1);

namespace BloodAndBanners\Database;

use PDO;

final class ConnectionFactory
{
    public static function sqliteMemory(): PDO
    {
        return self::configure(new PDO('sqlite::memory:'));
    }

    public static function fromEnvironment(): PDO
    {
        $dsn = getenv('BLOOD_BANNERS_DSN') ?: 'sqlite:' . dirname(__DIR__, 2) . '/var/development.sqlite';
        $user = getenv('BLOOD_BANNERS_DB_USER') ?: null;
        $password = getenv('BLOOD_BANNERS_DB_PASSWORD') ?: null;
        return self::configure(new PDO($dsn, $user, $password));
    }

    private static function configure(PDO $connection): PDO
    {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $connection->exec('PRAGMA foreign_keys = ON');
        }
        return $connection;
    }
}
