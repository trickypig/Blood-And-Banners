<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\Vanilla\MatchEngine;

/** A Void Reaches lobby: host plus $heroPlayers more guests, nobody ready, no Void Lord yet. */
function coop_service_match(int $heroPlayers = 1): array
{
    [$db, $auth] = test_services();
    $games = new GameService($db, null, new ScriptedRandom(), new MatchEngine(test_catalog()));
    $host = $auth->createGuest('coop-host-' . bin2hex(random_bytes(4)));
    $created = $games->createGame($host['identityId'], 'coop', 'Void Reaches');
    $match = ['db' => $db, 'games' => $games, 'gameId' => $created['gameId'],
        'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']]];
    for ($i = 1; $i <= $heroPlayers; $i++) {
        $guest = $auth->createGuest("coop-guest-{$i}-" . bin2hex(random_bytes(4)));
        $joined = $games->joinGame($created['gameId'], $guest['identityId']);
        $match["hero{$i}"] = ['identity' => $guest['identityId'], 'token' => $joined['seatToken'], 'seat' => $joined['viewer']['seatId']];
    }
    return $match;
}

function coop_ready_all(array $match): void
{
    foreach ($match as $who => $seat) {
        if (is_array($seat) && isset($seat['token'])) {
            vanilla_submit($match, $who, 'setReady', ['ready' => true]);
        }
    }
}

function coop_service_tests(): array
{
    return [
        'any member claims the void lord, roles show in the lobby and readiness resets' => static function (): void {
            $match = coop_service_match();
            vanilla_submit($match, 'hero1', 'setReady', ['ready' => true]);
            expect_true(in_array('claimVoidLord', array_column($match['games']->getGame($match['gameId'], $match['host']['identity'])['legalActions'], 'kind'), true), 'the role is offered');
            $claimed = vanilla_submit($match, 'host', 'claimVoidLord', []);
            $roles = array_column($claimed['memberships'], 'role', 'seatId');
            expect_same('voidLord', $roles[$match['host']['seat']], 'the claimer is the void lord');
            expect_same('player', $roles[$match['hero1']['seat']], 'others are hero players');
            expect_same([false, false], array_column($claimed['memberships'], 'ready'), 'claiming clears readiness');
            expect_game_error('voidLordTaken', static fn() => vanilla_submit($match, 'hero1', 'claimVoidLord', []));
            expect_game_error('notVoidLord', static fn() => vanilla_submit($match, 'hero1', 'releaseVoidLord', []));
            $released = vanilla_submit($match, 'host', 'releaseVoidLord', []);
            expect_same(['player', 'player'], array_column($released['memberships'], 'role'), 'releasing frees the role');
        },

        'the host assigns the void lord; others cannot; bad seats are refused' => static function (): void {
            $match = coop_service_match();
            expect_game_error('hostRequired', static fn() => vanilla_submit($match, 'hero1', 'assignVoidLord', ['seatId' => $match['hero1']['seat']]));
            expect_game_error('seatNotFound', static fn() => vanilla_submit($match, 'host', 'assignVoidLord', ['seatId' => ['x']]));
            $assigned = vanilla_submit($match, 'host', 'assignVoidLord', ['seatId' => $match['hero1']['seat']]);
            expect_same('voidLord', array_column($assigned['memberships'], 'role', 'seatId')[$match['hero1']['seat']], 'the host moved the role');
        },

        'role commands are refused outside a co-op lobby' => static function (): void {
            $duel = vanilla_service_match();
            expect_game_error('unsupportedCommand', static fn() => vanilla_submit($duel, 'host', 'claimVoidLord', []));
        },

        'co-op start needs a void lord and a hero player, then opens setup' => static function (): void {
            $match = coop_service_match();
            coop_ready_all($match);
            expect_game_error('voidLordRequired', static fn() => $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']));
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same('setup', $started['lifecycle'], 'the match opens in setup');
            expect_same(['placeMonster'], array_column($started['legalActions'], 'kind'), 'the void lord may place monsters');
            expect_same([], $match['games']->getGame($match['gameId'], $match['hero1']['identity'])['legalActions'], 'the hero player waits');

            $solo = coop_service_match(0);
            vanilla_submit($solo, 'host', 'claimVoidLord', []);
            vanilla_submit($solo, 'host', 'setReady', ['ready' => true]);
            expect_game_error('playersNotReady', static fn() => $solo['games']->startGame($solo['gameId'], $solo['host']['identity'], $solo['host']['token']));
        },
    ];
}
