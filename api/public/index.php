<?php
declare(strict_types=1);

use BloodAndBanners\Account\AccountService;
use BloodAndBanners\Account\FriendService;
use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Auth\EmailSignInService;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\RequestFactory;
use BloodAndBanners\Mail\LogMailer;

require dirname(__DIR__) . '/autoload.php';

$connection = ConnectionFactory::fromEnvironment();
$signingKey = getenv('BLOOD_BANNERS_SIGNING_KEY') ?: '';
$auth = new AuthService($connection, $signingKey);
$friends = new FriendService($connection);
$publicUrl = getenv('BLOOD_BANNERS_PUBLIC_URL') ?: 'http://localhost:5016';
$signIn = new EmailSignInService($connection, $auth, new LogMailer(dirname(__DIR__) . '/var/mail.log'), $publicUrl);
$application = new Application($auth, new GameService($connection), $signIn, new AccountService($connection, $friends), $friends, new BloodAndBanners\Scenario\ScenarioService($connection));

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
