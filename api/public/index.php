<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\RequestFactory;

require dirname(__DIR__) . '/autoload.php';

$connection = ConnectionFactory::fromEnvironment();
$signingKey = getenv('BLOOD_BANNERS_SIGNING_KEY') ?: '';
$application = new Application(new AuthService($connection, $signingKey), new GameService($connection));

$rawBody = file_get_contents('php://input');
$response = $application->handle(RequestFactory::fromHttp(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
    function_exists('getallheaders') ? getallheaders() : [],
    $rawBody === false ? '' : $rawBody,
    $_GET,
));

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header("{$name}: {$value}");
}
echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
