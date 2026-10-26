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
                ['name' => 'Test Marshal', 'image' => '/images/heroes/marshal.png', 'avatarImage' => null, 'move' => 4, 'range' => 2, 'power' => 3, 'defense' => 3, 'hp' => 5, 'abilities' => []],
                $catalog->hero('Test Marshal'),
                'hero stats and abilities are copied'
            );
            expect_same(null, $catalog->hero('Nobody'), 'unknown heroes resolve to null');
            $army = $catalog->army('Blue Company');
            expect_same('Blue', $army['color'], 'army colour should be kept');
            expect_same(6, count($army['units']), 'armies carry six units');
            expect_same('Blue Bow', $army['units'][2]['name'], 'army units keep catalog order');
            expect_same(5, count($catalog->heroes()), 'all heroes are listed');
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
            $first = Placement::place($board, '4,1', '0,9', $ids, []);
            expect_same(
                ['hero' => '3,2', 'u1' => '4,2', 'u2' => '3,1', 'u3' => '5,1', 'u4' => '4,0', 'u5' => '5,0', 'u6' => '2,3'],
                $first,
                'start 1 fills its portal ring nearest the enemy, then clusters around home'
            );
            $second = Placement::place($board, '0,9', '4,1', $ids, array_fill_keys(array_values($first), true));
            expect_same(
                ['hero' => '0,8', 'u1' => '1,8', 'u2' => '-1,9', 'u3' => '1,9', 'u4' => '-1,10', 'u5' => '0,10', 'u6' => '0,7'],
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
            expect_same(['elevation' => 2, 'sightBlocker' => true, 'impassable' => true, 'playerStart' => null, 'biome' => 'stone', 'terrain' => 'crags', 'spawnZone' => null, 'voidPortal' => false, 'object' => null, 'radius' => 1], $board['-1,5'], 'tall crags retain their paint and block sight');
            expect_same(['elevation' => 0, 'sightBlocker' => true, 'impassable' => true, 'playerStart' => null, 'biome' => 'plains', 'terrain' => 'grass', 'spawnZone' => null, 'voidPortal' => false, 'object' => 'statue', 'radius' => 1], $board['0,3'], 'statues retain ground paint and block sight');
            expect_same([1 => '4,1', 2 => '0,9'], Board::homes($board), 'homes are keyed by start number');
        },
    ];
}
