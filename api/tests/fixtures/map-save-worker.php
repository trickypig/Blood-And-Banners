<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';

[$script, $path, $owner, $mapId, $expected, $ready] = $argv;
$db = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
$service = new BloodAndBanners\Scenario\ScenarioService($db);
$definition = $service->loadOwned($mapId, $owner)['definition'];
file_put_contents($ready, 'ready');
try {
    $service->saveOwned($mapId, $owner, $expected, $definition);
    echo '200';
} catch (BloodAndBanners\Scenario\MapException $error) {
    echo (string)$error->status;
}
