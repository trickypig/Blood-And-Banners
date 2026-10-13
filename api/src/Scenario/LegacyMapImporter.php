<?php
declare(strict_types=1);

namespace BloodAndBanners\Scenario;

use InvalidArgumentException;

final class LegacyMapImporter
{
    private const TERRAIN_BIOMES = [
        'grass' => 'plains', 'roads' => 'plains', 'road' => 'plains',
        'stone' => 'stone', 'stone-path' => 'stone', 'crags' => 'stone',
        'water' => 'water', 'open-water' => 'water', 'shallow-water' => 'water',
        'sand' => 'sand', 'desert' => 'sand', 'dunes' => 'sand',
        'ice' => 'ice', 'snow' => 'ice',
        'lava' => 'lava', 'basalt' => 'lava',
        'swamp' => 'jungle', 'jungle' => 'jungle',
        'darkness' => 'darkness', 'poison-pits' => 'darkness',
    ];

    public function import(array $legacy): array
    {
        $columns = (int)($legacy['columns'] ?? 0);
        $rows = (int)($legacy['rows'] ?? 0);
        if ($columns < 1 || $rows < 1 || !isset($legacy['hexes']) || !is_array($legacy['hexes'])) {
            throw new InvalidArgumentException('invalidLegacyMap');
        }
        $hexes = array_map(fn(array $hex): array => $this->normalizeHex($hex), $legacy['hexes']);
        return [
            'schemaVersion' => 1,
            'name' => trim((string)($legacy['name'] ?? 'Imported map')) ?: 'Imported map',
            'modeCompatibility' => ['vanillaPvp', 'coop', 'battleRoyale'],
            'map' => ['columns' => $columns, 'rows' => $rows, 'hexes' => $hexes],
            'placements' => $legacy['placements'] ?? [],
            'objectives' => $legacy['objectives'] ?? [],
            'scoring' => [
                'target' => 10,
                'enemyHero' => 3,
                'boss' => 2,
                'terrainObject' => 1,
                'completeObjectSetBonus' => 2,
            ],
        ];
    }

    private function normalizeHex(array $hex): array
    {
        $terrain = strtolower((string)($hex['terrain'] ?? 'grass'));
        $biome = strtolower((string)($hex['biome'] ?? self::TERRAIN_BIOMES[$terrain] ?? 'plains'));
        $coordinates = $this->normalizeCoordinates($hex);
        return [
            'q' => $coordinates['q'],
            'r' => $coordinates['r'],
            'biome' => $biome,
            'terrain' => $terrain,
            'elevation' => (int)($hex['elevation'] ?? $hex['height'] ?? 0),
            'playerStart' => isset($hex['playerStart']) ? (int)$hex['playerStart'] : null,
            'portal' => (bool)($hex['portal'] ?? false),
            'spawnZone' => $hex['spawnZone'] ?? null,
            'piece' => $hex['piece'] ?? null,
            'object' => $hex['object'] ?? null,
            'fountain' => (bool)($hex['fountain'] ?? (($hex['object'] ?? null) === 'fountain')),
            'sightBlocker' => (bool)($hex['sightBlocker'] ?? false),
        ];
    }

    /** @return array{q:int, r:int} */
    private function normalizeCoordinates(array $hex): array
    {
        $hasQ = array_key_exists('q', $hex);
        $hasR = array_key_exists('r', $hex);
        if ($hasQ || $hasR) {
            if (!$hasQ || !$hasR) {
                throw new InvalidArgumentException('invalidLegacyMap');
            }
            return ['q' => (int)$hex['q'], 'r' => (int)$hex['r']];
        }

        $hasColumn = array_key_exists('col', $hex);
        $hasRow = array_key_exists('row', $hex);
        if (!$hasColumn || !$hasRow) {
            throw new InvalidArgumentException('invalidLegacyMap');
        }
        return AxialCoordinates::fromOddColumnOffset((int)$hex['col'], (int)$hex['row']);
    }
}
