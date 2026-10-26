<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;

function coop_setup_tests(): array
{
    return [
        'the catalog offers visible monsters with biome and tier' => static function (): void {
            $catalog = test_catalog();
            expect_same(['Test Wolf', 'Test Golem', 'Test Wyrm'], array_column($catalog->monsters(), 'name'), 'hidden monsters are left out');
            expect_same(null, $catalog->monster('Test Phantom'), 'a hidden monster cannot be looked up');
            $wyrm = $catalog->monster('Test Wyrm');
            expect_same(['Ice', 'boss', 10], [$wyrm['biome'], $wyrm['tier'], $wyrm['hp']], 'stats, biome and tier are loaded');
            expect_same('', $wyrm['abilities'][0]['type'], 'an ability without a type loads as an empty type');
        },

        'the shipped catalog has forty-three placeable monsters and the fixed void portal' => static function (): void {
            $catalog = new Catalog();
            expect_same(43, count($catalog->monsters()), 'gameData.json ships 43 placeable monsters');
            expect_same(null, $catalog->monster('Void Portal'), 'the void lord cannot place the portal');
            $portal = $catalog->voidPortal();
            expect_same([0, 5, 5, 0, 15, 'boss'], [$portal['move'], $portal['range'], $portal['power'], $portal['defense'], $portal['hp'], $portal['tier']], 'portal stats');
            expect_same(['Unmovable'], array_column($portal['abilities'], 'name'), 'the portal cannot be pushed');
        },

        'boards keep spawn zones from the scenario' => static function (): void {
            $board = Board::fromScenario(['map' => ['hexes' => [
                ['q' => 0, 'r' => 0, 'elevation' => 0, 'spawnZone' => 'void'],
                ['q' => 1, 'r' => 0, 'elevation' => 0],
            ]]]);
            expect_same('void', $board['0,0']['spawnZone'], 'a spawn hex keeps its zone');
            expect_same(null, $board['1,0']['spawnZone'], 'other hexes have no zone');
        },

        'a co-op match opens with the void lord setting up and hero initiative rolled' => static function (): void {
            $started = coop_start();
            $state = $started['state'];
            expect_same(['coop', 'prep', 'void-lord'], [$state['mode'], $state['phase'], $state['voidLordSeatId']], 'preparation opens for both sides');
            expect_same(['hero-a', 'hero-b'], $state['initiative']['order'], 'only hero players roll initiative');
            expect_same(['hero-a', 'hero-b'], $state['sides']['heroes']['seatIds'], 'heroes share one side in initiative order');
            expect_same(['void-lord'], $state['sides']['void']['seatIds'], 'the void lord is the void side');
            expect_same(20, $state['setup']['budget'], 'budget is base 4 plus 8 per hero player');
            expect_same('initiativeRolled', $started['events'][0]['kind'], 'initiative is announced');
            $engine = new \BloodAndBanners\Rules\Vanilla\MatchEngine(test_catalog());
            expect_same(['pickHero', 'pickArmy'], array_column($engine->legalActions($state, 'hero-a'), 'kind'), 'the first hero drafts during setup');
            expect_same(['placeMonster'], array_values(array_unique(array_column($engine->legalActions($state, 'void-lord'), 'kind'))), 'the void lord may place');
        },

        'co-op start needs a void lord, a hero player and a co-op battlefield' => static function (): void {
            $engine = new \BloodAndBanners\Rules\Vanilla\MatchEngine(test_catalog());
            $none = new SequenceRandom([]);
            expect_game_error('voidLordRequired', static fn() => $engine->start(['a', 'b'], coop_board(), $none, 'coop', []));
            expect_game_error('heroPlayerRequired', static fn() => $engine->start(['void-lord'], coop_board(), $none, 'coop', ['voidLordSeatId' => 'void-lord']));
            expect_game_error('invalidCoopScenario', static fn() => $engine->start(['a', 'void-lord'], proving_field_board(), $none, 'coop', ['voidLordSeatId' => 'void-lord']));
        },

        'placing monsters spends the budget inside spawn zones and announces them' => static function (): void {
            $state = coop_start()['state'];
            $placed = coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wyrm', 'hex' => '8,4']);
            $wyrm = $placed['state']['units']['v-m1'];
            expect_same(['void-lord', 'void', 'boss', '8,4', []], [$wyrm['seatId'], $wyrm['side'], $wyrm['tier'], $wyrm['hex'], $wyrm['abilities']], 'the monster joins the void side without live abilities');
            expect_same('Frost Breath', $wyrm['inactiveAbilities'][0]['name'], 'printed abilities are kept for display');
            expect_same(4, $placed['state']['setup']['spent'], 'a boss costs 4');
            expect_same(['monsterPlaced'], array_column($placed['events'], 'kind'), 'placement is announced');
            expect_same(['unitId' => 'v-m1', 'monster' => 'Test Wyrm', 'hex' => '8,4', 'cost' => 4], $placed['events'][0]['payload'], 'the event names the monster');
        },

        'setup refuses illegal placements with specific errors' => static function (): void {
            $board = coop_board();
            $board['8,0']['terrain'] = 'lava';
            $board['8,1']['sightBlocker'] = true;
            $state = coop_command(coop_start($board)['state'], 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,0'])['state'];
            expect_game_error('notVoidLord', static fn() => coop_command($state, 'hero-a', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,1']));
            expect_game_error('unknownMonster', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Phantom', 'hex' => '7,1']));
            expect_game_error('unknownMonster', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => ['x'], 'hex' => '7,1']));
            expect_game_error('outsideSpawnZone', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '6,1']));
            expect_game_error('outsideSpawnZone', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf']));
            expect_game_error('hexOccupied', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,0']));
            expect_game_error('hexNotEnterable', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '8,0']));
            expect_game_error('hexNotEnterable', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '8,1']));
            foreach (['7,2', '7,3', '7,4', '7,5'] as $hex) {
                $state = coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wyrm', 'hex' => $hex])['state'];
            }
            expect_same(17, $state['setup']['spent'], 'one wolf and four wyrms cost 17');
            expect_game_error('budgetExceeded', static fn() => coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wyrm', 'hex' => '7,6']));
        },

        'removing a monster refunds it and ids are never reused' => static function (): void {
            $state = coop_start()['state'];
            $state = coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Golem', 'hex' => '7,1'])['state'];
            $removed = coop_command($state, 'void-lord', 'removeMonster', ['unitId' => 'v-m1']);
            expect_same([], $removed['state']['units'], 'the monster leaves the board');
            expect_same(0, $removed['state']['setup']['spent'], 'its cost is refunded');
            expect_same('monsterRemoved', $removed['events'][0]['kind'], 'removal is announced');
            expect_game_error('unknownUnit', static fn() => coop_command($removed['state'], 'void-lord', 'removeMonster', ['unitId' => 'v-m1']));
            $again = coop_command($removed['state'], 'void-lord', 'placeMonster', ['monster' => 'Test Golem', 'hex' => '7,1'])['state'];
            expect_same(['v-m2'], array_keys($again['units']), 'the next monster gets a fresh id');
        },

        'confirming setup needs a monster and locks the horde' => static function (): void {
            $state = coop_start()['state'];
            expect_game_error('setupEmpty', static fn() => coop_command($state, 'void-lord', 'confirmSetup'));
            $state = coop_command($state, 'void-lord', 'placeMonster', ['monster' => 'Test Wolf', 'hex' => '7,1'])['state'];
            $confirmed = coop_command($state, 'void-lord', 'confirmSetup');
            expect_same('prep', $confirmed['state']['phase'], 'the draft keeps running');
            expect_same(true, $confirmed['state']['setup']['confirmed'], 'setup is locked');
            expect_same('hero-a', $confirmed['state']['draft']['pickingSeatId'], 'the initiative winner picks first');
            expect_same(['kind' => 'setupConfirmed', 'focusHexes' => [], 'payload' => []], $confirmed['events'][0], 'confirmation is announced');
        },
    ];
}
