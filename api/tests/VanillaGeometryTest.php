<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;

function vanilla_geometry_tests(): array
{
    return [
        'hex distance and neighbours use axial coordinates' => static function (): void {
            expect_same(0, Hex::distance('2,3', '2,3'), 'a hex is zero steps from itself');
            expect_same(1, Hex::distance('0,0', '1,-1'), 'diagonal axial neighbour is one step');
            expect_same(3, Hex::distance('0,0', '-1,3'), 'distance follows cube rules');
            $board = ['0,0' => [], '1,0' => [], '0,1' => [], '5,5' => []];
            expect_same(['1,0', '0,1'], Hex::neighbors('0,0', $board), 'only on-board neighbours are returned in direction order');
            expect_same([-2, 7], Hex::parse('-2,7'), 'keys parse to integers');
        },

        'catalog copies hero and army stats from game data' => static function (): void {
            $catalog = test_catalog();
            expect_same(
                ['name' => 'Test Marshal', 'image' => '/images/heroes/marshal.png', 'move' => 4, 'range' => 2, 'power' => 3, 'defense' => 3, 'hp' => 5],
                $catalog->hero('Test Marshal'),
                'hero stats should be copied without abilities'
            );
            expect_same(null, $catalog->hero('Nobody'), 'unknown heroes resolve to null');
            $army = $catalog->army('Blue Company');
            expect_same('Blue', $army['color'], 'army colour should be kept');
            expect_same(6, count($army['units']), 'armies carry six units');
            expect_same('Blue Bow', $army['units'][2]['name'], 'army units keep catalog order');
            expect_same(3, count($catalog->heroes()), 'all heroes are listed');
        },

        'the shipped catalog loads every hero and army' => static function (): void {
            $catalog = new Catalog();
            expect_same(8, count($catalog->heroes()), 'gameData.json ships eight heroes');
            expect_same(5, count($catalog->armies()), 'gameData.json ships five armies');
            foreach ($catalog->armies() as $army) {
                expect_same(6, count($army['units']), "{$army['name']} should field six units");
            }
        },
    ];
}
