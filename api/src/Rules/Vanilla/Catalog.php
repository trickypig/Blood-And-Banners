<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use RuntimeException;

final class Catalog
{
    private array $heroes = [];
    private array $armies = [];
    private array $monsters = [];

    public function __construct(?string $path = null)
    {
        if ($path === null) {
            $apiRoot = dirname(__DIR__, 3);
            $packagedPath = $apiRoot . '/data/gameData.json';
            $path = is_file($packagedPath)
                ? $packagedPath
                : dirname($apiRoot) . '/data/gameData.json';
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read the game catalog at {$path}.");
        }
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['heroes'] as $hero) {
            $this->heroes[(string)$hero['name']] = self::stats($hero);
        }
        foreach ($data['armies'] as $army) {
            $this->armies[(string)$army['name']] = [
                'name' => (string)$army['name'],
                'color' => (string)($army['color'] ?? ''),
                'emblem' => isset($army['emblem']) ? (string)$army['emblem'] : null,
                'units' => array_map(self::stats(...), $army['units']),
            ];
        }
        foreach ($data['monsters'] ?? [] as $biome) {
            foreach ($biome['creatures'] ?? [] as $creature) {
                if (!empty($creature['hidden'])) {
                    continue;
                }
                $this->monsters[(string)$creature['name']] = self::stats($creature) + [
                    'biome' => (string)($biome['biome'] ?? ''),
                    'tier' => (string)($creature['tier'] ?? 'common'),
                ];
                // Myconid prints its death reaction as Attack; it is always a passive reaction in play.
                foreach ($this->monsters[(string)$creature['name']]['abilities'] as &$ability) {
                    if ($ability['name'] === 'Spore Release') {
                        $ability['type'] = 'Essence';
                    }
                }
                unset($ability);
            }
        }
    }

    public function monsters(): array
    {
        return array_values($this->monsters);
    }

    public function monster(string $name): ?array
    {
        return $this->monsters[$name] ?? null;
    }

    public function heroes(): array
    {
        return array_values($this->heroes);
    }

    public function armies(): array
    {
        return array_values($this->armies);
    }

    public function hero(string $name): ?array
    {
        return $this->heroes[$name] ?? null;
    }

    public function army(string $name): ?array
    {
        return $this->armies[$name] ?? null;
    }

    private static function stats(array $card): array
    {
        return [
            'name' => (string)$card['name'],
            'image' => (string)($card['image'] ?? ''),
            'avatarImage' => isset($card['avatarImage']) ? (string)$card['avatarImage'] : null,
            'move' => (int)$card['move'],
            'range' => (int)$card['range'],
            'power' => (int)$card['power'],
            'defense' => (int)$card['defense'],
            'hp' => (int)$card['hp'],
            'abilities' => array_map(static fn(array $ability): array => [
                'name' => (string)$ability['name'],
                'type' => (string)($ability['type'] ?? ''),
                'description' => (string)($ability['description'] ?? ''),
            ], $card['abilities'] ?? []),
        ];
    }
}
