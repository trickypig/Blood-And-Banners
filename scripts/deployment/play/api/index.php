<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Config\AppConfig;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\Gateway;
use BloodAndBanners\Http\RequestFactory;
use BloodAndBanners\Http\Response;

$bloodRoot = dirname(__DIR__, 3);
$apiRoot = $bloodRoot . '/server/api';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
require $apiRoot . '/autoload.php';

try {
    $config = AppConfig::fromFile($bloodRoot . '/.env');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS'
        && Gateway::corsHeaders($config, $requestOrigin) !== []) {
        $response = new Response(204, []);
    } else {
        $connection = ConnectionFactory::fromConfig($config);
        $application = new Application(
            new AuthService($connection, $config->signingKey),
            new GameService($connection),
        );
        $request = RequestFactory::fromHttp(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '/',
            function_exists('getallheaders') ? getallheaders() : [],
            (string)file_get_contents('php://input'),
            $_GET,
            '/play',
        );
        $response = $application->handle($request);
    }
} catch (JsonException) {
    $response = new Response(400, [
        'error' => [
            'code' => 'invalidJson',
            'message' => 'The request body is not valid JSON.',
        ],
    ]);
} catch (Throwable $error) {
    $response = Gateway::failure($error);
}

Gateway::emit($response, $config ?? null, $requestOrigin);
