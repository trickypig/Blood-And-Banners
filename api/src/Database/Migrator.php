<?php
declare(strict_types=1);

namespace BloodAndBanners\Database;

use PDO;
use RuntimeException;

/** Applies each migration file once, recording it in schema_migrations; 001 and 002 are idempotent so pre-tracking databases re-run them harmlessly. */
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
        $this->connection->exec($dialect === 'mysql'
            ? 'CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(191) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB'
            : 'CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $applied = $this->connection->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($paths as $path) {
            $name = basename($path, ".{$dialect}.sql");
            if (in_array($name, $applied, true)) {
                continue;
            }
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration {$path}");
            }
            $this->connection->exec($sql);
            $this->connection->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (:name, :applied)')
                ->execute(['name' => $name, 'applied' => gmdate('Y-m-d H:i:s')]);
        }
    }
}
