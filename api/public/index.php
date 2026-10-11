<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\Request;

spl_autoload_register(static function (string $class): void {
    $prefix = 'BloodAndBanners\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$connection = ConnectionFactory::fromEnvironment();
(new Migrator($connection))->migrate();
$signingKey = getenv('BLOOD_BANNERS_SIGNING_KEY') ?: '';
$application = new Application(new AuthService($connection, $signingKey), new GameService($connection));

$rawBody = file_get_contents('php://input');
$body = $rawBody === false || $rawBody === '' ? [] : json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$response = $application->handle(new Request(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    function_exists('getallheaders') ? getallheaders() : [],
    is_array($body) ? $body : [],
    $_GET,
));

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
