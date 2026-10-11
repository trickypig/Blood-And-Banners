<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

use RuntimeException;

final class Catalog
{
    private array $heroes = [];
    private array $armies = [];

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 4) . '/data/gameData.json';
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
                'units' => array_map(self::stats(...), $army['units']),
            ];
        }
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
            'move' => (int)$card['move'],
            'range' => (int)$card['range'],
            'power' => (int)$card['power'],
            'defense' => (int)$card['defense'],
            'hp' => (int)$card['hp'],
        ];
    }
}
