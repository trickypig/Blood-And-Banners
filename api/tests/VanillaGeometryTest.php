<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\Catalog;
use BloodAndBanners\Rules\Vanilla\Hex;
use BloodAndBanners\Rules\Vanilla\Placement;

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
                ['name' => 'Test Marshal', 'image' => '/images/heroes/marshal.png', 'move' => 4, 'range' => 2, 'power' => 3, 'defense' => 3, 'hp' => 5, 'abilities' => []],
                $catalog->hero('Test Marshal'),
                'hero stats and abilities are copied'
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
        'proving field places both armies deterministically beside their portals' => static function (): void {
            $board = proving_field_board();
            $ids = ['hero', 'u1', 'u2', 'u3', 'u4', 'u5', 'u6'];
            $first = Placement::place($board, '-1,4', '7,4', $ids, []);
            expect_same(
                ['hero' => '0,4', 'u1' => '-1,5', 'u2' => '0,3', 'u3' => '-2,4', 'u4' => '-2,5', 'u5' => '-1,3', 'u6' => '1,4'],
                $first,
                'start 1 fills its portal ring nearest the enemy, then clusters around home'
            );
            $second = Placement::place($board, '7,4', '-1,4', $ids, array_fill_keys(array_values($first), true));
            expect_same(
                ['hero' => '6,4', 'u1' => '6,5', 'u2' => '7,3', 'u3' => '7,5', 'u4' => '8,3', 'u5' => '8,4', 'u6' => '5,4'],
                $second,
                'start 2 mirrors the same rules'
            );
            Placement::assertCapacity($board, 7);
        },

        'training grounds is too small for two full armies' => static function (): void {
            $definition = json_decode((string)file_get_contents(dirname(__DIR__) . '/scenarios/training-grounds.json'), true, flags: JSON_THROW_ON_ERROR);
            expect_game_error('scenarioTooSmall', static fn() => Placement::assertCapacity(Board::fromScenario($definition), 7));
        },

        'engine board keeps elevation sight blockers and homes' => static function (): void {
            $board = proving_field_board();
            expect_same(99, count($board), 'every scenario hex becomes a board hex');
            expect_same(['elevation' => 2, 'sightBlocker' => true, 'playerStart' => null], $board['5,1'], 'tall crags block sight');
            expect_same(['elevation' => 0, 'sightBlocker' => true, 'playerStart' => null], $board['3,1'], 'statues block sight');
            expect_same([1 => '-1,4', 2 => '7,4'], Board::homes($board), 'homes are keyed by start number');
        },
    ];
}
