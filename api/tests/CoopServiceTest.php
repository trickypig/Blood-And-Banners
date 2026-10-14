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
            vanilla_submit($match, 'hero1', 'setReady', ['ready' => true]);
            expect_game_error('voidLordTaken', static fn() => vanilla_submit($match, 'host', 'claimVoidLord', []));
            $after = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same(['voidLord', 'player'], array_column($after['memberships'], 'role'), 'roles are untouched');
            expect_same([false, true], array_column($after['memberships'], 'ready'), 'readiness is untouched by a repeat claim');
            vanilla_submit($match, 'hero1', 'setReady', ['ready' => false]);
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
            expect_same(['placeMonster', 'setCommanderName'], array_column($started['legalActions'], 'kind'), 'the void lord may place monsters');
            expect_same(['setCommanderName'], array_column($match['games']->getGame($match['gameId'], $match['hero1']['identity'])['legalActions'], 'kind'), 'the hero player waits but may rename');

            $solo = coop_service_match(0);
            vanilla_submit($solo, 'host', 'claimVoidLord', []);
            vanilla_submit($solo, 'host', 'setReady', ['ready' => true]);
            expect_game_error('playersNotReady', static fn() => $solo['games']->startGame($solo['gameId'], $solo['host']['identity'], $solo['host']['token']));
        },

        'a scripted co-op match runs from setup through draft to a hero victory' => static function (): void {
            $match = coop_service_match(1);
            vanilla_submit($match, 'host', 'claimVoidLord', []);
            coop_ready_all($match);
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same(12, $started['setup']['budget'], 'one hero player: 4 + 8');
            expect_same(['Test Wolf', 'Test Golem', 'Test Wyrm'], array_column($started['setup']['roster'], 'name'), 'the roster lists visible monsters');
            expect_same([1, 2, 4], array_column($started['setup']['roster'], 'cost'), 'tier costs are projected');
            expect_true(count($started['setup']['spawnZoneHexes']) >= 100, 'spawn hexes are projected');
            expect_same(['heroes', 'void'], array_keys((array)$started['sides']), 'both sides are projected');

            $spawnHex = $started['setup']['spawnZoneHexes'][0];
            $placed = vanilla_submit($match, 'host', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => $spawnHex]);
            $wolf = array_values(array_filter($placed['units'], static fn(array $unit): bool => $unit['unitId'] === 'v-m1'))[0];
            expect_same(['void', 'common'], [$wolf['side'], $wolf['tier']], 'monster side and tier are projected');
            expect_same('Pack Tactics', $wolf['inactiveAbilities'][0]['name'], 'printed monster abilities are projected');
            expect_same(false, $wolf['inactiveAbilities'][0]['playable'], 'and marked not yet in play');

            $drafting = vanilla_submit($match, 'host', 'confirmSetup', []);
            expect_same('draft', $drafting['lifecycle'], 'the draft opens');
            expect_same([$match['hero1']['seat'] => []], $drafting['draft']['heroesBySeat'], 'co-op draft lists heroes per seat');
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Captain']);
            vanilla_submit($match, 'hero1', 'pickHero', ['hero' => 'Test Marshal']);
            $active = vanilla_submit($match, 'hero1', 'pickArmy', ['army' => 'Red Company']);
            expect_same('active', $active['lifecycle'], 'the battle begins');
            expect_same($match['hero1']['seat'], $active['turn']['activeSeatId'], 'the hero player acts first');
            expect_same(null, $active['turn']['respondingToSeatId'], 'nobody is being answered on a hero turn');

            $voidTurn = vanilla_submit($match, 'hero1', 'endTurn', []);
            expect_same($match['host']['seat'], $voidTurn['turn']['activeSeatId'], 'the void lord answers');
            expect_same($match['hero1']['seat'], $voidTurn['turn']['respondingToSeatId'], 'the projection names who is being answered');

            vanilla_submit($match, 'host', 'endTurn', []);
            $statement = $match['db']->prepare('SELECT state_json FROM game_states WHERE game_id = :game');
            $statement->execute(['game' => $match['gameId']]);
            $state = json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            $heroUnitId = array_values(array_filter(
                $state['units'],
                static fn(array $unit): bool => $unit['seatId'] === $match['hero1']['seat'] && $unit['hex'] !== null
            ))[0]['unitId'];
            $occupied = array_filter(array_column($state['units'], 'hex'));
            $free = array_values(array_filter(
                \BloodAndBanners\Rules\Vanilla\Hex::neighbors($state['units'][$heroUnitId]['hex'], $state['board']),
                static fn(string $hex): bool => !in_array($hex, $occupied, true)
            ));
            $state['units']['v-m1'] = array_merge($state['units']['v-m1'], ['hex' => $free[0], 'hp' => 1, 'wounds' => 0, 'defense' => 0]);
            $state['units'][$heroUnitId]['power'] = 6;
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')
                ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);

            $won = vanilla_submit($match, 'hero1', 'attackUnit', ['unitId' => $heroUnitId, 'targetUnitId' => 'v-m1']);
            expect_same('completed', $won['lifecycle'], 'the match ends');
            expect_same('monstersDefeated', $won['result']['reason'], 'the last monster fell');
            expect_same('heroes', $won['result']['winningSide'], 'the heroes win');
            expect_same(null, $won['result']['winningSeatId'], 'no single seat wins a co-op match');
            expect_true(in_array('matchCompleted', array_column($match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)['events'], 'kind'), true), 'the completion is in the event log');
        },

        'duel projections gain winning side and keep their draft shape' => static function (): void {
            $match = vanilla_service_match();
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_true(!array_key_exists('heroesBySeat', $started['draft']), 'duels keep the single-hero draft');
            expect_same(null, $started['setup'], 'duels have no setup');
        },
    ];
}
