<?php
declare(strict_types=1);

use BloodAndBanners\Rules\Vanilla\Abilities\AbilityRegistry;
use BloodAndBanners\Rules\Vanilla\Board;
use BloodAndBanners\Rules\Vanilla\MatchEngine;
use BloodAndBanners\Rules\Vanilla\Stats;
use BloodAndBanners\Rules\Vanilla\Terrain;

function terrain_unit(string $unitId, string $seatId, string $hex, array $abilities = [], array $stats = []): array
{
    return vanilla_unit($unitId, $seatId, $hex, $stats + [
        'abilities' => array_map(
            static fn(string $name): array => ['name' => $name, 'type' => 'Essence', 'description' => ''],
            $abilities
        ),
        'flags' => [],
    ]);
}

function vanilla_terrain_tests(): array
{
    return [
        'engine boards retain painted terrain and old boards normalize to neutral ground' => static function (): void {
            $board = Board::fromScenario([
                'map' => ['hexes' => [[
                    'q' => 2, 'r' => 3, 'biome' => 'water', 'terrain' => 'open-water',
                    'elevation' => -1, 'sightBlocker' => false, 'playerStart' => 1,
                ]]],
            ]);
            expect_same(
                ['elevation' => -1, 'sightBlocker' => false, 'playerStart' => 1, 'biome' => 'water', 'terrain' => 'open-water'],
                $board['2,3'],
                'the engine must retain painted terrain from the scenario'
            );

            $old = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0]), [
                vanilla_unit('a1', 'seat-a', '0,0'),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            unset($old['board']['0,0']['biome'], $old['board']['0,0']['terrain']);
            $old['board']['1,0']['biome'] = 'lava';
            $old['board']['1,0']['terrain'] = 'basalt';
            $normal = MatchEngine::normalize($old);
            expect_same(['biome' => 'plains', 'terrain' => 'grass'], array_intersect_key($normal['board']['0,0'], ['biome' => true, 'terrain' => true]), 'missing paint should become neutral');
            expect_same(['biome' => 'lava', 'terrain' => 'basalt'], array_intersect_key($normal['board']['1,0'], ['biome' => true, 'terrain' => true]), 'present paint must not be overwritten');
        },

        'only the four approved painted subtypes classify as terrain rules' => static function (): void {
            foreach ([
                'open-water' => 'water',
                'ice' => 'ice',
                'poison-pits' => 'poison',
                'lava' => 'lava',
            ] as $paint => $type) {
                expect_same($type, Terrain::type(['terrain' => $paint]), "{$paint} should classify as {$type}");
            }
            foreach (['shallow-water', 'snow', 'basalt', 'darkness', 'grass'] as $paint) {
                expect_same(null, Terrain::type(['terrain' => $paint]), "{$paint} should remain ordinary terrain");
            }
        },

        'open water lowers Power while shallow water remains neutral' => static function (): void {
            $water = ['0,0' => ['biome' => 'water', 'terrain' => 'open-water']];
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0], [], $water), [
                vanilla_unit('a1', 'seat-a', '0,0', ['power' => 3]),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(2, Stats::effective($state, 'a1', 'power'), 'open water should cost one Power');

            $state['board']['0,0']['terrain'] = 'shallow-water';
            expect_same(3, Stats::effective($state, 'a1', 'power'), 'shallow water should not change Power');
        },

        'Flying and Lava Walker expose their terrain traits' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0, '2,0' => 0]), [
                terrain_unit('flyer', 'seat-a', '0,0', ['Flying']),
                terrain_unit('walker', 'seat-a', '1,0', ['Lava Walker']),
                vanilla_unit('b1', 'seat-b', '2,0'),
            ]);
            $flying = AbilityRegistry::get('Flying');
            $walker = AbilityRegistry::get('Lava Walker');
            expect_true($flying !== null && $walker !== null, 'both printed terrain abilities should be playable');
            expect_true(Terrain::hasTrait($state, 'flyer', 'Flying'), 'the flyer should expose Flying');
            expect_true(Terrain::hasTrait($state, 'walker', 'Lava Walker'), 'the walker should expose Lava Walker');
            expect_true($flying->ignoresTerrain('water', 'voluntary'), 'Flying ignores open water');
            expect_true($flying->ignoresTerrain('ice', 'slip'), 'Flying ignores ice');
            expect_true($flying->ignoresTerrain('lava', 'voluntary'), 'Flying crosses lava voluntarily');
            expect_true(!$flying->ignoresTerrain('lava', 'push'), 'Flying does not ignore forced lava entry');
            expect_true(!$flying->ignoresTerrain('lava', 'teleport'), 'Flying does not ignore teleported lava entry');
            expect_true($walker->ignoresTerrain('lava', 'teleport'), 'Lava Walker ignores lava for every movement kind');
            expect_true(!$walker->ignoresTerrain('water', 'voluntary'), 'Lava Walker does not ignore open water');
        },

        'Lava Walker defense changes only for typed fire and water attacks' => static function (): void {
            $state = vanilla_battle(vanilla_board(['0,0' => 0, '1,0' => 0]), [
                terrain_unit('walker', 'seat-a', '0,0', ['Lava Walker'], ['defense' => 1]),
                vanilla_unit('b1', 'seat-b', '1,0'),
            ]);
            expect_same(3, Stats::effective($state, 'walker', 'defense', ['damageType' => 'fire']), 'fire grants two Defense');
            expect_same(0, Stats::effective($state, 'walker', 'defense', ['damageType' => 'water']), 'water removes two Defense and floors at zero');
            expect_same(1, Stats::effective($state, 'walker', 'defense'), 'untyped damage leaves Defense alone');
            expect_same(1, Stats::effective($state, 'b1', 'defense', ['damageType' => 'fire']), 'other units get no elemental modifier');
        },
    ];
}
