<?php
declare(strict_types=1);

namespace BloodAndBanners\Http;

use BloodAndBanners\Auth\AuthService;
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

            if (preg_match('#^/api/v1/spectate/([^/]+)/commands$#', $request->path) === 1 && $request->method === 'POST') {
                return $this->error(403, 'spectatorReadOnly', 'Spectators cannot submit game commands.');
            }
            if (preg_match('#^/api/v1/spectate/([^/]+)$#', $request->path, $matches) === 1 && $request->method === 'GET') {
                return new Response(200, $this->games->getSpectatorGame($matches[1]));
            }

            $principal = $this->principal($request);
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
