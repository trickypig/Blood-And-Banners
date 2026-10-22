<?php
declare(strict_types=1);

use BloodAndBanners\Account\AccountService;
use BloodAndBanners\Account\FriendService;
use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Auth\EmailSignInService;
use BloodAndBanners\Database\ConnectionFactory;
use BloodAndBanners\Database\Migrator;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Http\Application;
use BloodAndBanners\Http\Request;
use BloodAndBanners\Mail\Mailer;

final class MemoryMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, text: string, html: string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $subject, string $text, string $html): void
    {
        if ($this->fail) {
            throw new RuntimeException('SMTP unavailable');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
    }
}

/** @return array{app: Application, db: PDO, mailer: MemoryMailer} */
function account_context(?Closure $clock = null): array
{
    $db = ConnectionFactory::sqliteMemory();
    (new Migrator($db))->migrate();
    $auth = new AuthService($db, 'test-signing-key-with-at-least-32-bytes');
    $mailer = new MemoryMailer();
    $signIn = new EmailSignInService($db, $auth, $mailer, 'https://blood.test/play', $clock, static fn(string $line): bool => true);
    $friends = new FriendService($db);
    return ['app' => new Application($auth, new GameService($db), $signIn, new AccountService($db, $friends), $friends, new BloodAndBanners\Scenario\ScenarioService($db)), 'db' => $db, 'mailer' => $mailer];
}

function mailed_code(MemoryMailer $mailer): string
{
    $last = $mailer->sent[array_key_last($mailer->sent)];
    preg_match('/\b(\d{6})\b/', $last['subject'], $match) === 1 || throw new RuntimeException('no code mailed');
    return $match[1];
}

function mailed_token(MemoryMailer $mailer): string
{
    $last = $mailer->sent[array_key_last($mailer->sent)];
    preg_match('/[?&]signin=([A-Za-z0-9_-]+)/', $last['text'], $match) === 1 || throw new RuntimeException('no link mailed');
    return $match[1];
}

function bearer(array $session): array
{
    return ['authorization' => 'Bearer ' . $session['accessToken']];
}

/** Runs start + verify by code and returns the verify response body. */
function sign_in(array $ctx, string $email, string $device = 'device-1', array $headers = []): array
{
    $start = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/start', body: ['email' => $email]));
    expect_same(202, $start->status, 'start should accept');
    $verify = $ctx['app']->handle(new Request('POST', '/api/v1/auth/email/verify', $headers, [
        'email' => $email, 'code' => mailed_code($ctx['mailer']), 'deviceId' => $device,
    ]));
    expect_same(200, $verify->status, 'verify should succeed: ' . json_encode($verify->body));
    return $verify->body;
}

function create_game(array $ctx, array $session, string $mode = 'vanillaPvp', string $scenario = 'Four Corners'): array
{
    $created = $ctx['app']->handle(new Request('POST', '/api/v1/games', bearer($session), ['mode' => $mode, 'scenarioName' => $scenario]));
    expect_same(201, $created->status, 'game created');
    return $created->body;
}

function set_display_name(array $ctx, array $session, string $name): void
{
    $response = $ctx['app']->handle(new Request('PUT', '/api/v1/account', bearer($session), ['displayName' => $name]));
    expect_same(200, $response->status, 'name set');
}

function befriend(array $ctx, array $a, array $b, string $bEmail): void
{
    $ctx['app']->handle(new Request('POST', '/api/v1/friends/requests', bearer($a), ['email' => $bEmail]));
    $incoming = $ctx['app']->handle(new Request('GET', '/api/v1/friends', bearer($b)))->body['incoming'];
    $ctx['app']->handle(new Request('POST', "/api/v1/friends/requests/{$incoming[0]['requestId']}/accept", bearer($b)));
}
