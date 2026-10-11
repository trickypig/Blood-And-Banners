<?php
declare(strict_types=1);

namespace BloodAndBanners\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function migrate(): void
    {
        $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dialect = $driver === 'mysql' ? 'mysql' : 'sqlite';
        $paths = glob(dirname(__DIR__, 2) . "/migrations/*.{$dialect}.sql") ?: [];
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new RuntimeException("No {$dialect} migrations were found.");
        }
        foreach ($paths as $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration {$path}");
            }
            $this->connection->exec($sql);
        }
    }
}
