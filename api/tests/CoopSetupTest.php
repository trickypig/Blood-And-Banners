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

        'the shipped catalog has forty-four visible monsters' => static function (): void {
            expect_same(44, count((new Catalog())->monsters()), 'gameData.json ships 44 visible monsters');
        },

        'boards keep spawn zones from the scenario' => static function (): void {
            $board = Board::fromScenario(['map' => ['hexes' => [
                ['q' => 0, 'r' => 0, 'elevation' => 0, 'spawnZone' => 'void'],
                ['q' => 1, 'r' => 0, 'elevation' => 0],
            ]]]);
            expect_same('void', $board['0,0']['spawnZone'], 'a spawn hex keeps its zone');
            expect_same(null, $board['1,0']['spawnZone'], 'other hexes have no zone');
        },
    ];
}
