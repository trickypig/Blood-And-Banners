<?php
declare(strict_types=1);

namespace BloodAndBanners\Http;

use BloodAndBanners\Account\AccountException;
use BloodAndBanners\Account\AccountService;
use BloodAndBanners\Account\FriendService;
use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Auth\EmailSignInService;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Game\GameServiceException;
use DomainException;
use Throwable;

final class Application
{
    public const API_VERSION = '1.0';
    public const MINIMUM_MAUI_VERSION = '1.0.0';

    public function __construct(
        private readonly AuthService $auth,
        private readonly GameService $games,
        private readonly ?EmailSignInService $signIn = null,
        private readonly ?AccountService $accounts = null,
        private readonly ?FriendService $friends = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $upgrade = $this->upgradeResponse($request);
            if ($upgrade !== null) {
                return $upgrade;
            }
            if ($request->method === 'GET' && $request->path === '/api/v1/compatibility') {
                return new Response(200, [
                    'apiVersion' => self::API_VERSION,
                    'minimumMauiVersion' => self::MINIMUM_MAUI_VERSION,
                ]);
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/guest') {
                return new Response(201, $this->auth->createGuest((string)($request->body['deviceId'] ?? '')));
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/refresh') {
                return new Response(200, $this->auth->refresh(
                    (string)($request->body['refreshToken'] ?? ''),
                    (string)($request->body['deviceId'] ?? '')
                ));
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/login') {
                return new Response(200, $this->auth->login(
                    (string)($request->body['email'] ?? ''),
                    (string)($request->body['password'] ?? ''),
                    (string)($request->body['deviceId'] ?? '')
                ));
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/email/start') {
                $this->service($this->signIn)->start((string)($request->body['email'] ?? ''), $request->clientIp);
                return new Response(202, ['sent' => true]);
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/email/verify') {
                $signIn = $this->service($this->signIn);
                $deviceId = (string)($request->body['deviceId'] ?? '');
                $guestId = $this->optionalGuestId($request);
                $transfer = fn(string $from, string $to) => $this->games->transferIdentity($from, $to);
                $token = (string)($request->body['token'] ?? '');
                return new Response(200, $token !== ''
                    ? $signIn->verifyToken($token, $deviceId, $guestId, $transfer)
                    : $signIn->verifyCode((string)($request->body['email'] ?? ''), (string)($request->body['code'] ?? ''), $deviceId, $guestId, $transfer));
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/logout') {
                $this->auth->logout((string)($request->body['refreshToken'] ?? ''));
                return new Response(204, []);
            }

            if (preg_match('#^/api/v1/spectate/([^/]+)/commands$#', $request->path) === 1 && $request->method === 'POST') {
                return $this->error(403, 'spectatorReadOnly', 'Spectators cannot submit game commands.');
            }
            if (preg_match('#^/api/v1/spectate/([^/]+)$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                return new Response(200, $this->games->getSpectatorGame($matches[1]));
            }
            if (preg_match('#^/api/v1/watch/([^/]+)/events$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                return new Response(200, $this->games->getWatchedEvents(
                    $matches[1],
                    max(0, (int)($request->query['after'] ?? 0))
                ));
            }
            if (preg_match('#^/api/v1/watch/([^/]+)$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                return new Response(200, $this->games->getWatchedGame($matches[1]));
            }

            if (preg_match('#^/api/v1/scenarios/([^/]+)/board$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                try {
                    return new Response(200, $this->games->scenarioBoard(rawurldecode($matches[1])));
                } catch (DomainException $error) {
                    if ($error->getMessage() !== 'scenarioNotFound') {
                        throw $error;
                    }
                    return $this->error(404, 'scenarioNotFound', 'No built-in scenario has that name.');
                }
            }

            $principal = $this->principal($request);
            if ($request->path === '/api/v1/account' && in_array($request->method, ['GET', 'PUT'], true)) {
                $userId = $this->requireUser($principal);
                $accounts = $this->service($this->accounts);
                return new Response(200, $request->method === 'GET'
                    ? $accounts->profile($userId)
                    : $accounts->updateDisplayName($userId, (string)($request->body['displayName'] ?? '')));
            }
            if (str_starts_with($request->path, '/api/v1/friends')) {
                $me = $this->requireUser($principal);
                $friends = $this->service($this->friends);
                if ($request->method === 'GET' && $request->path === '/api/v1/friends') {
                    return new Response(200, $friends->list($me));
                }
                if ($request->method === 'POST' && $request->path === '/api/v1/friends/requests') {
                    $gameId = (string)($request->body['gameId'] ?? '');
                    return new Response(200, $gameId !== ''
                        ? $friends->requestFromSeat($me, $gameId, (string)($request->body['seatId'] ?? ''))
                        : $friends->requestByEmail($me, (string)($request->body['email'] ?? '')));
                }
                if ($request->method === 'POST' && preg_match('#^/api/v1/friends/requests/([^/]+)/(accept|decline)$#', $request->path, $matches) === 1) {
                    return new Response(200, $matches[2] === 'accept' ? $friends->accept($me, $matches[1]) : $friends->decline($me, $matches[1]));
                }
                if ($request->method === 'DELETE' && preg_match('#^/api/v1/friends/([^/]+)$#', $request->path, $matches) === 1) {
                    return new Response(200, $friends->remove($me, $matches[1]));
                }
            }
            if (preg_match('#^/api/v1/games/([^/]+)/resume$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(200, $this->seatSession($this->games->resumeSeat($matches[1], $this->requireUser($principal))));
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/auth/convert') {
                if ($principal['kind'] !== 'guest') {
                    throw new DomainException('guestIdentityRequired');
                }
                $account = $this->auth->convertGuest(
                    $principal['identityId'],
                    (string)($request->body['email'] ?? ''),
                    (string)($request->body['password'] ?? ''),
                    (string)($request->body['displayName'] ?? ''),
                    (string)($request->body['deviceId'] ?? ''),
                    fn(string $from, string $to) => $this->games->transferIdentity($from, $to)
                );
                return new Response(200, $account);
            }
            if ($request->method === 'GET' && $request->path === '/api/v1/account/matches') {
                if ($principal['kind'] !== 'user') {
                    throw new DomainException('accountRequired');
                }
                return new Response(200, ['matches' => $this->games->matchHistory($principal['identityId'])]);
            }
            if ($request->method === 'POST' && $request->path === '/api/v1/games') {
                return new Response(201, $this->seatSession($this->games->createGame(
                    $principal['identityId'],
                    (string)($request->body['mode'] ?? ''),
                    (string)($request->body['scenarioName'] ?? '')
                )));
            }
            if (preg_match('#^/api/v1/games/([^/]+)$#', $request->path, $matches) === 1) {
                if ($request->method === 'GET') {
                    return new Response(200, $this->games->reconnect(
                        $matches[1],
                        $principal['identityId'],
                        $request->seatToken()
                    ));
                }
            }
            if (preg_match('#^/api/v1/games/([^/]+)/join$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(200, $this->seatSession($this->games->joinGame($matches[1], $principal['identityId'])));
            }
            if (preg_match('#^/api/v1/games/([^/]+)/leave$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                $this->games->leaveGame($matches[1], $principal['identityId'], $request->seatToken());
                return new Response(200, ['left' => true]);
            }
            if (preg_match('#^/api/v1/games/([^/]+)/seats/([^/]+)/remove$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(200, $this->games->removeSeat(
                    $matches[1],
                    $principal['identityId'],
                    $request->seatToken(),
                    $matches[2]
                ));
            }
            if (preg_match('#^/api/v1/games/([^/]+)/start$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(200, $this->games->startGame(
                    $matches[1],
                    $principal['identityId'],
                    $request->seatToken()
                ));
            }
            if (preg_match('#^/api/v1/games/([^/]+)/commands$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(200, $this->games->submitCommand(
                    $matches[1],
                    $principal['identityId'],
                    $request->seatToken(),
                    $request->body
                ));
            }
            if (preg_match('#^/api/v1/games/([^/]+)/seats/([^/]+)/reassign$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(200, $this->games->reassignSeat(
                    $matches[1],
                    $principal['identityId'],
                    $request->seatToken(),
                    $matches[2],
                    (string)($request->body['identityId'] ?? '')
                ));
            }
            if (preg_match('#^/api/v1/games/([^/]+)/spectator-links$#', $request->path, $matches) === 1 && $request->method === 'POST') {
                return new Response(201, $this->games->createSpectatorLink(
                    $matches[1],
                    $principal['identityId'],
                    $request->seatToken()
                ));
            }
            if (preg_match('#^/api/v1/games/([^/]+)/events$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                return new Response(200, $this->games->getEvents(
                    $matches[1],
                    $principal['identityId'],
                    $request->seatToken(),
                    max(0, (int)($request->query['after'] ?? 0))
                ));
            }

            return $this->error(404, 'routeNotFound', 'No API route matches this request.');
        } catch (GameServiceException $error) {
            $status = match ($error->errorCode) {
                'versionConflict' => 409,
                'gameNotFound' => 404,
                'membershipRequired', 'hostRequired', 'seatCredentialRequired', 'seatCredentialInvalid' => 403,
                default => 422,
            };
            return $this->error($status, $error->errorCode, $error->getMessage(), $error->currentVersion, $error->recovery);
        } catch (AccountException $error) {
            return $this->error($error->status, $error->errorCode, $error->getMessage());
        } catch (DomainException $error) {
            $status = str_contains($error->getMessage(), 'Token') ? 401 : 422;
            return $this->error($status, $error->getMessage(), 'The request could not be authorized.');
        } catch (Throwable) {
            return $this->error(500, 'internalError', 'The server could not complete the request.');
        }
    }

    private function principal(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new DomainException('accessTokenRequired');
        }
        return $this->auth->authenticate($token);
    }

    /** A guest bearer token on sign-in means "bring my current seat along"; anything else is ignored. */
    private function optionalGuestId(Request $request): ?string
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return null;
        }
        try {
            $principal = $this->auth->authenticate($token);
        } catch (DomainException) {
            return null;
        }
        return ($principal['kind'] ?? '') === 'guest' ? (string)$principal['identityId'] : null;
    }

    /**
     * @template T of object
     * @param T|null $service
     * @return T
     */
    private function service(?object $service): object
    {
        return $service ?? throw new \RuntimeException('Account services are not configured.');
    }

    private function requireUser(array $principal): string
    {
        if (($principal['kind'] ?? '') !== 'user') {
            throw new AccountException(403, 'accountRequired', 'Sign in to use this.');
        }
        return (string)$principal['identityId'];
    }

    private function upgradeResponse(Request $request): ?Response
    {
        $platform = strtolower($request->headers['x-client-platform'] ?? '');
        $version = $request->headers['x-client-version'] ?? self::MINIMUM_MAUI_VERSION;
        if ($platform === 'maui' && version_compare($version, self::MINIMUM_MAUI_VERSION, '<')) {
            return $this->error(
                426,
                'upgradeRequired',
                'This app version is no longer compatible with the game server.',
                recovery: 'Upgrade the app before reconnecting.'
            );
        }
        return null;
    }

    private function error(
        int $status,
        string $code,
        string $message,
        ?int $currentVersion = null,
        ?string $recovery = null,
    ): Response {
        return new Response($status, ['error' => array_filter([
            'code' => $code,
            'message' => $message,
            'currentVersion' => $currentVersion,
            'recovery' => $recovery,
        ], static fn(mixed $value): bool => $value !== null)]);
    }

    private function seatSession(array $result): array
    {
        $seatToken = (string)$result['seatToken'];
        unset($result['seatToken']);
        return ['game' => $result, 'seatToken' => $seatToken];
    }
}
