<?php
declare(strict_types=1);

namespace BloodAndBanners\Rules\Vanilla;

final class Board
{
    /** Map objects that fill their hex: nothing may enter it (flying units included) or see through it. */
    public const OBSTACLES = ['tree', 'rock', 'shrine'];

    /** Whether a scenario hex blocks movement and sight, either by its flag or by holding an obstacle. */
    public static function blocks(array $hex): bool
    {
        return (bool)($hex['sightBlocker'] ?? false) || in_array($hex['object'] ?? null, self::OBSTACLES, true);
    }

    public static function fromScenario(array $definition): array
    {
        $board = [];
        foreach ($definition['map']['hexes'] as $hex) {
            $board[Hex::key((int)$hex['q'], (int)$hex['r'])] = [
                'elevation' => (int)$hex['elevation'],
                'sightBlocker' => self::blocks($hex),
                'playerStart' => isset($hex['playerStart']) ? (int)$hex['playerStart'] : null,
                'biome' => (string)($hex['biome'] ?? 'plains'),
                'terrain' => (string)($hex['terrain'] ?? 'grass'),
                'spawnZone' => isset($hex['spawnZone']) ? (string)$hex['spawnZone'] : null,
            ];
        }
        return $board;
    }

    public static function homes(array $board): array
    {
        $homes = [];
        foreach ($board as $key => $hex) {
            if ($hex['playerStart'] !== null) {
                $homes[$hex['playerStart']] = (string)$key;
            }
        }
        ksort($homes);
        return $homes;
    }
}
