<?php
declare(strict_types=1);

namespace BloodAndBanners\Ai;

/** Maps a map hex (biome + terrain) to the monster biomes that fit it, in preference order. */
final class BiomeMap
{
    /** @return list<string> */
    public static function monsterBiomes(string $hexBiome, string $terrain): array
    {
        return match ($hexBiome) {
            'ice' => ['Ice'],
            'water' => ['River'],
            'jungle' => $terrain === 'swamp' ? ['Swamp'] : ['Forest'],
            'darkness' => $terrain === 'poison-pits' ? ['Swamp'] : ['Void'],
            'sand' => ['Wasteland'],
            'stone', 'plains' => ['Hills', 'Mountain'],
            'lava' => ['Lava'],
            default => [],
        };
    }
}
