<?php
declare(strict_types=1);

use BloodAndBanners\Auth\AuthService;
use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\RandomSource;
use BloodAndBanners\Rules\Vanilla\IceMovement;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Turn;
use BloodAndBanners\Rules\Vanilla\UnitConditions;

final class ScriptedRandom implements RandomSource
{
    private int $d20 = 0;

    public function roll(int $sides): int
    {
        if ($sides === 20) {
            return [20, 10][$this->d20++ % 2];
        }
        return 1;
    }
}

function vanilla_service_match(string $scenario = 'Proving Field', string $mode = 'vanillaPvp'): array
{
    [$db, $auth] = test_services();
    $games = new GameService($db, null, new ScriptedRandom(), new MatchEngine(test_catalog()));
    $host = $auth->createGuest('vanilla-host-' . bin2hex(random_bytes(4)));
    $rival = $auth->createGuest('vanilla-rival-' . bin2hex(random_bytes(4)));
    $created = $games->createGame($host['identityId'], $mode, $scenario);
    $joined = $games->joinGame($created['gameId'], $rival['identityId']);
    $games->setReady($created['gameId'], $host['identityId'], $created['seatToken'], true);
    $games->setReady($created['gameId'], $rival['identityId'], $joined['seatToken'], true);
    return [
        'db' => $db,
        'games' => $games,
        'gameId' => $created['gameId'],
        'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']],
        'rival' => ['identity' => $rival['identityId'], 'token' => $joined['seatToken'], 'seat' => $joined['viewer']['seatId']],
    ];
}

function vanilla_submit(array $match, string $who, string $kind, array $payload, ?string $commandId = null): array
{
    $current = $match['games']->getGame($match['gameId'], $match[$who]['identity']);
    return $match['games']->submitCommand($match['gameId'], $match[$who]['identity'], $match[$who]['token'], [
        'commandId' => $commandId ?? bin2hex(random_bytes(8)),
        'expectedVersion' => $current['version'],
        'kind' => $kind,
        'payload' => $payload,
    ]);
}

function vanilla_draft_picks(array $match): array
{
    return [
        ['host', 'chooseFirstPlayer', ['seatId' => $match['host']['seat']]],
        ['rival', 'chooseStartPosition', ['start' => 2]],
        ['rival', 'pickHero', ['hero' => 'Test Marshal']],
        ['host', 'pickHero', ['hero' => 'Test Captain']],
        ['host', 'pickArmy', ['army' => 'Red Company']],
        ['rival', 'pickArmy', ['army' => 'Blue Company']],
    ];
}

function vanilla_match_service_tests(): array
{
    return [
        'starting a vanilla duel rolls initiative and opens the draft for the winner' => static function (): void {
            $match = vanilla_service_match();
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            expect_same('draft', $started['lifecycle'], 'the match enters the draft');
            expect_same($match['host']['seat'], $started['draft']['pickingSeatId'], 'the host rolled 20 and picks first');
            expect_same([20], $started['draft']['initiativeRolls'][$match['host']['seat']], 'rolls are projected per seat');
            expect_same(
                [$match['host']['seat'] => null, $match['rival']['seat'] => null],
                $started['draft']['heroBySeat'],
                'undecided maps always carry both seats'
            );
            expect_same(['chooseFirstPlayer', 'chooseStartPosition', 'pickHero', 'pickArmy', 'setCommanderName'], array_column($started['legalActions'], 'kind'), 'the picker sees every open decision');
            expect_same(5, count($started['draft']['availableHeroes']), 'the catalog heroes are offered');
            expect_same(null, $started['draft']['availableHeroes'][0]['takenBySeatId'], 'no hero is taken yet');
            $armies = array_column($started['draft']['availableArmies'], null, 'name');
            expect_same('/images/armies/red-company.svg', $armies['Red Company']['emblem'], 'armies carry their emblem');
            expect_same(null, $armies['Blue Company']['emblem'], 'an army without an emblem projects null');
            expect_same(null, $started['armies'], 'no seat has an army before the draft picks');
            expect_same(['setCommanderName'], array_column($match['games']->getGame($match['gameId'], $match['rival']['identity'])['legalActions'], 'kind'), 'the rival waits but may rename');
            $events = $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0)['events'];
            expect_same(['gameStarted', 'initiativeRolled'], array_slice(array_column($events, 'kind'), -2), 'start and initiative are logged');
        },

        'battle royale cannot start yet and small duel maps are refused' => static function (): void {
            $royale = vanilla_service_match('Last Banner', 'battleRoyale');
            expect_game_error('modeNotPlayable', static fn() => $royale['games']->startGame($royale['gameId'], $royale['host']['identity'], $royale['host']['token']));
            expect_same('lobby', $royale['games']->getGame($royale['gameId'], $royale['host']['identity'])['lifecycle'], 'a refused start leaves the lobby intact');

            $small = vanilla_service_match('Training Grounds');
            expect_game_error('scenarioTooSmall', static fn() => $small['games']->startGame($small['gameId'], $small['host']['identity'], $small['host']['token']));
        },

        'draft commands are validated versioned and idempotent' => static function (): void {
            $match = vanilla_service_match();
            $started = $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);

            $first = vanilla_submit($match, 'host', 'pickHero', ['hero' => 'Test Captain'], 'pick-once');
            expect_same($started['version'] + 1, $first['version'], 'a legal pick advances the version');
            expect_same('Test Captain', $first['draft']['heroBySeat'][$match['host']['seat']], 'the pick is projected');

            $replayed = $match['games']->submitCommand($match['gameId'], $match['host']['identity'], $match['host']['token'], [
                'commandId' => 'pick-once', 'expectedVersion' => $started['version'], 'kind' => 'pickHero', 'payload' => ['hero' => 'Test Captain'],
            ]);
            expect_same($first['version'], $replayed['version'], 'a retried command returns the cached projection');

            try {
                vanilla_submit($match, 'rival', 'pickHero', ['hero' => 'Test Captain']);
                throw new RuntimeException('a taken hero was accepted');
            } catch (\BloodAndBanners\Game\GameServiceException $error) {
                expect_same('heroTaken', $error->errorCode, 'taken heroes are refused');
                expect_same($first['version'], $error->currentVersion, 'refusals report the current version');
            }
            expect_same($first['version'], $match['games']->getGame($match['gameId'], $match['host']['identity'])['version'], 'a refusal changes nothing');
        },

        'a drafted match projects units turn state and battle actions' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                $projection = vanilla_submit($match, $who, $kind, $payload);
            }
            $projection = $match['games']->getGame($match['gameId'], $match['host']['identity']);

            expect_same('active', $projection['lifecycle'], 'the finished draft starts the battle');
            expect_same(null, $projection['draft'], 'the draft block disappears after the draft');
            expect_same(
                [
                    $match['host']['seat'] => ['name' => 'Red Company', 'emblem' => '/images/armies/red-company.svg'],
                    $match['rival']['seat'] => ['name' => 'Blue Company', 'emblem' => null],
                ],
                $projection['armies'],
                'each seat keeps its drafted army and emblem into the battle'
            );
            expect_same(14, count($projection['units']), 'both armies are projected');
            expect_same('s1-hero', $projection['units'][0]['unitId'], 'the first seat hero leads the unit list');
            expect_same('s1-u1', $projection['units'][1]['unitId'], 'units follow their hero in numeric order');
            expect_same('s2-hero', $projection['units'][7]['unitId'], 'the second seat hero starts the second army');
            expect_same(
                ['unitId', 'seatId', 'isHero', 'name', 'image', 'avatarImage', 'move', 'range', 'power', 'defense', 'hp', 'wounds', 'hex', 'activatedThisTurn',
                    'abilities', 'side', 'tier', 'inactiveAbilities', 'effects', 'conditions', 'effectiveMove', 'effectiveRange', 'effectivePower', 'effectiveDefense'],
                array_keys($projection['units'][0]),
                'unit projections match the shared contract'
            );
            expect_same('/images/units/red_spear_nobg.png', $projection['units'][1]['avatarImage'], 'units carry their background-free map avatar');
            expect_same(null, $projection['units'][0]['avatarImage'], 'cards without an avatar project null');
            expect_same(['round' => 1, 'activeSeatId' => $match['host']['seat'], 'activationsRemaining' => 4, 'activeUnitId' => null, 'respondingToSeatId' => null], $projection['turn'], 'the host opens round one');
            $captain = array_values(array_filter($projection['units'], static fn(array $unit): bool => $unit['name'] === 'Test Captain'))[0];
            expect_same(
                [['name' => 'Shield Wall', 'type' => 'Ability', 'description' => 'Grant one adjacent unit +1 Defense until the start of your next turn', 'playable' => true, 'usedThisTurn' => false]],
                $captain['abilities'],
                'units project their abilities'
            );
            expect_same([], $captain['effects'], 'no effects yet');
            expect_same($captain['defense'], $captain['effectiveDefense'], 'effective stats match base with no modifiers');
            foreach ($projection['legalActions'] as $action) {
                expect_true(array_key_exists('ability', $action), 'every legal action carries an ability key');
            }
            expect_same(null, $projection['pendingChoiceSummary'], 'nothing is pending');
            expect_true(in_array('moveUnit', array_column($projection['legalActions'], 'kind'), true), 'the host may move');
            $occupied = array_values(array_filter($projection['board']['hexes'], static fn(array $hex): bool => $hex['q'] === 3 && $hex['r'] === 2));
            expect_same('s1-hero', $occupied[0]['occupantId'], 'the board shows live occupants');

            $link = $match['games']->createSpectatorLink($match['gameId'], $match['host']['identity'], $match['host']['token']);
            $spectated = $match['games']->getSpectatorGame($link['token']);
            expect_same([], $spectated['legalActions'], 'spectators cannot act');
            expect_same(14, count($spectated['units']), 'spectators still see the armies');
            $watched = $match['games']->getWatchedGame($match['gameId']);
            expect_same([], $watched['legalActions'], 'watchers cannot act');
            expect_same('spectator', $watched['viewer']['role'], 'watchers are spectators');
            expect_same(
                $match['games']->getEvents($match['gameId'], $match['host']['identity'], $match['host']['token'], 0),
                $match['games']->getWatchedEvents($match['gameId'], 0),
                'watchers read the same event log as players'
            );
        },

        'the defending seat resolves a Moonlight Glare choice through the service while the attacker waits' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $statement = $match['db']->prepare('SELECT state_json FROM game_states WHERE game_id = :game');
            $statement->execute(['game' => $match['gameId']]);
            $state = json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            $attacker = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['seatId'] === $match['host']['seat']))[0]['unitId'];
            $defender = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['seatId'] === $match['rival']['seat']))[0]['unitId'];
            $state['pending'] = [
                'choice' => [
                    'choiceId' => 'choice-1', 'seatId' => $match['rival']['seat'], 'kind' => 'moonlightGlare', 'unitId' => $defender,
                    'side' => 'defense', 'faces' => ['blank'], 'options' => ['reroll:0', 'keep'], 'openedAt' => time(),
                ],
                'steps' => [[
                    'op' => 'land', 'attackerId' => $attacker, 'targetId' => $defender, 'ability' => null,
                    'attackFaces' => ['blank'], 'defenseFaces' => ['blank'], 'rerolled' => null,
                ]],
            ];
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')
                ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);

            $hostView = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same(null, $hostView['pendingChoice'], 'the attacker never sees the defender\'s dice');
            expect_same('choice-1', $hostView['pendingChoiceSummary']['choiceId'], 'the attacker knows a decision is pending');
            expect_same([], $hostView['legalActions'], 'the attacker can do nothing before the timeout');
            expect_game_error('choicePending', static fn() => vanilla_submit($match, 'host', 'endTurn', []));

            $rivalView = $match['games']->getGame($match['gameId'], $match['rival']['identity']);
            expect_same(['reroll:0', 'keep'], $rivalView['pendingChoice']['options'], 'the defender sees the choice');
            expect_same(['blank'], $rivalView['pendingChoice']['faces'], 'with its dice');

            $resolved = vanilla_submit($match, 'rival', 'resolveChoice', ['choiceId' => 'choice-1', 'option' => 'keep']);
            expect_same(null, $resolved['pendingChoiceSummary'], 'the choice is closed');
            expect_same($match['host']['seat'], $resolved['turn']['activeSeatId'], 'the turn stays with the attacker');
        },

        'terrain movement and ice choices project only to the commanding seat' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $statement = $match['db']->prepare('SELECT state_json FROM game_states WHERE game_id = :game');
            $statement->execute(['game' => $match['gameId']]);
            $state = json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            $hostIds = array_keys(array_filter($state['units'], static fn(array $unit): bool => $unit['seatId'] === $match['host']['seat']));
            $rivalIds = array_keys(array_filter($state['units'], static fn(array $unit): bool => $unit['seatId'] === $match['rival']['seat']));
            foreach ($state['units'] as $unitId => $unit) {
                $state['units'][$unitId]['hex'] = null;
            }
            $moverId = (string)$hostIds[0];
            $allyId = (string)$hostIds[1];
            $enemyId = (string)$rivalIds[0];
            $state['board'] = vanilla_board(
                ['0,0' => 0, '1,0' => 0, '2,0' => 0, '3,0' => 0, '0,1' => 0],
                [],
                ['1,0' => ['biome' => 'lava', 'terrain' => 'lava']]
            );
            $state['units'][$moverId]['hex'] = '0,0';
            $state['units'][$moverId]['move'] = 2;
            $state['units'][$enemyId]['hex'] = '3,0';
            $state['conditions'] = [];
            $state['pending'] = null;
            $state['turn'] = Turn::fresh($state, $match['host']['seat'], 1);
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')
                ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);

            $hostView = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $move = array_values(array_filter($hostView['legalActions'], static fn(array $action): bool => $action['kind'] === 'moveUnit'))[0];
            $neutral = array_values(array_filter($move['movementOptions'], static fn(array $option): bool => $option['destination'] === '0,1'))[0];
            expect_same(['0,1'], $neutral['path'], 'neutral movement publishes its exact canonical path');
            expect_same([], $neutral['hazards'], 'neutral movement has no hazards');
            $lava = array_values(array_filter($move['movementOptions'], static fn(array $option): bool => $option['destination'] === '1,0'))[0];
            expect_same(['1,0'], $lava['path'], 'lava movement publishes its exact canonical path');
            expect_same([
                'kind' => 'damage', 'terrain' => 'lava', 'hex' => '1,0',
                'dice' => 5, 'forced' => false, 'stops' => false,
            ], $lava['hazards'][0], 'lava projection carries the five guaranteed dice');
            expect_same([], $match['games']->getGame($match['gameId'], $match['rival']['identity'])['legalActions'], 'the waiting seat receives no commands');
            $link = $match['games']->createSpectatorLink($match['gameId'], $match['host']['identity'], $match['host']['token']);
            expect_same([], $match['games']->getSpectatorGame($link['token'])['legalActions'], 'spectators receive no commands');

            $state['board'] = vanilla_board(vanilla_line(3), [], ice_paint(['0,0', '1,0', '2,0']));
            foreach ($state['units'] as $unitId => $unit) {
                $state['units'][$unitId]['hex'] = null;
            }
            $state['units'][$moverId]['hex'] = '0,0';
            $state['units'][$allyId]['hex'] = '1,0';
            $state['units'][$enemyId]['hex'] = '2,0';
            $state['turn'] = Turn::fresh($state, $match['host']['seat'], 1);
            $conditionEvents = [];
            $state = UnitConditions::apply($state, 'Unsteady', $moverId, null, ['kind' => 'whileOnTerrain'], $conditionEvents);
            $state = UnitConditions::apply($state, 'Slipping', $moverId, null, ['kind' => 'untilCleared'], $conditionEvents, ['direction' => [1, 0]]);
            $iceEvents = [];
            $state = IceMovement::continue($state, $moverId, 2, new SequenceRandom([]), $iceEvents)['state'];
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')
                ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);

            $owner = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            expect_same('iceCollision', $owner['pendingChoice']['kind'], 'the owner sees the generic choice kind');
            expect_same(['stop'], $owner['pendingChoice']['options'], 'the owner sees only server-approved options');
            expect_same([], $owner['pendingChoice']['targetHexes'], 'no legal Swing exits project in this corridor');
            expect_true(!array_key_exists('context', $owner['pendingChoice']), 'internal continuation context never projects');
            expect_same(['resolveChoice'], array_column($owner['legalActions'], 'kind'), 'only resolution remains actionable');
            $projectedMover = array_values(array_filter($owner['units'], static fn(array $unit): bool => $unit['unitId'] === $moverId))[0];
            $slipping = array_values(array_filter($projectedMover['conditions'], static fn(array $condition): bool => $condition['name'] === 'Slipping'))[0];
            expect_same('{"direction":[1,0]}', json_encode($slipping['data'], JSON_THROW_ON_ERROR), 'condition data carries the locked direction as a JSON object');

            $opponent = $match['games']->getGame($match['gameId'], $match['rival']['identity']);
            expect_same(null, $opponent['pendingChoice'], 'the opponent cannot inspect actionable choice details');
            expect_same('iceCollision', $opponent['pendingChoiceSummary']['kind'], 'the opponent sees why play is waiting');
            expect_same(null, $opponent['pendingChoiceSummary']['keepableAt'], 'ice choices never gain a timeout keep action');
            expect_same([], $opponent['legalActions'], 'the opponent cannot resolve the owner choice');
            $spectator = $match['games']->getSpectatorGame($link['token']);
            expect_same(null, $spectator['pendingChoice'], 'spectators never receive actionable choice details');
            expect_same('iceCollision', $spectator['pendingChoiceSummary']['kind'], 'spectators receive only the non-actionable choice summary');
            expect_same([], $spectator['legalActions'], 'spectators remain unable to resolve pending choices');
        },

        'a stunned unit projects its condition and its weakened Power' => static function (): void {
            $match = vanilla_service_match();
            $match['games']->startGame($match['gameId'], $match['host']['identity'], $match['host']['token']);
            foreach (vanilla_draft_picks($match) as [$who, $kind, $payload]) {
                vanilla_submit($match, $who, $kind, $payload);
            }
            $statement = $match['db']->prepare('SELECT state_json FROM game_states WHERE game_id = :game');
            $statement->execute(['game' => $match['gameId']]);
            $state = json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            $victim = array_values(array_filter($state['units'], static fn(array $unit): bool => $unit['seatId'] === $match['rival']['seat']))[0];
            $state['conditions'] = [[
                'name' => 'Stunned', 'unitId' => $victim['unitId'], 'sourceUnitId' => null,
                'expires' => ['kind' => 'afterTurn', 'turnNumber' => $state['turn']['number'] + 1], 'data' => [],
            ]];
            $match['db']->prepare('UPDATE game_states SET state_json = :state WHERE game_id = :game')
                ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'game' => $match['gameId']]);

            $view = $match['games']->getGame($match['gameId'], $match['host']['identity']);
            $unit = array_values(array_filter($view['units'], static fn(array $unit): bool => $unit['unitId'] === $victim['unitId']))[0];
            expect_true($unit['conditions'][0]['data'] instanceof \stdClass, 'empty condition data remains a JSON object for the client contract');
            expect_same(
                '{"name":"Stunned","expires":"endOfNextTurn","data":{}}',
                json_encode($unit['conditions'][0], JSON_THROW_ON_ERROR),
                'the actual PHP projection serializes empty condition data as an object'
            );
            expect_same(max(0, $victim['power'] - 2), $unit['effectivePower'], 'its Power is shown reduced');
        },
    ];
}
