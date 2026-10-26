<?php
declare(strict_types=1);

use BloodAndBanners\Game\GameService;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Forced;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Modes\CoopMode;
use BloodAndBanners\Scenario\MapException;
use BloodAndBanners\Scenario\MapValidator;

/** A co-op start on the 9×9 board with the shipped catalog, which has the Void Portal. */
function void_portal_start(?array $board = null): array
{
    return (new MatchEngine(new Catalog()))->start(
        ['hero-a', 'void-lord'],
        $board ?? coop_board(),
        new SequenceRandom([15]),
        'coop',
        ['voidLordSeatId' => 'void-lord']
    )['state'];
}

function void_portal_command(array $state, string $seatId, string $kind, array $payload = [], array $rolls = []): array
{
    return (new MatchEngine(new Catalog()))->apply($state, $seatId, $kind, $payload, new SequenceRandom($rolls));
}

function coop_void_portal_tests(): array
{
    return [
        'a co-op start stands the void portal on the map marker for free' => static function (): void {
            $board = coop_board();
            $board['8,4']['voidPortal'] = true;
            $state = void_portal_start($board);
            $portal = $state['units'][CoopMode::VOID_PORTAL_ID];
            expect_same(['8,4', 'void-lord', 'void', 'Void Portal', 0, 5, 5, 0], [$portal['hex'], $portal['seatId'], $portal['side'], $portal['name'], $portal['move'], $portal['range'], $portal['power'], $portal['defense']], 'the portal is a fixed void unit');
            expect_true(CoopMode::isFixed($portal), 'the portal is marked fixed');
            expect_same(0, $state['setup']['spent'], 'the portal costs nothing');
            expect_true(Forced::immovable($state, CoopMode::VOID_PORTAL_ID), 'the portal cannot be pushed');
        },

        'without a marker the portal takes the open spawn hex farthest from the heroes' => static function (): void {
            $state = void_portal_start();
            $hex = $state['units'][CoopMode::VOID_PORTAL_ID]['hex'];
            $farthest = max(array_map(static fn(string $key): int => Hex::distance('1,4', $key), CoopMode::layout($state['board'])['spawn']));
            expect_same($farthest, Hex::distance('1,4', $hex), 'the farthest spawn hex is chosen');
            expect_true($state['board'][$hex]['voidPortal'], 'the board marks where it stands');
        },

        'the void lord cannot place, remove or confirm with only the portal' => static function (): void {
            $board = coop_board();
            $board['8,4']['voidPortal'] = true;
            $state = void_portal_start($board);
            $engine = new MatchEngine(new Catalog());
            $actions = $engine->legalActions($state, 'void-lord');
            expect_same([], array_values(array_filter($actions, static fn(array $a): bool => in_array($a['kind'], ['removeMonster', 'confirmSetup'], true))), 'nothing to remove or confirm yet');
            expect_same([], array_values(array_filter($actions, static fn(array $a): bool => ($a['monster'] ?? '') === 'Void Portal')), 'the portal is not in the summoning roster');
            foreach (array_filter($actions, static fn(array $a): bool => $a['kind'] === 'placeMonster') as $action) {
                expect_true(!in_array('8,4', $action['targetHexes'], true), 'the portal hex is taken');
            }
            expect_game_error('fixedMonster', static fn() => void_portal_command($state, 'void-lord', 'removeMonster', ['unitId' => CoopMode::VOID_PORTAL_ID]));
            expect_game_error('setupEmpty', static fn() => void_portal_command($state, 'void-lord', 'confirmSetup'));
            $placed = void_portal_command($state, 'void-lord', 'placeMonster', ['monster' => 'Ice Wolf', 'hex' => '7,4'])['state'];
            expect_same('Ice Wolf', $placed['units']['v-m1']['name'], 'monster ids are unchanged');
            $confirmed = void_portal_command($placed, 'void-lord', 'confirmSetup');
            expect_true($confirmed['state']['setup']['confirmed'], 'a placed monster lets the void lord confirm');
        },

        'the war room portal follows into the match and its hex stays marked' => static function (): void {
            $lobby = CoopMode::lobbySetup(coop_board(), 'void-lord', 20, new Catalog());
            $hex = $lobby['units'][CoopMode::VOID_PORTAL_ID]['hex'];
            expect_true($hex !== null, 'the war room board has the portal');
            $state = (new MatchEngine(new Catalog()))->start(['hero-a', 'void-lord'], coop_board(), new SequenceRandom([15]), 'coop',
                ['voidLordSeatId' => 'void-lord', 'setupState' => $lobby])['state'];
            expect_same($hex, $state['units'][CoopMode::VOID_PORTAL_ID]['hex'], 'the same portal stands');
            expect_true($state['board'][$hex]['voidPortal'], 'the fresh board marks its hex');
            expect_same(1, count(array_filter($state['units'], [CoopMode::class, 'isFixed'])), 'only one portal');
        },

        'destroying the portal wins for the heroes while monsters still stand' => static function (): void {
            $state = coop_battle([
                coop_hero('a-h', 'hero-a', '0,0', ['power' => 6]),
                vanilla_unit(CoopMode::VOID_PORTAL_ID, 'void-lord', '1,0', ['hp' => 1, 'defense' => 0, 'move' => 0, 'fixed' => true]),
                vanilla_unit('v-m1', 'void-lord', '8,0'),
            ]);
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'a-h', 'targetUnitId' => CoopMode::VOID_PORTAL_ID], [1, 1, 1, 1, 1, 1]);
            expect_same('completed', $result['state']['phase'], 'the match ends');
            expect_same(['reason' => 'portalDestroyed', 'winningSeatId' => null, 'winningSide' => 'heroes'], $result['state']['result'], 'the heroes win');
        },

        'the match goes on while the portal stands even when every monster has fallen' => static function (): void {
            $state = coop_battle([
                coop_hero('a-h', 'hero-a', '0,0', ['power' => 6]),
                vanilla_unit('v-m1', 'void-lord', '1,0', ['hp' => 1, 'defense' => 0]),
                vanilla_unit(CoopMode::VOID_PORTAL_ID, 'void-lord', '8,0', ['move' => 0, 'fixed' => true]),
            ]);
            $result = coop_command($state, 'hero-a', 'attackUnit', ['unitId' => 'a-h', 'targetUnitId' => 'v-m1'], [1, 1, 1, 1, 1, 1]);
            expect_same(null, $result['state']['units']['v-m1']['hex'], 'the monster fell');
            expect_same('active', $result['state']['phase'], 'the heroes must still reach the portal');
        },

        'the war room shows the void lord the portal and every viewer its hex' => static function (): void {
            [$db, $auth] = test_services();
            $games = new GameService($db, null, new ScriptedRandom(), new MatchEngine(new Catalog()));
            $host = $auth->createGuest('portal-host');
            $guest = $auth->createGuest('portal-guest');
            $created = $games->createGame($host['identityId'], 'coop', 'Void Reaches');
            $joined = $games->joinGame($created['gameId'], $guest['identityId']);
            $match = ['db' => $db, 'games' => $games, 'gameId' => $created['gameId'],
                'host' => ['identity' => $host['identityId'], 'token' => $created['seatToken'], 'seat' => $created['viewer']['seatId']],
                'hero1' => ['identity' => $guest['identityId'], 'token' => $joined['seatToken'], 'seat' => $joined['viewer']['seatId']]];
            $lord = vanilla_submit($match, 'host', 'claimVoidLord', []);
            $marked = array_values(array_filter($lord['board']['hexes'], static fn(array $hex): bool => $hex['voidPortal']));
            expect_same([[-4, 12, CoopMode::VOID_PORTAL_ID]], array_map(static fn(array $hex): array => [$hex['q'], $hex['r'], $hex['occupantId']], $marked), 'the void lord sees the portal on its hex');
            $hero = $games->getGame($match['gameId'], $guest['identityId']);
            expect_same(1, count(array_filter($hero['board']['hexes'], static fn(array $hex): bool => $hex['voidPortal'])), 'heroes see where the portal stands');
            expect_true(!in_array('removeMonster', array_column($lord['legalActions'], 'kind'), true), 'the portal cannot be removed');
        },

        'void reaches marks its portal opposite the hero start' => static function (): void {
            $board = Board::fromScenario(built_in_scenario('void-reaches'));
            $layout = CoopMode::layout($board);
            expect_same('-4,12', $layout['portal'], 'the map marker is used');
            expect_same('void', $board['-4,12']['spawnZone'], 'it stands in the void spawn zone');
        },

        'maps hold at most one void portal and the flag must be boolean' => static function (): void {
            $map = built_in_scenario('void-reaches');
            foreach ($map['map']['hexes'] as $index => $hex) {
                if ($hex['q'] === 0 && $hex['r'] === 0) {
                    $map['map']['hexes'][$index]['voidPortal'] = true;
                }
            }
            foreach ([$map, (static function (array $map): array {
                $map['map']['hexes'][0]['voidPortal'] = 'yes';
                return $map;
            })(built_in_scenario('void-reaches'))] as $bad) {
                try {
                    MapValidator::validate($bad);
                    throw new RuntimeException('the map should be rejected');
                } catch (MapException) {
                }
            }
            expect_same('Void Reaches', MapValidator::validate(built_in_scenario('void-reaches'))['name'], 'one portal is fine');
        },
    ];
}
