<?php
declare(strict_types=1);

use BloodAndBanners\Ai\BiomeMap;
use BloodAndBanners\Ai\HordePackages;
use BloodAndBanners\Ai\Personalities;
use BloodAndBanners\Rules\Vanilla\Catalog;

function ai_personality_tests(): array
{
    return [
        'four personalities ship in difficulty order' => static function (): void {
            $all = Personalities::all();
            expect_same(['ravager', 'warden', 'huntress', 'hollowKing'], array_keys($all), 'personality order');
            expect_same(['easy', 'medium', 'hard', 'hardest'], array_values(array_map(static fn ($p) => $p->difficulty, $all)), 'difficulties');
            foreach ($all as $p) {
                expect_true($p->name !== '' && $p->pitch !== '', 'name and pitch set for ' . $p->id);
            }
            expect_same('warden', Personalities::get('warden')?->id, 'get by id');
            expect_same(null, Personalities::get('nobody'), 'unknown id');
            expect_same(4, count(Personalities::catalogue()), 'catalogue size');
        },
        'personalities differ in what they value' => static function (): void {
            $all = array_values(Personalities::all());
            for ($i = 0; $i < 4; $i++) {
                for ($j = $i + 1; $j < 4; $j++) {
                    expect_true($all[$i]->weights !== $all[$j]->weights, 'weights differ');
                }
            }
            $tags = array_map(static fn ($p) => $p->horde['tag'], $all);
            expect_same(4, count(array_unique($tags)), 'distinct horde tags');
        },
        'the hex biome map follows the approved table' => static function (): void {
            expect_same(['Ice'], BiomeMap::monsterBiomes('ice', 'snow'), 'ice');
            expect_same(['River'], BiomeMap::monsterBiomes('water', 'open-water'), 'water');
            expect_same(['Forest'], BiomeMap::monsterBiomes('jungle', 'jungle'), 'jungle');
            expect_same(['Swamp'], BiomeMap::monsterBiomes('jungle', 'swamp'), 'swamp');
            expect_same(['Void'], BiomeMap::monsterBiomes('darkness', 'darkness'), 'darkness');
            expect_same(['Swamp'], BiomeMap::monsterBiomes('darkness', 'poison-pits'), 'poison pits');
            expect_same(['Wasteland'], BiomeMap::monsterBiomes('sand', 'dunes'), 'sand');
            expect_same(['Hills', 'Mountain'], BiomeMap::monsterBiomes('stone', 'crags'), 'stone');
            expect_same(['Hills', 'Mountain'], BiomeMap::monsterBiomes('plains', 'grass'), 'plains');
            expect_same(['Lava'], BiomeMap::monsterBiomes('lava', 'basalt'), 'lava');
            expect_same([], BiomeMap::monsterBiomes('nowhere', 'x'), 'unknown');
        },
        'every package names real creatures of its biome' => static function (): void {
            $catalog = new Catalog();
            $biomes = [];
            $tagCounts = [];
            foreach (HordePackages::all() as $package) {
                expect_true($package['creatures'] !== [], $package['name'] . ' has creatures');
                foreach ($package['creatures'] as $name) {
                    $monster = $catalog->monster($name);
                    expect_same($package['biome'], $monster['biome'], $name . ' biome in ' . $package['name']);
                }
                $biomes[$package['biome']] = true;
                foreach ($package['tags'] as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
            expect_same(9, count($biomes), 'all nine biomes covered');
            foreach (['swarm', 'fortress', 'ambush', 'combo'] as $tag) {
                expect_true(($tagCounts[$tag] ?? 0) >= 2, 'tag ' . $tag . ' on 2+ packages');
            }
        },
    ];
}
