<?php
declare(strict_types=1);

use BloodAndBanners\Config\AppConfig;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;

require dirname(__DIR__) . '/autoload.php';

if ($argc === 3 && $argv[1] === '--env-file') {
    $connection = ConnectionFactory::fromConfig(AppConfig::fromFile($argv[2]));
} elseif ($argc === 1) {
    $connection = ConnectionFactory::fromEnvironment();
} else {
    fwrite(STDERR, "Usage: php api/tools/migrate.php [--env-file PATH]\n");
    exit(2);
}

(new Migrator($connection))->migrate();
fwrite(STDOUT, "Migrations complete.\n");
