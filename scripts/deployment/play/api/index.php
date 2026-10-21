<?php
declare(strict_types=1);

use BloodAndBanners\Account\AccountService;
use BloodAndBanners\Account\FriendService;
use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Auth\EmailSignInService;
use BloodAndBanners\Config\AppConfig;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\Gateway;
use BloodAndBanners\Http\RequestFactory;
use BloodAndBanners\Http\Response;
use BloodAndBanners\Mail\MailerFactory;

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
        $auth = new AuthService($connection, $config->signingKey);
        $friends = new FriendService($connection);
        $signIn = new EmailSignInService(
            $connection,
            $auth,
            MailerFactory::fromConfig($config, $bloodRoot . '/server/mail.log'),
            $config->publicUrl,
        );
        $application = new Application(
            $auth,
            new GameService($connection),
            $signIn,
            new AccountService($connection, $friends),
            $friends,
        );
        $httpHeaders = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($httpHeaders)) {
            $httpHeaders = [];
        }
        $request = RequestFactory::fromHttp(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '/',
            $httpHeaders,
            (string)file_get_contents('php://input'),
            $_GET,
            '/play',
            $_SERVER,
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
